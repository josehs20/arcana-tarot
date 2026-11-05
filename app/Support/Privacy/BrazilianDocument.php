<?php

namespace App\Support\Privacy;

/**
 * Validação de documentos brasileiros usada na detecção de dados pessoais.
 *
 * A checagem de dígitos verificadores evita que números longos de outra
 * natureza (protocolos, pedidos, códigos) sejam tratados como CPF/CNPJ.
 */
final class BrazilianDocument
{
    public static function isValidCpf(string $digits): bool
    {
        $digits = self::onlyDigits($digits);

        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits) === 1) {
            return false;
        }

        for ($position = 9; $position < 11; $position++) {
            $sum = 0;

            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $digits[$index] * (($position + 1) - $index);
            }

            $checkDigit = ((10 * $sum) % 11) % 10;

            if ((int) $digits[$position] !== $checkDigit) {
                return false;
            }
        }

        return true;
    }

    public static function isValidCnpj(string $digits): bool
    {
        $digits = self::onlyDigits($digits);

        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits) === 1) {
            return false;
        }

        $weights = [
            [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
            [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
        ];

        foreach ($weights as $index => $weightList) {
            $sum = 0;

            foreach ($weightList as $position => $weight) {
                $sum += (int) $digits[$position] * $weight;
            }

            $remainder = $sum % 11;
            $checkDigit = $remainder < 2 ? 0 : 11 - $remainder;

            if ((int) $digits[12 + $index] !== $checkDigit) {
                return false;
            }
        }

        return true;
    }

    public static function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
