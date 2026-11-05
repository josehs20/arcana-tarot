<?php

namespace App\Support\Privacy;

use App\Enums\SensitiveDataCategory;
use App\Support\PhoneNumber;

/**
 * Detecta dados pessoais e contatos externos no texto do chat.
 *
 * A detecção roda no servidor, antes da persistência da mensagem, para que
 * requisições diretas à API não consigam burlar o aviso exibido no navegador.
 *
 * Regras cobertas:
 * - CPF (dígitos verificadores válidos ou máscara 000.000.000-00)
 * - CNPJ (14 dígitos, com ou sem máscara)
 * - E-mail, inclusive disfarces como "nome [at] gmail [dot] com" ou "nome arroba gmail ponto com"
 * - Telefone/WhatsApp brasileiro (fixo, celular, com DDI, com espaços e pontuação)
 * - Links externos (http, https, www e domínios conhecidos como wa.me)
 * - Contato disfarçado: números escritos por extenso em sequência
 *
 * O que NÃO é marcado: valores monetários, horários, datas, quantidades e
 * códigos curtos. Grupos numéricos precisam ter 10, 11 ou 14 dígitos e, quando
 * escritos apenas com espaços, no máximo quatro blocos.
 */
final class SensitiveDataDetector
{
    /**
     * @var array<string, string>
     */
    private const SpelledDigits = [
        'zero' => '0',
        'um' => '1',
        'dois' => '2',
        'três' => '3',
        'tres' => '3',
        'quatro' => '4',
        'cinco' => '5',
        'seis' => '6',
        'sete' => '7',
        'oito' => '8',
        'nove' => '9',
    ];

    private const SpelledWordPattern = 'zero|um|dois|três|tres|quatro|cinco|seis|sete|oito|nove';

    /**
     * Termos que indicam intenção de levar a conversa para fora da plataforma.
     */
    private const ContactContextPattern = '/whats\s*(?:app)?|zap|telegram|instagram|insta\b|facebook|messenger|tiktok|twitter|e-?mail|correio|gmail|hotmail|outlook|yahoo|me\s+chama|chama\s+(?:eu|no|na|l[aá])|me\s+liga|liga\s+para|meus?\s+contatos?|meu\s+n[uú]mero|telefone|celular|fone|contato|link|site|perfil|p[aá]gina/iu';

    private const KnownDomainPattern = '/\b(?:wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com|t\.me|telegram\.me|instagram\.com|instagr\.am|facebook\.com|fb\.me|messenger\.com|tiktok\.com|twitter\.com|x\.com|linkedin\.com|youtube\.com|youtu\.be|bit\.ly|linktr\.ee|discord\.gg|onlyfans\.com)\b/iu';

    private const LinkPattern = '/\b(?:https?:\/\/|www\.)[^\s<>"\'`]+|\b[a-z0-9](?:[a-z0-9._%+\-]*[a-z0-9])?\.(?:com|com\.br|net|net\.br|org|org\.br|edu|edu\.br|gov|gov\.br|info|app|dev|me|io|co|tv|xyz|site|online|store|shop|link|click|pro|biz|cloud|live|gg)(?:\.[a-z]{2})?(?:\/[^\s<>"\'`]*)?|\bwa\.me\/\d+/iu';

    private const NumberGroupPattern = '/\(?\+?\d(?:[\d\s().\-\/]{7,})\d/u';

    private const EmailPattern = '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu';

    private const SpelledRunPattern = '/\b(?:(?:zero|um|dois|três|tres|quatro|cinco|seis|sete|oito|nove)\b[\s,.;:\-]*){8,}/iu';

    public function detect(string|int|null $body): SensitiveDataDetection
    {
        $text = is_string($body) ? $body : (string) $body;
        $scan = $this->normalize($text);

        if ($scan === '' || ! $this->hasDigitsOrContactHint($scan)) {
            return SensitiveDataDetection::empty($scan);
        }

        $scan = $this->spellDigits($scan);

        $matches = [
            ...$this->detectEmails($scan),
            ...$this->detectLinks($scan),
            ...$this->detectDocumentsAndPhones($scan),
            ...$this->detectDisguisedDigits($scan),
        ];

        return new SensitiveDataDetection($scan, $this->sortAndDeduplicate($matches));
    }

