<?php

declare(strict_types=1);

namespace OCA\Assinafy\Domain;

final class TaxId
{
    public static function normalize(string $value): string
    {
        return preg_replace('/[^0-9A-Z]/', '', strtoupper($value)) ?? '';
    }

    public static function isValid(string $value): bool
    {
        return self::isCpf($value) || self::isCnpj($value);
    }

    public static function isCpf(string $value): bool
    {
        $normalized = self::normalize($value);
        return strlen($normalized) === 11
            && ctype_digit($normalized)
            && preg_match('/^(\d)\1+$/', $normalized) !== 1
            && self::cpf($normalized);
    }

    public static function isCnpj(string $value): bool
    {
        $normalized = self::normalize($value);
        return preg_match('/^[0-9A-Z]{12}\d{2}$/', $normalized) === 1
            && preg_match('/^(.)\1+$/', $normalized) !== 1
            && self::cnpj($normalized);
    }

    private static function cpf(string $digits): bool
    {
        for ($position = 9; $position < 11; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $digits[$index] * (($position + 1) - $index);
            }
            $check = (10 * $sum) % 11;
            if ($check === 10) {
                $check = 0;
            }
            if ($check !== (int) $digits[$position]) {
                return false;
            }
        }

        return true;
    }

    private static function cnpj(string $value): bool
    {
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $offset => $weights) {
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += (ord($value[$index]) - 48) * $weight;
            }
            $remainder = $sum % 11;
            $check = $remainder < 2 ? 0 : 11 - $remainder;
            if ($check !== (int) $value[12 + $offset]) {
                return false;
            }
        }

        return true;
    }
}
