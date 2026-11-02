<?php

declare(strict_types=1);

namespace OCA\Assinafy\Domain;

use OCA\Assinafy\Exception\AssinafyException;

final class Quote
{
    /** @param array<array-key, mixed> $estimate */
    public static function fingerprint(array $estimate): string
    {
        if (
            !is_bool($estimate['has_sufficient_resources'] ?? null)
            || !array_key_exists('total_credits', $estimate)
            || !is_array($estimate['breakdown'] ?? [])
            || array_key_exists('needs_extra_document', $estimate)
                && !is_bool($estimate['needs_extra_document'])
        ) {
            throw new AssinafyException('Assinafy returned an invalid cost estimate.', 502, 'invalid_response');
        }

        $documents = self::number($estimate['documents'] ?? 0);
        if (array_key_exists('documents', $estimate) && !is_int($documents)) {
            throw new AssinafyException('Assinafy returned an invalid cost estimate.', 502, 'invalid_response');
        }
        if (($estimate['needs_extra_document'] ?? false) === true && !array_key_exists('extra_document_cost', $estimate)) {
            throw new AssinafyException('Assinafy returned an invalid cost estimate.', 502, 'invalid_response');
        }

        $breakdown = [];
        foreach (($estimate['breakdown'] ?? []) as $line) {
            if (!is_array($line)) {
                throw new AssinafyException('Assinafy returned an invalid cost estimate.', 502, 'invalid_response');
            }
            $breakdown[] = [
                (string) ($line['code'] ?? ''),
                (string) ($line['name'] ?? ''),
                self::number($line['quantity'] ?? 0),
                self::number($line['cost'] ?? 0),
                self::number($line['unit_cost'] ?? 0),
            ];
        }
        sort($breakdown);

        $payload = [
            $documents,
            self::number($estimate['total_credits']),
            self::number($estimate['credits'] ?? 0),
            ($estimate['needs_extra_document'] ?? false) === true,
            ($estimate['needs_extra_document'] ?? false) === true
                ? self::number($estimate['extra_document_cost'] ?? 0)
                : 0,
            $breakdown,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function number(mixed $value): int | float
    {
        if (!is_int($value) && !is_float($value) || !is_finite((float) $value) || $value < 0) {
            throw new AssinafyException('Assinafy returned an invalid cost estimate.', 502, 'invalid_response');
        }

        return $value;
    }
}