    private function hasDigitsOrContactHint(string $text): bool
    {
        return preg_match('/\d/u', $text) === 1
            || str_contains($text, '@')
            || preg_match(self::ContactContextPattern, $text) === 1;
    }

    /**
     * Normaliza disfarces comuns antes da varredura:
     * "nome [at] gmail [dot] com", "nome arroba gmail ponto com",
     * espaços em volta do "@" e de pontos entre rótulos de domínio.
     */
    private function normalize(string $body): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($body)) ?? trim($body);

        $text = preg_replace('/\s*(?:\[\s*at\s*\]|\(\s*at\s*\)|\{\s*at\s*\}|\barroba\b)\s*/iu', '@', $text) ?? $text;
        $text = preg_replace('/(?<=[\p{L}\d])\s*(?:\[\s*dot\s*\]|\(\s*dot\s*\)|\{\s*dot\s*\}|\bponto\b)\s*(?=[\p{L}\d])/iu', '.', $text) ?? $text;
        $text = preg_replace('/\s*@\s*/u', '@', $text) ?? $text;
        $text = preg_replace('/(?<=[\p{L}\d])\s*\.\s*(?=[\p{L}\d])/u', '.', $text) ?? $text;

        return trim($text);
    }

    /**
     * Substitui dígitos escritos por extenso intercalados com números reais
     * ("11 nove 8765 4321"). A substituição preserva o tamanho em bytes para
     * manter os deslocamentos do mascaramento no lugar.
     */
    private function spellDigits(string $text): string
    {
        if (preg_match('/\d/u', $text) !== 1) {
            return $text;
        }

        $pattern = '/(?<=\d[\s().\-\/+]{0,2})(?:'.self::SpelledWordPattern.')(?=[\s().\-\/+]{0,2}\d)/iu';

        for ($round = 0; $round < 4; $round++) {
            $updated = preg_replace_callback(
                $pattern,
                fn (array $found): string => str_pad(self::SpelledDigits[mb_strtolower($found[0])] ?? '', strlen($found[0]), ' '),
                $text,
            );

            if (! is_string($updated) || $updated === $text) {
                break;
            }

            $text = $updated;
        }

        return $text;
    }

    /**
     * @return list<SensitiveDataMatch>
     */
    private function detectEmails(string $text): array
    {
        preg_match_all(self::EmailPattern, $text, $found, PREG_OFFSET_CAPTURE);

        $matches = [];

        foreach ($found[0] as [$raw, $offset]) {
            $matches[] = new SensitiveDataMatch(SensitiveDataCategory::Email, (string) $raw, (int) $offset);
        }

        return $matches;
    }

    /**
     * @return list<SensitiveDataMatch>
     */
    private function detectLinks(string $text): array
    {
        preg_match_all(self::LinkPattern, $text, $found, PREG_OFFSET_CAPTURE);

        $matches = [];

        foreach ($found[0] as [$raw, $offset]) {
            $raw = rtrim((string) $raw, '.,;:!?)');

            if ($raw === '') {
                continue;
            }

            $category = preg_match(self::KnownDomainPattern, $raw) === 1
                ? SensitiveDataCategory::WhatsApp
                : SensitiveDataCategory::ExternalLink;

            $matches[] = new SensitiveDataMatch($category, $raw, (int) $offset);
        }

        return $matches;
    }

    /**
     * @return list<SensitiveDataMatch>
     */
    private function detectDocumentsAndPhones(string $text): array
    {
        preg_match_all(self::NumberGroupPattern, $text, $found, PREG_OFFSET_CAPTURE);

        $matches = [];

        foreach ($found[0] as [$raw, $offset]) {
            $raw = trim((string) $raw);
            $digits = BrazilianDocument::onlyDigits($raw);
            $length = strlen($digits);

            if ($length < 10 || $length > 14) {
                continue;
            }

            if ($length === 11 && $this->looksLikeNumberList($raw)) {
                continue;
            }

            $category = $this->classifyNumber($text, $raw, $digits, (int) $offset);

            if ($category === null) {
                continue;
            }

            $matches[] = new SensitiveDataMatch($category, $raw, (int) $offset);
        }

        return $matches;
    }

    /**
     * Listas de números curtos separados por espaço ("10 20 30 40 50") não
     * são contatos. Contatos espaçados costumam ter ao menos um bloco maior.
     */
    private function looksLikeNumberList(string $raw): bool
    {
        if (preg_match('/[().\-\/+]/', $raw) === 1) {
            return false;
        }

        $tokens = preg_split('/\s+/u', trim($raw)) ?: [];

        if (count($tokens) <= 4) {
            return false;
        }

        return preg_match('/\d{3,}/', $raw) !== 1;
    }

    private function classifyNumber(string $text, string $raw, string $digits, int $offset): ?SensitiveDataCategory
    {
        if (strlen($digits) === 14) {
            return SensitiveDataCategory::Cnpj;
        }

        if (strlen($digits) === 11) {
            $isCpfFormatted = preg_match('/\d{3}\s*\.\s*\d{3}\s*\.\s*\d{3}\s*-\s*\d{2}/', $raw) === 1;

            if ($isCpfFormatted || BrazilianDocument::isValidCpf($digits)) {
                return SensitiveDataCategory::Cpf;
            }
        }

        // O número pode vir com DDI (+55). A validação usa o formato
        // persistido (DDD + número), igual ao resto da aplicação.
        $phoneDigits = PhoneNumber::normalize($digits);

        if (! PhoneNumber::isValid($phoneDigits)) {
            return null;
        }

        return $this->hasContactContext($text, $offset, strlen($raw))
            ? SensitiveDataCategory::WhatsApp
            : SensitiveDataCategory::Phone;
    }

    private function hasContactContext(string $text, int $offset, int $length): bool
    {
        $window = substr($text, max(0, $offset - 60), $length + 120);

        return preg_match(self::ContactContextPattern, $window) === 1;
    }

    /**
     * @return list<SensitiveDataMatch>
     */
    private function detectDisguisedDigits(string $text): array
    {
        preg_match_all(self::SpelledRunPattern, $text, $found, PREG_OFFSET_CAPTURE);

        $matches = [];

        foreach ($found[0] as [$raw, $offset]) {
            preg_match_all('/'.self::SpelledWordPattern.'/iu', (string) $raw, $words);

            $digits = '';

            foreach ($words[0] as $word) {
                $digits .= self::SpelledDigits[mb_strtolower((string) $word)] ?? '';
            }

            if (! in_array(strlen($digits), [10, 11, 14], true)) {
                continue;
            }

            $isRelevant = PhoneNumber::isValid($digits)
                || BrazilianDocument::isValidCpf($digits)
                || BrazilianDocument::isValidCnpj($digits);

            if (! $isRelevant) {
                continue;
            }

            $matches[] = new SensitiveDataMatch(SensitiveDataCategory::HiddenContact, trim((string) $raw), (int) $offset);
        }

        return $matches;
    }

    /**
     * @param  list<SensitiveDataMatch>  $matches
     * @return list<SensitiveDataMatch>
     */
    private function sortAndDeduplicate(array $matches): array
    {
        usort($matches, function (SensitiveDataMatch $left, SensitiveDataMatch $right): int {
            if ($left->offset === $right->offset) {
                return $right->length() <=> $left->length();
            }

            return $left->offset <=> $right->offset;
        });

        $result = [];
        $coveredUntil = -1;

        foreach ($matches as $match) {
            if ($match->offset < $coveredUntil) {
                continue;
            }

            $result[] = $match;
            $coveredUntil = $match->offset + $match->length();
        }

        return $result;
    }
}
