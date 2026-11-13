<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Assunto e corpo padrão do disparo de e-mails (/admin/emails).
 *
 * O texto fica em `settings` para a administração reutilizar o mesmo conteúdo
 * entre disparos. Cada campanha guarda uma cópia do corpo no momento do envio,
 * então salvar um novo padrão não altera o histórico.
 *
 * Marcadores disponíveis no corpo (substituídos no envio, nunca renderizados
 * como HTML): `{{nome}}` (primeiro nome), `{{email}}` e `{{link}}` (home).
 */
final class BulkEmailTemplate
{
    public const SUBJECT_SETTING_KEY = 'bulk_email_default_subject';

    public const BODY_SETTING_KEY = 'bulk_email_default_body';

    /** @var array<string, string|null>|null */
    private ?array $settings = null;

    public function subject(): string
    {
        $stored = $this->stored(self::SUBJECT_SETTING_KEY);

        return $stored !== null && trim($stored) !== ''
            ? $stored
            : 'Novidades do '.SiteContent::currentName();
    }

    public function body(): string
    {
        $stored = $this->stored(self::BODY_SETTING_KEY);

        return $stored !== null && trim($stored) !== '' ? $stored : $this->defaultBody();
    }

    /**
     * Texto inicial sugerido, montado com o nome atual do site.
     *
     * O nome vem do banco (e não do `config/site.php`) para que o modelo padrão
     * não continue exibindo a identidade antiga depois de renomear o site em
     * /admin/conteudo — o valor só é salvo em `settings` quando a administração
     * edita o modelo.
     */
    private function defaultBody(): string
    {
        $siteName = SiteContent::currentName();

        return <<<TEXT
        Olá, {{nome}}!

        Passando para contar as novidades do {$siteName}: novos atendentes, baralhos atualizados e melhorias no atendimento por chat.

        Conheça as cartas e os atendentes disponíveis agora em {{link}}.

        Com carinho,
        Equipe {$siteName}
        TEXT;
    }

    /**
     * Substitui os marcadores do corpo pelos dados do destinatário.
     */
    public static function render(string $body, ?string $name = null, ?string $email = null): string
    {
        return str_replace(
            ['{{nome}}', '{{email}}', '{{link}}'],
            [self::firstName($name, $email), (string) $email, route('home')],
            $body,
        );
    }

    /**
     * Salva o modelo padrão com auditoria (assunto e tamanho do corpo).
     */
    public function save(string $subject, string $body, ?User $actor = null): void
    {
        $subject = trim($subject);
        $body = trim($body);

        $before = [
            'subject' => $this->subject(),
            'body_length' => Str::length($this->body()),
        ];

        DB::transaction(function () use ($subject, $body, $before, $actor): void {
            Setting::query()->updateOrCreate(
                ['key' => self::SUBJECT_SETTING_KEY],
                ['value' => $subject, 'type' => 'string'],
            );

            Setting::query()->updateOrCreate(
                ['key' => self::BODY_SETTING_KEY],
                ['value' => $body, 'type' => 'text'],
            );

            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(),
                'action' => 'email_template.updated',
                'metadata' => [
                    'before' => $before,
                    'after' => [
                        'subject' => $subject,
                        'body_length' => Str::length($body),
                    ],
                ],
            ]);
        });

        $this->settings = null;
    }

    private static function firstName(?string $name, ?string $email): string
    {
        $trimmed = trim((string) $name);

        if ($trimmed !== '') {
            return Str::before($trimmed, ' ');
        }

        if ($email !== null && str_contains($email, '@')) {
            return Str::headline(Str::before($email, '@'));
        }

        return 'cliente';
    }

    private function stored(string $key): ?string
    {
        if ($this->settings === null) {
            $this->settings = Setting::query()
                ->whereIn('key', [self::SUBJECT_SETTING_KEY, self::BODY_SETTING_KEY])
                ->pluck('value', 'key')
                ->all();
        }

        $value = $this->settings[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
