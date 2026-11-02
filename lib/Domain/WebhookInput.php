<?php

declare(strict_types=1);

namespace OCA\Assinafy\Domain;

use OCA\Assinafy\Exception\AssinafyException;

final class WebhookInput
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function sanitize(array $input, bool $partial = false): array
    {
        $output = [];
        if (!$partial || array_key_exists('url', $input)) {
            $url = trim((string) ($input['url'] ?? ''));
            self::validateUrl($url);
            $output['url'] = $url;
        }
        if (!$partial || array_key_exists('email', $input)) {
            $email = trim((string) ($input['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
                throw new AssinafyException('Enter a valid webhook contact email address.');
            }
            $output['email'] = strtolower($email);
        }
        if (!$partial || array_key_exists('events', $input)) {
            $events = $input['events'] ?? null;
            if (!is_array($events) || $events === [] || count($events) > 100) {
                throw new AssinafyException('Choose at least one webhook event.');
            }
            $events = array_values(array_unique(array_map('strval', $events)));
            if (array_filter($events, static fn (string $event): bool => preg_match('/^[a-z][a-z0-9_]{0,99}$/', $event) !== 1)) {
                throw new AssinafyException('A webhook event code is invalid.');
            }
            $output['events'] = $events;
        }
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '' || mb_strlen($name) > 255 || str_contains($name, '<') || str_contains($name, '>')) {
                throw new AssinafyException('Webhook names must contain 1 to 255 plain-text characters.');
            }
            $output['name'] = $name;
        }
        foreach (['is_active', 'signing_enabled'] as $key) {
            if (array_key_exists($key, $input)) {
                $value = filter_var($input[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    throw new AssinafyException('Webhook flags must be true or false.');
                }
                $output[$key] = $value;
            }
        }
        if ($partial && $output === []) {
            throw new AssinafyException('Enter at least one webhook change.');
        }

        return $output;
    }

    private static function validateUrl(string $url): void
    {
        if (strlen($url) > 255 || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new AssinafyException('Enter a valid webhook URL of at most 255 bytes.');
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? null) !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new AssinafyException('Webhook URLs must use HTTPS without embedded credentials.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new AssinafyException('Webhook URLs must use a public host.');
        }
        $ip = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                throw new AssinafyException('Webhook URLs must use a public host.');
            }
        }
    }
}
