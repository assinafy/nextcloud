<?php

declare(strict_types=1);

namespace OCA\Assinafy\Service;

use JsonException;
use OCA\Assinafy\Exception\AssinafyException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ISecureRandom;
use Throwable;

final class OAuthService
{
    public const API_ORIGIN = 'https://api.assinafy.com.br';
    public const API_BASE = self::API_ORIGIN . '/v1';
    public const ISSUER = 'https://auth.assinafy.com.br';
    private const LOCK_ATTEMPTS = 12;
    private const LOCK_RETRY_MICROSECONDS = 250_000;
    public const SCOPES = [
        'documents:read',
        'documents:write',
        'templates:read',
        'account:read',
        'openid',
        'profile',
        'email',
        'offline_access',
        'templates:write',
        'webhooks:write',
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly SessionStore $store,
        private readonly IClientService $clients,
        private readonly IURLGenerator $urlGenerator,
        private readonly IUserSession $userSession,
        private readonly ILockingProvider $locking,
        private readonly ITimeFactory $timeFactory,
        private readonly ISecureRandom $secureRandom,
    ) {
    }

    public function callbackUrl(): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('assinafy.auth.callback');
    }

    public function isConfigured(): bool
    {
        return $this->config->clientId() !== '';
    }

    /** @return array<string, mixed>|null */
    public function session(): ?array
    {
        return $this->store->session($this->userId());
    }

    public function authorizationUrl(): string
    {
        $clientId = $this->config->clientId();
        if ($clientId === '') {
            throw new AssinafyException('An administrator must configure the Assinafy OAuth client ID.', 503, 'not_configured');
        }

        $metadata = $this->discovery();
        $resource = $this->protectedResource();
        $state = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $verifier = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
        $challenge = self::base64Url(hash('sha256', $verifier, true));
        $redirectUri = $this->callbackUrl();

        $this->store->saveAttempt($this->userId(), [
            'state' => $state,
            'verifier' => $verifier,
            'createdAt' => $this->timeFactory->getTime(),
            'redirectUri' => $redirectUri,
            'resource' => $resource,
            'tokenEndpoint' => $metadata['token_endpoint'],
            'revocationEndpoint' => $metadata['revocation_endpoint'],
        ]);

        return $metadata['authorization_endpoint'] . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => $resource,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, mixed> */
    public function complete(string $state, string $code, string $issuer): array
    {
        $userId = $this->userId();
        $lock = $this->lock($userId);
        try {
            $attempt = $this->store->attempt($userId);
            if (
                $attempt === null
                || !is_string($attempt['state'] ?? null)
                || !hash_equals($attempt['state'], $state)
                || !is_int($attempt['createdAt'] ?? null)
                || $this->timeFactory->getTime() - $attempt['createdAt'] > 600
                || $issuer !== self::ISSUER
                || $code === ''
            ) {
                throw new AssinafyException('The OAuth response could not be verified. Start the connection again.', 400, 'invalid_oauth_response');
            }
            $this->store->deleteAttempt($userId);

            $tokens = $this->form((string) $attempt['tokenEndpoint'], [
                'grant_type' => 'authorization_code',
                'client_id' => $this->config->clientId(),
                'redirect_uri' => (string) $attempt['redirectUri'],
                'code' => $code,
                'code_verifier' => (string) $attempt['verifier'],
                'resource' => (string) $attempt['resource'],
            ]);
            if ($tokens->status < 200 || $tokens->status >= 300 || !is_array($tokens->data)) {
                throw new AssinafyException('Assinafy did not accept the OAuth authorization.', 502, 'oauth_exchange_failed');
            }

            try {
                $token = $this->parseTokens($tokens->data, true);
            } catch (Throwable $error) {
                $refreshToken = $tokens->data['refresh_token'] ?? null;
                if (is_string($refreshToken)) {
                    $this->revoke($refreshToken, true, (string) $attempt['revocationEndpoint']);
                }
                throw $error;
            }
            try {
                $profile = $this->bearer('GET', self::API_BASE . '/oauth/userinfo', $token['accessToken']);
                $accounts = $this->bearer('GET', self::API_BASE . '/accounts', $token['accessToken']);
                if (!is_array($profile->data) || array_is_list($profile->data)) {
                    throw new AssinafyException('Assinafy returned an invalid user profile.', 502, 'invalid_response');
                }
                if (!is_array($accounts->data) || !array_is_list($accounts->data) || count($accounts->data) !== 1 || !is_array($accounts->data[0])) {
                    throw new AssinafyException('Choose exactly one Assinafy workspace when granting access.', 400, 'workspace_required');
                }

                $account = $accounts->data[0];
                $subject = trim((string) ($profile->data['sub'] ?? ''));
                $accountId = trim((string) ($account['id'] ?? ''));
                if ($subject === '' || $accountId === '') {
                    throw new AssinafyException('Assinafy returned an invalid account identity.', 502, 'invalid_response');
                }

                $session = $token + [
                    'subject' => $subject,
                    'name' => trim((string) ($profile->data['name'] ?? '')),
                    'email' => trim((string) ($profile->data['email'] ?? '')),
                    'accountId' => $accountId,
                    'accountName' => trim((string) ($account['name'] ?? '')),
                    'resource' => (string) $attempt['resource'],
                    'tokenEndpoint' => (string) $attempt['tokenEndpoint'],
                    'revocationEndpoint' => (string) $attempt['revocationEndpoint'],
                ];
                $this->store->saveSession($userId, $session);

                return $session;
            } catch (Throwable $error) {
                $this->revoke($token['refreshToken'], true, (string) $attempt['revocationEndpoint']);
                throw $error;
            }
        } finally {
            $this->unlock($lock);
        }
    }

    public function accessToken(): string
    {
        $session = $this->store->session($this->userId());
        if ($session === null) {
            throw new AssinafyException('Connect your Assinafy account first.', 401, 'not_connected');
        }
        if ((int) ($session['expiresAt'] ?? 0) <= $this->timeFactory->getTime() + 60) {
            $session = $this->refresh(false);
        }

        return (string) $session['accessToken'];
    }

    /** @return array<string, mixed> */
    public function refresh(bool $force): array
    {
        $userId = $this->userId();
        $lock = $this->lock($userId);
        try {
            $session = $this->store->session($userId);
            if ($session === null) {
                throw new AssinafyException('Connect your Assinafy account first.', 401, 'not_connected');
            }
            if (!$force && (int) ($session['expiresAt'] ?? 0) > $this->timeFactory->getTime() + 60) {
                return $session;
            }
            $refreshToken = (string) ($session['refreshToken'] ?? '');
            if ($refreshToken === '') {
                $this->store->deleteSession($userId);
                throw new AssinafyException('Your Assinafy session expired. Connect again.', 401, 'session_expired');
            }

            try {
                $response = $this->form((string) $session['tokenEndpoint'], [
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->config->clientId(),
                    'refresh_token' => $refreshToken,
                ]);
            } catch (AssinafyException $error) {
                // A timed-out refresh may have rotated the token; never risk replaying the old one.
                $this->store->deleteSession($userId);
                throw new AssinafyException('The OAuth refresh result was uncertain. Connect again.', 401, 'session_expired');
            }

            if ($response->status === 429) {
                throw new AssinafyException('Assinafy is rate limiting OAuth requests. Try again later.', 429, 'oauth_rate_limited');
            }
            if ($response->status === 400 || $response->status === 401) {
                $this->store->deleteSession($userId);
                throw new AssinafyException('Your Assinafy session expired. Connect again.', 401, 'session_expired');
            }
            if ($response->status < 200 || $response->status >= 300 || !is_array($response->data)) {
                // The old rotating refresh token may already be retired; never replay it.
                $this->store->deleteSession($userId);
                throw new AssinafyException('The OAuth refresh result was uncertain. Connect again.', 401, 'session_expired');
            }

            try {
                $tokens = $this->parseTokens($response->data, true);
            } catch (AssinafyException) {
                $replacement = $response->data['refresh_token'] ?? null;
                if (is_string($replacement)) {
                    $this->revoke($replacement, true, (string) ($session['revocationEndpoint'] ?? ''));
                }
                $this->store->deleteSession($userId);
                throw new AssinafyException('The OAuth refresh result was uncertain. Connect again.', 401, 'session_expired');
            }

            $updated = array_replace($session, $tokens);
            try {
                $this->store->saveSession($userId, $updated);
            } catch (Throwable $failure) {
                // The previous refresh token is already retired; never leave it stored for a replay.
                $this->revoke((string) $tokens['refreshToken'], true, (string) ($session['revocationEndpoint'] ?? ''));
                try {
                    $this->store->deleteSession($userId);
                } catch (Throwable) {
                }
                throw $failure;
            }
            return $updated;
        } finally {
            $this->unlock($lock);
        }
    }

    public function disconnect(): void
    {
        $userId = $this->userId();
        $lock = $this->lock($userId);
        try {
            $session = $this->store->session($userId);
            $this->store->deleteSession($userId);
            $this->store->deleteAttempt($userId);
        } finally {
            $this->unlock($lock);
        }
        if ($session !== null && is_string($session['refreshToken'] ?? null)) {
            $this->revoke(
                $session['refreshToken'],
                true,
                (string) ($session['revocationEndpoint'] ?? ''),
            );
        }
    }

    public function invalidate(): void
    {
        $userId = $this->userId();
        $lock = $this->lock($userId);
        try {
            $this->store->deleteSession($userId);
            $this->store->deleteAttempt($userId);
        } finally {
            $this->unlock($lock);
        }
    }

    /** @return array{authorization_endpoint: string, token_endpoint: string, revocation_endpoint: string} */
    private function discovery(): array
    {
        $result = $this->json('GET', self::ISSUER . '/.well-known/oauth-authorization-server');
        if ($result->status !== 200 || !is_array($result->data) || array_is_list($result->data) || ($result->data['issuer'] ?? null) !== self::ISSUER) {
            throw new AssinafyException('Assinafy OAuth discovery could not be verified.', 502, 'oauth_discovery_failed');
        }

        return [
            'authorization_endpoint' => $this->endpoint($result->data, 'authorization_endpoint', self::ISSUER),
            'token_endpoint' => $this->endpoint($result->data, 'token_endpoint', self::API_ORIGIN),
            'revocation_endpoint' => $this->endpoint($result->data, 'revocation_endpoint', self::API_ORIGIN),
        ];
    }

    private function protectedResource(): string
    {
        $result = $this->json('GET', self::API_ORIGIN . '/.well-known/oauth-protected-resource');
        $resource = is_array($result->data) ? ($result->data['resource'] ?? null) : null;
        if ($result->status !== 200 || !is_string($resource) || parse_url($resource, PHP_URL_SCHEME) !== 'https' || self::origin($resource) !== self::API_ORIGIN) {
            throw new AssinafyException('Assinafy OAuth resource discovery could not be verified.', 502, 'oauth_discovery_failed');
        }

        return $resource;
    }

    /** @param array<array-key, mixed> $data */
    private function endpoint(array $data, string $key, string $origin): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || self::origin($value) !== $origin || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
            throw new AssinafyException('Assinafy OAuth discovery returned an unexpected endpoint.', 502, 'oauth_discovery_failed');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data @return array<string, mixed> */
    private function parseTokens(array $data, bool $refreshRequired): array
    {
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;
        if (
            !is_string($accessToken) || $accessToken === ''
            || !is_int($expiresIn) && !is_float($expiresIn)
            || $expiresIn <= 0
            || strtolower((string) ($data['token_type'] ?? '')) !== 'bearer'
            || $refreshRequired && (!is_string($refreshToken) || $refreshToken === '')
        ) {
            throw new AssinafyException('Assinafy returned invalid OAuth tokens.', 502, 'invalid_response');
        }

        $scope = $data['scope'] ?? null;
        return [
            'accessToken' => $accessToken,
            'refreshToken' => is_string($refreshToken) ? $refreshToken : null,
            'expiresAt' => $this->timeFactory->getTime() + (int) $expiresIn,
            'scopes' => is_string($scope)
                ? array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []))
                : array_values(array_diff(self::SCOPES, ['offline_access'])),
        ];
    }

    private function revoke(string $token, bool $refresh, string $endpoint): void
    {
        if ($token === '' || $endpoint === '') {
            return;
        }
        try {
            $this->form($endpoint, [
                'client_id' => $this->config->clientId(),
                'token' => $token,
                'token_type_hint' => $refresh ? 'refresh_token' : 'access_token',
            ]);
        } catch (Throwable) {
        }
    }

    /** @param array<string, string> $fields */
    private function form(string $url, array $fields): HttpResult
    {
        return $this->raw('POST', $url, [
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        ]);
    }

    private function json(string $method, string $url): HttpResult
    {
        return $this->raw($method, $url, ['headers' => ['Accept' => 'application/json']]);
    }

    private function bearer(string $method, string $url, string $token): HttpResult
    {
        $result = $this->raw($method, $url, [
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token],
        ]);
        if ($result->status < 200 || $result->status >= 300) {
            throw new AssinafyException('Assinafy refused the account request.', 502, 'oauth_profile_failed');
        }
        return $result;
    }

    /** @param array<string, mixed> $options */
    private function raw(string $method, string $url, array $options): HttpResult
    {
        try {
            $response = $this->clients->newClient()->request($method, $url, $options + [
                'timeout' => 20,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (Throwable $error) {
            throw new AssinafyException('Assinafy could not be reached.', 503, 'transport_error');
        }
        $body = (string) $response->getBody();
        $data = null;
        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $data = isset($decoded['status'], $decoded['data']) && is_int($decoded['status'])
                        ? (is_array($decoded['data']) ? $decoded['data'] : null)
                        : $decoded;
                }
            } catch (JsonException) {
            }
        }

        return new HttpResult($response->getStatusCode(), $data);
    }

    private function userId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AssinafyException('A Nextcloud login is required.', 401, 'not_authenticated');
        }
        return $user->getUID();
    }

    /** Waits briefly for a concurrent OAuth operation, then reads state that request may have changed. */
    private function lock(string $userId): string
    {
        $path = '/assinafy/oauth/' . hash('sha256', $userId);
        for ($attempt = 1;; $attempt++) {
            try {
                $this->locking->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE);
                break;
            } catch (LockedException) {
                if ($attempt >= self::LOCK_ATTEMPTS) {
                    throw new AssinafyException('Your Assinafy connection is being updated. Try again.', 409, 'oauth_busy');
                }
                usleep(self::LOCK_RETRY_MICROSECONDS);
            }
        }
        // Nextcloud caches user configuration per request; a concurrent request may have rotated the tokens.
        $this->store->reload($userId);
        return $path;
    }

    private function unlock(string $path): void
    {
        $this->locking->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function origin(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_string($scheme) || !is_string($host)) {
            return '';
        }
        return strtolower($scheme) . '://' . strtolower($host) . ($port === null ? '' : ':' . $port);
    }
}
