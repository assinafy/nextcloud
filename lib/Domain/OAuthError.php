<?php

declare(strict_types=1);

namespace OCA\Assinafy\Domain;

/** OAuth callback failures travel as codes so a crafted link cannot place arbitrary text in the page. */
final class OAuthError
{
    private const MESSAGES = [
        'access_denied' => 'Assinafy access was not granted.',
        'incomplete_response' => 'The OAuth response was incomplete.',
        'invalid_oauth_response' => 'The OAuth response could not be verified. Start the connection again.',
        'oauth_exchange_failed' => 'Assinafy did not accept the OAuth authorization.',
        'workspace_required' => 'Choose exactly one Assinafy workspace when granting access.',
        'oauth_busy' => 'Your Assinafy connection is being updated. Try again.',
        'transport_error' => 'Assinafy could not be reached.',
        'connection_failed' => 'The Assinafy connection could not be completed.',
    ];

    public static function code(string $code): string
    {
        return isset(self::MESSAGES[$code]) ? $code : 'connection_failed';
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? '';
    }
}
