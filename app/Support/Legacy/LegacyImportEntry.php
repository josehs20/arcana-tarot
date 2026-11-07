<?php

namespace App\Support\Legacy;

use App\Enums\Gender;
use App\Enums\LegacyImportStatus;
use App\Enums\LegacyPasswordFormat;

/**
 * Resultado da análise (e, quando executada, da gravação) de uma linha do dump.
 */
final class LegacyImportEntry
{
    public function __construct(
        public readonly string $legacyKey,
        public readonly LegacyImportStatus $status,
        public readonly LegacyPasswordFormat $passwordFormat = LegacyPasswordFormat::Missing,
        public readonly ?string $email = null,
        public readonly ?string $name = null,
        public readonly int $balanceCents = 0,
        public readonly ?string $reason = null,
        public readonly ?int $userId = null,
        public readonly ?string $birthDate = null,
        public readonly ?Gender $gender = null,
        public readonly bool $profileMatched = false,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }
}
