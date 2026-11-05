<?php

namespace Tests\Feature;

use App\Enums\SensitiveDataCategory;
use App\Support\Privacy\SensitiveDataDetection;
use App\Support\Privacy\SensitiveDataDetector;
use Tests\TestCase;

/**
 * Regras de detecção de dado pessoal no chat.
 *
 * A detecção é a base de tudo: se ela errar para menos, o contato externo
 * passa; se errar para mais, o cliente não consegue falar de dinheiro, datas
 * ou horários. Os casos abaixo cobrem os dois lados.
 */
class ChatPrivacyDetectionTest extends TestCase
{
    private function detect(string $body): SensitiveDataDetection
    {
        return app(SensitiveDataDetector::class)->detect($body);
    }

    /**
     * @return list<string>
     */
    private function categories(string $body): array
    {
        return $this->detect($body)->values();
    }

    public function test_valid_and_formatted_cpf_is_blocked(): void
    {
        $this->assertSame(
            [SensitiveDataCategory::Cpf->value],
            $this->categories('meu cpf é 529.982.247-25, confere lá'),
        );

        $this->assertSame(
            [SensitiveDataCategory::Cpf->value],
            $this->categories('cpf 52998224725'),
        );

        // Máscara completa é bloqueada mesmo quando os dígitos verificadores
        // não fecham: o formato já é um dado pessoal.
        $this->assertSame(
            [SensitiveDataCategory::Cpf->value],
            $this->categories('meu documento 123.456.789-00'),
        );
    }

    public function test_cnpj_is_blocked_with_and_without_mask(): void
    {
        $this->assertSame(
            [SensitiveDataCategory::Cnpj->value],
            $this->categories('a empresa é 11.222.333/0001-81'),
        );

        $this->assertSame(
            [SensitiveDataCategory::Cnpj->value],
            $this->categories('cnpj 11222333000181'),
        );
    }

    public function test_email_is_blocked_including_disguised_writing(): void
    {
        $this->assertSame(
            [SensitiveDataCategory::Email->value],
            $this->categories('me escreve em maria.silva@gmail.com'),
        );

        $this->assertSame(
            [SensitiveDataCategory::Email->value],
            $this->categories('meu email é maria [at] gmail [dot] com'),
        );

        $this->assertSame(
            [SensitiveDataCategory::Email->value],
            $this->categories('maria arroba gmail ponto com'),
        );
    }

    public function test_phone_and_whatsapp_are_blocked(): void
    {
        $this->assertSame(
            [SensitiveDataCategory::Phone->value],
            $this->categories('meu fixo é 11 3456-7890'),
        );

        $this->assertSame(
            [SensitiveDataCategory::WhatsApp->value],
            $this->categories('me chama no whats 11 98765-4321'),
        );

        $this->assertSame(
            [SensitiveDataCategory::WhatsApp->value],
            $this->categories('meu celular +55 (11) 9 8765-4321'),
        );
    }

    public function test_external_links_are_blocked(): void
    {
        $this->assertSame(
            [SensitiveDataCategory::ExternalLink->value],
            $this->categories('olha meu site https://minha-loja.com.br/promo'),
        );

        $this->assertSame(
            [SensitiveDataCategory::ExternalLink->value],
            $this->categories('acessa www.meusite.com'),
        );

        // Convites de WhatsApp/Telegram caem na mesma regra, com ou sem
        // classificação de número: o importante é bloquear.
        $detection = $this->detect('chama no wa.me/5511999999999');

        $this->assertTrue($detection->hasMatches());
        $this->assertContains(
            $detection->primaryCategory(),
            [SensitiveDataCategory::WhatsApp, SensitiveDataCategory::ExternalLink],
        );
    }

    public function test_spelled_digits_are_blocked_as_disguised_contact(): void
    {
        $this->assertSame(
            [SensitiveDataCategory::HiddenContact->value],
            $this->categories('meu numero um um nove nove nove oito sete seis cinco quatro três'),
        );
    }

    public function test_money_time_dates_and_short_codes_are_not_blocked(): void
    {
        $this->assertFalse($this->detect('a consulta custa R$ 50,00 e dura 30 minutos por 2 horas')->hasMatches());
        $this->assertFalse($this->detect('prefiro amanhã às 14:30, pode ser?')->hasMatches());
        $this->assertFalse($this->detect('nasci em 12/03/1990, faço 35 anos')->hasMatches());
        $this->assertFalse($this->detect('o código 1234 e a senha 0000 não valem nada')->hasMatches());
        $this->assertFalse($this->detect('tirei as cartas 1, 2 e 3 do baralho')->hasMatches());
        $this->assertFalse($this->detect('vou pensar com calma e te retorno')->hasMatches());
    }

    public function test_masked_text_hides_the_sensitive_values(): void
    {
        $detection = $this->detect('meu whats é (11) 98765-4321 e meu cpf 529.982.247-25');

        // O trecho mascarado substitui somente o valor, sem duplicar a
        // pontuação que já existia na frase.
        $this->assertSame(
            'meu whats é (11) *****-4321 e meu cpf 529.***.***-25',
            $detection->maskedText,
        );

        $this->assertStringNotContainsString('98765-4321', $detection->maskedText);
        $this->assertStringNotContainsString('529.982.247-25', $detection->maskedText);
        $this->assertStringNotContainsString('98765-4321', $detection->maskedExcerpt);
    }
}
