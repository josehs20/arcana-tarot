<?php

namespace App\Providers;

use App\Enums\ApplicationStatus;
use App\Models\AttendantApplication;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\ConsultationTestimonial;
use App\Models\Payment;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use App\Models\Wallet;
use App\Policies\AttendantApplicationPolicy;
use App\Policies\AttendantProfilePolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\CommissionLedgerEntryPolicy;
use App\Policies\ConsultationPolicy;
use App\Policies\ConsultationTestimonialPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\TarotCatalogPolicy;
use App\Policies\UserPolicy;
use App\Policies\WalletPolicy;
use App\Support\Payments\Contracts\PixProvider;
use App\Support\Payments\InfinitePay\InfinitePayGateway;
use App\Support\Payments\PixSandboxProvider;
use App\Support\Payments\UnavailablePixProvider;
use App\Support\SiteContent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // O conteúdo do site é memoizado dentro da instância; um singleton por
        // request evita repetir consultas entre layout, navbar e rodapé.
        $this->app->singleton(SiteContent::class);

        $this->app->singleton(PixProvider::class, function (): PixProvider {
            return match ((string) config('payments.provider')) {
                'pix_sandbox' => new PixSandboxProvider,
                // O checkout InfinitePay não usa o contrato PIX interno; o
                // adaptador neutro mantém o binding resolvível e desligado.
                InfinitePayGateway::NAME => new UnavailablePixProvider,
                default => throw new InvalidArgumentException(
                    'Provedor PIX não suportado. Implemente o contrato PixProvider antes de alterar PAYMENT_PROVIDER.',
                ),
            };
        });

        $this->app->singleton(InfinitePayGateway::class, fn (): InfinitePayGateway => new InfinitePayGateway);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        Gate::policy(AttendantApplication::class, AttendantApplicationPolicy::class);
        Gate::policy(AttendantProfile::class, AttendantProfilePolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(CommissionLedgerEntry::class, CommissionLedgerEntryPolicy::class);
        Gate::policy(Consultation::class, ConsultationPolicy::class);
        Gate::policy(ConsultationTestimonial::class, ConsultationTestimonialPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(TarotCard::class, TarotCatalogPolicy::class);
        Gate::policy(TarotDeck::class, TarotCatalogPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Wallet::class, WalletPolicy::class);

        Gate::define('access-admin-panel', fn (User $user): bool => $user->is_active && $user->isAdmin());
        Gate::define('access-attendant-panel', fn (User $user): bool => $user->is_active && $user->isAttendant());
        Gate::define('access-client-panel', fn (User $user): bool => $user->is_active && $user->isClient());

        RateLimiter::for('consultation-messages', function (Request $request): Limit {
            return Limit::perMinute((int) config('consultation.message_rate_per_minute'))
                ->by($this->consultationRateKey($request));
        });

        RateLimiter::for('consultation-cards', function (Request $request): Limit {
            return Limit::perMinute((int) config('consultation.card_rate_per_minute'))
                ->by($this->consultationRateKey($request));
        });

        RateLimiter::for('consultation-calls', function (Request $request): Limit {
            return Limit::perMinute(120)
                ->by($this->consultationRateKey($request));
        });

        RateLimiter::for('consultation-requests', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by('consultation-request:'.($request->user()?->getKey() ?? $request->ip()));
        });

        // Depoimentos: o cliente escreve um por consulta concluída, mas o
        // limite evita automação e envios repetidos em sequência.
        RateLimiter::for('consultation-testimonials', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by('consultation-testimonial:'.($request->user()?->getKey() ?? $request->ip()));
        });

        RateLimiter::for('consultation-presence', function (Request $request): Limit {
            return Limit::perMinute(60)
                ->by($this->consultationRateKey($request));
        });

        // Leituras do modo polling: o cursor de mensagens roda a cada 2 s por
        // participante e o feed do painel a cada 3 s por usuário.
        RateLimiter::for('consultation-feed', function (Request $request): Limit {
            return Limit::perMinute((int) config('consultation.realtime.message_feed_rate_per_minute'))
                ->by($this->consultationRateKey($request));
        });

        RateLimiter::for('panel-events', function (Request $request): Limit {
            return Limit::perMinute((int) config('consultation.realtime.panel_feed_rate_per_minute'))
                ->by('panel-events:'.($request->user()?->getKey() ?? $request->ip()));
        });

        // Atualização automática do histórico administrativo: mesmo intervalo do
        // feed do painel, isolado por administrador para não consumir a cota do
        // painel do cliente ou do atendente.
        RateLimiter::for('admin-consultation-history', function (Request $request): Limit {
            return Limit::perMinute((int) config('consultation.realtime.panel_feed_rate_per_minute'))
                ->by('admin-consultation-history:'.($request->user()?->getKey() ?? $request->ip()));
        });

        RateLimiter::for('credit-purchases', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by('credit-purchase:'.($request->user()?->getKey() ?? $request->ip()));
        });

        // Disparo de e-mails: o limite protege o SMTP de um duplo clique ou de
        // um formulário reenviado, já que cada campanha enfileira centenas de
        // mensagens.
        RateLimiter::for('admin-bulk-email', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by('admin-bulk-email:'.($request->user()?->getKey() ?? $request->ip()));
        });

        View::composer('layouts.partials.sidebar-admin', function ($view): void {
            $user = auth()->user();
            $count = $user && $user->is_active && $user->isAdmin()
                ? AttendantApplication::query()
                    ->where('status', ApplicationStatus::Pending->value)
                    ->count()
                : 0;

            $view->with('pendingApplicationsCount', $count);
        });

        /*
         * `composer run dev` (php artisan dev) já sobe servidor, fila, logs,
         * Vite e Reverb. Sem o agendador junto, cobrança por minuto, expiração
         * de solicitações e interrupção por inatividade não rodam fora do
         * Docker — a consulta inativa ficava aberta.
         */
        if ($this->app->runningInConsole() && class_exists(DevCommands::class)) {
            DevCommands::artisan('schedule:work', 'scheduler');
        }
    }

    /**
     * Limite por participante e consulta, evitando que uma conversa consuma a
     * cota de outra ou que o limite global bloqueie usuários distintos.
     */
    private function consultationRateKey(Request $request): string
    {
        $consultation = $request->route('consultation');
        $consultationId = $consultation instanceof Consultation
            ? $consultation->getKey()
            : (is_scalar($consultation) && (string) $consultation !== '' ? (string) $consultation : 'unknown');

        return 'consultation:'.$consultationId.':user:'.($request->user()?->getKey() ?? $request->ip());
    }
}
