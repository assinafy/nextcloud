<?php

declare(strict_types=1);

namespace OCA\Assinafy\Domain;

use DateTimeImmutable;
use OCA\Assinafy\Exception\AssinafyException;

final class ContractInput
{
    private const TEXT = ['counterparty_name' => 255, 'counterparty_taxpayer_id' => 20];
    private const DATES = ['start_date', 'end_date'];
    private const INTEGERS = ['renewal_term_months' => [1, 600], 'notice_period_days' => [0, 3650]];

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function sanitize(array $input, bool $allowEmpty = false): array
    {
        $output = [];
        foreach (self::TEXT as $key => $max) {
            if (array_key_exists($key, $input)) {
                $value = trim((string) $input[$key]);
                if (mb_strlen($value) > $max || $key === 'counterparty_name' && (str_contains($value, '<') || str_contains($value, '>'))) {
                    throw new AssinafyException('Contract text fields must use plain text within the supported length.');
                }
                $output[$key] = $value === '' ? null : $value;
            }
        }
        if (($output['counterparty_taxpayer_id'] ?? null) !== null) {
            if (!TaxId::isValid((string) $output['counterparty_taxpayer_id'])) {
                throw new AssinafyException('The counterparty taxpayer ID must be a valid CPF or CNPJ.');
            }
            $output['counterparty_taxpayer_id'] = TaxId::normalize((string) $output['counterparty_taxpayer_id']);
        }
        foreach (self::DATES as $key) {
            if (array_key_exists($key, $input)) {
                $value = trim((string) $input[$key]);
                if ($value !== '' && !self::date($value)) {
                    throw new AssinafyException('Contract dates must use YYYY-MM-DD.');
                }
                $output[$key] = $value === '' ? null : $value;
            }
        }
        foreach (self::INTEGERS as $key => [$minimum, $maximum]) {
            if (array_key_exists($key, $input)) {
                $value = $input[$key];
                if ($value === '' || $value === null) {
                    $output[$key] = null;
                } elseif (!is_int($value) && !(is_string($value) && ctype_digit($value)) || (int) $value < $minimum || (int) $value > $maximum) {
                    throw new AssinafyException('Contract term values are outside the supported range.');
                } else {
                    $output[$key] = (int) $value;
                }
            }
        }
        if (array_key_exists('value', $input)) {
            $value = $input['value'];
            if ($value === '' || $value === null) {
                $output['value'] = null;
            } elseif (!is_numeric($value) || (float) $value < 0 || (float) $value > 9999999999999.99 || round((float) $value, 2) !== (float) $value) {
                throw new AssinafyException('Contract value must be between zero and 9,999,999,999,999.99 with at most two decimal places.');
            } else {
                $output['value'] = (float) $value;
            }
        }
        if (array_key_exists('currency', $input)) {
            $currency = strtoupper(trim((string) $input['currency']));
            if ($currency !== '' && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new AssinafyException('Contract currency must be a three-letter ISO code.');
            }
            $output['currency'] = $currency === '' ? null : $currency;
        }
        if (array_key_exists('auto_renew', $input)) {
            $output['auto_renew'] = filter_var($input['auto_renew'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($output['auto_renew'] === null) {
                throw new AssinafyException('Automatic renewal must be true or false.');
            }
        }
        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (!in_array($status, ['draft', 'active', 'expired', 'terminated'], true)) {
                throw new AssinafyException('Choose a supported contract status.');
            }
            $output['status'] = $status;
        }
        if (array_key_exists('owner_id', $input)) {
            $ownerId = trim((string) $input['owner_id']);
            if ($ownerId !== '' && (strlen($ownerId) > 255 || preg_match('/^\w+$/', $ownerId) !== 1)) {
                throw new AssinafyException('The contract owner ID is invalid.');
            }
            $output['owner_id'] = $ownerId === '' ? null : $ownerId;
        }
        if (isset($output['start_date'], $output['end_date']) && $output['start_date'] !== null && $output['end_date'] !== null && $output['end_date'] < $output['start_date']) {
            throw new AssinafyException('The contract end date cannot be before its start date.');
        }
        if (array_key_exists('updated_at', $input)) {
            $updated = trim((string) $input['updated_at']);
            if (!self::dateTime($updated)) {
                throw new AssinafyException('The contract update timestamp is invalid.');
            }
            $output['updated_at'] = $updated;
        }

        if (!$allowEmpty && $output === []) {
            throw new AssinafyException('Enter at least one contract term.');
        }
        return $output;
    }

    private static function date(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value && (int) $date->format('Y') >= 1000;
    }

    private static function dateTime(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value) === 1
            && self::date(substr($value, 0, 10));
    }
}
