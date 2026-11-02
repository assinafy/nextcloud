<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace OCA\Assinafy\Tests;

use OCA\Assinafy\Exception\AssinafyException;
use OCA\Assinafy\Service\AssinafyClient;
use OCA\Assinafy\Service\ConfigService;
use OCA\Assinafy\Service\OAuthService;
use OCA\Assinafy\Service\SessionStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use RuntimeException;
use Throwable;

final class ServiceTestResponse implements IResponse
{
    public function __construct(
        private readonly int $status,
        private readonly string $body,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}

final class ServiceTestClient implements IClient
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $requests = [];

    /** @param list<IResponse|Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $options = []): IResponse
    {
        $this->requests[] = [$method, $url, $options];
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if (!$response instanceof IResponse) {
            throw new RuntimeException('No fake response queued for ' . $method . ' ' . $url);
        }
        return $response;
    }
}

final class ServiceTestClientService implements IClientService
{
    public function __construct(public readonly ServiceTestClient $client)
    {
    }

    public function newClient(): IClient
    {
        return $this->client;
    }
}

final class ServiceTestAppConfig implements IAppConfig
{
    public string $value = 'public-client';

    public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string
    {
        return $this->value;
    }

    public function setValueString(string $app, string $key, string $value, bool $lazy = false): void
    {
        $this->value = $value;
    }
}

final class ServiceTestModernConfig implements IUserConfig
{
    /** @var array<string, string> Share with ServiceTestLegacyConfig::$values to model one preferences table. */
    public array $values = [];
    /** @var array<string, int> Flags of rows written through IUserConfig. */
    public array $rowFlags = [];
    public bool $lazy = false;
    public int $flags = 0;
    /** Models a lazy read before Nextcloud has loaded the user's non-lazy rows. */
    public bool $lazyReadSkipsLegacyRows = false;
    /** Models Nextcloud's per-request cache: once loaded, reads ignore rows written by other requests. */
    public bool $cachesReads = false;
    /** @var array<string, string>|null */
    private ?array $cache = null;
    public int $writes = 0;

    public function getValueString(string $userId, string $app, string $key, string $default = '', bool $lazy = false): string
    {
        $id = $userId . ':' . $app . ':' . $key;
        if ($lazy && $this->lazyReadSkipsLegacyRows && !isset($this->rowFlags[$id])) {
            return $default;
        }
        $rows = $this->cachesReads ? ($this->cache ??= $this->values) : $this->values;
        return $rows[$id] ?? $default;
    }

    public function clearCache(string $userId, bool $reload = false): void
    {
        $this->cache = null;
    }

    public function isSensitive(string $userId, string $app, string $key, ?bool $lazy = false): bool
    {
        $id = $userId . ':' . $app . ':' . $key;
        if (!isset($this->values[$id])) {
            throw new RuntimeException('Unknown config key.');
        }
        return (($this->rowFlags[$id] ?? 0) & self::FLAG_SENSITIVE) !== 0;
    }

    public function setValueString(string $userId, string $app, string $key, string $value, bool $lazy = false, int $flags = 0): void
    {
        $this->writes++;
        $this->lazy = $lazy;
        $this->flags = $flags;
        $this->values[$userId . ':' . $app . ':' . $key] = $value;
        $this->rowFlags[$userId . ':' . $app . ':' . $key] = $flags;
        $this->cache = null;
    }

    public function deleteUserConfig(string $userId, string $app, string $key): void
    {
        unset($this->values[$userId . ':' . $app . ':' . $key], $this->rowFlags[$userId . ':' . $app . ':' . $key]);
        $this->cache = null;
    }
}

final class ServiceTestLegacyConfig implements IConfig
{
    /** @var array<string, string> */
    public array $values = [];
    public int $deletes = 0;

    /** @var array<string, string>|null */
    private ?array $cache = null;
    public int $cachedReads = 0;

    public function getUserValue(string $userId, string $appName, string $key, string $default = ''): string
    {
        $this->cachedReads++;
        $this->cache ??= $this->values;
        return $this->cache[$userId . ':' . $appName . ':' . $key] ?? $default;
    }

    public function getUserValueForUsers(string $appName, string $key, array $userIds): array
    {
        $values = [];
        foreach ($userIds as $userId) {
            if (isset($this->values[$userId . ':' . $appName . ':' . $key])) {
                $values[$userId] = $this->values[$userId . ':' . $appName . ':' . $key];
            }
        }
        return $values;
    }

    public function setUserValue(string $userId, string $appName, string $key, string $value): void
    {
        $this->values[$userId . ':' . $appName . ':' . $key] = $value;
    }

    public function deleteUserValue(string $userId, string $appName, string $key): void
    {
        $this->deletes++;
        unset($this->values[$userId . ':' . $appName . ':' . $key]);
    }
}

final class ServiceTestCrypto implements ICrypto
{
    public int $decrypts = 0;

    public function encrypt(string $plaintext, string $password = ''): string
    {
        return base64_encode($plaintext);
    }

    public function decrypt(string $authenticatedCiphertext, string $password = ''): string
    {
        $this->decrypts++;
        $plaintext = base64_decode($authenticatedCiphertext, true);
        if ($plaintext === false) {
            throw new RuntimeException('Invalid test ciphertext.');
        }
        return $plaintext;
    }
}

final class ServiceTestTimeFactory implements ITimeFactory
{
    public const NOW = 1800000000;

    public function getTime(): int
    {
        return self::NOW;
    }
}

final class ServiceTestSecureRandom implements ISecureRandom
{
    public function generate(
        int $length,
        string $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
    ): string {
        return str_repeat($characters[0], $length);
    }
}

/** @return array{OAuthService, ServiceTestModernConfig, ServiceTestLegacyConfig} */
function serviceTestOAuth(ServiceTestClientService $clients, ?array $session = null, ?ILockingProvider $locking = null): array
{
    $appConfig = new ServiceTestAppConfig();
    $modern = new ServiceTestModernConfig();
    $legacy = new ServiceTestLegacyConfig();
    if ($session !== null) {
        $modern->values['nextcloud-user:assinafy:oauth_session'] = json_encode($session, JSON_THROW_ON_ERROR);
    }
    $user = new class implements IUser {
        public function getUID(): string
        {
            return 'nextcloud-user';
        }
    };
    $userSession = new class ($user) implements IUserSession {
        public function __construct(private readonly IUser $user)
        {
        }

        public function getUser(): ?IUser
        {
            return $this->user;
        }
    };
    $locking ??= new class implements ILockingProvider {
        public function acquireLock(string $path, int $type): void
        {
        }

        public function releaseLock(string $path, int $type): void
        {
        }
    };
    $oauth = new OAuthService(
        new ConfigService($appConfig),
        new SessionStore($legacy, new ServiceTestCrypto(), $modern),
        $clients,
        new class implements IURLGenerator {
        },
        $userSession,
        $locking,
        new ServiceTestTimeFactory(),
        new ServiceTestSecureRandom(),
    );
    return [$oauth, $modern, $legacy];
}

function serviceTestApiResponse(mixed $data, int $status = 200): ServiceTestResponse
{
    return new ServiceTestResponse($status, json_encode(['status' => $status, 'data' => $data], JSON_THROW_ON_ERROR));
}

/** @return array<string, mixed> */
function serviceTestEstimate(): array
{
    return [
        'documents' => 1,
        'credits' => 0,
        'needs_extra_document' => false,
        'total_credits' => 0,
        'breakdown' => [],
        'has_sufficient_resources' => true,
    ];
}

/** @return list<array<string, mixed>> */
function serviceTestRecipients(): array
{
    return [[
        'name' => 'Example Signer',
        'email' => 'signer@example.invalid',
        'method' => 'email',
        'notification' => 'email',
    ]];
}

/** @return array<string, mixed> */
function serviceTestSession(): array
{
    return [
        'accessToken' => 'test-access',
        'refreshToken' => 'test-refresh',
        'expiresAt' => ServiceTestTimeFactory::NOW + 3600,
        'accountId' => 'account-test',
        'tokenEndpoint' => OAuthService::API_ORIGIN . '/v1/oauth/token',
        'revocationEndpoint' => OAuthService::API_ORIGIN . '/v1/oauth/revoke',
    ];
}

function runServiceChecks(callable $check, callable $rejects): void
{
    $appConfig = new ServiceTestAppConfig();
    $config = new ConfigService($appConfig);
    $rejects(static fn () => $config->setClientId('invalid client'), 'Invalid OAuth client ID was accepted.');
    $config->setClientId(' valid-client ');
    $check($appConfig->value === 'valid-client', 'OAuth client ID was not normalized at the service boundary.');

    $legacy = new ServiceTestLegacyConfig();
    $store = new SessionStore($legacy, new ServiceTestCrypto());
    $store->saveSession('nextcloud-user', serviceTestSession());
    $stored = $legacy->values['nextcloud-user:assinafy:oauth_session'];
    $check(str_starts_with($stored, 'assinafy-v1:'), 'Nextcloud 31 session was not encrypted.');
    $check(!str_contains($stored, 'test-access'), 'Legacy storage contains a readable access token.');
    $check($store->session('nextcloud-user') === serviceTestSession(), 'Legacy encrypted session did not round-trip.');
    $store->deleteSession('nextcloud-user');
    $check($store->session('nextcloud-user') === null, 'Legacy session deletion failed.');

    $legacy->values['nextcloud-user:assinafy:oauth_session'] = json_encode(serviceTestSession(), JSON_THROW_ON_ERROR);
    $store->reload('nextcloud-user');
    $check($store->session('nextcloud-user') === serviceTestSession(), 'Plaintext legacy session was not readable.');
    $store->migrate('nextcloud-user');
    $check(str_starts_with($legacy->values['nextcloud-user:assinafy:oauth_session'], 'assinafy-v1:'), 'Migration left a Nextcloud 31 session unencrypted.');

    // Nextcloud 32+ keeps IConfig user values and IUserConfig values in the same preferences row.
    foreach ([true, false] as $lazyReadSkipsLegacyRows) {
        $legacy = new ServiceTestLegacyConfig();
        (new SessionStore($legacy, new ServiceTestCrypto()))->saveSession('nextcloud-user', serviceTestSession());
        $modern = new ServiceTestModernConfig();
        $modern->values = &$legacy->values;
        $modern->lazyReadSkipsLegacyRows = $lazyReadSkipsLegacyRows;
        $store = new SessionStore($legacy, new ServiceTestCrypto(), $modern);
        $check($store->session('nextcloud-user') === serviceTestSession(), 'Nextcloud 31 session was unreadable after the upgrade.');
        $store->reload('nextcloud-user');
        $check($store->session('nextcloud-user') === serviceTestSession(), 'Reading a Nextcloud 31 session deleted it.');
        $check($modern->writes === 0, 'An unlocked read rewrote stored OAuth state.');
        $store->migrate('nextcloud-user');
        $store->reload('nextcloud-user');
        $check($store->session('nextcloud-user') === serviceTestSession(), 'Migrating a Nextcloud 31 session lost it.');
        $check($modern->lazy && $modern->flags === IUserConfig::FLAG_SENSITIVE, 'Migrated session is not lazy sensitive configuration.');
    }

    $crypto = new ServiceTestCrypto();
    $legacy = new ServiceTestLegacyConfig();
    $modern = new ServiceTestModernConfig();
    $store = new SessionStore($legacy, $crypto, $modern);
    $store->saveSession('nextcloud-user', serviceTestSession());
    $stored = $modern->values['nextcloud-user:assinafy:oauth_session'];
    $check(str_starts_with($stored, 'assinafy-v1:') && !str_contains($stored, 'test-access'), 'Modern storage relies on Nextcloud to encrypt the session.');
    $store->reload('nextcloud-user');
    $crypto->decrypts = 0;
    $store->session('nextcloud-user');
    $check($store->session('nextcloud-user') === serviceTestSession() && $crypto->decrypts === 1, 'Repeated reads decrypted the session more than once per request.');
    $modern->values['nextcloud-user:assinafy:oauth_session'] = json_encode(serviceTestSession(), JSON_THROW_ON_ERROR);
    $store->reload('nextcloud-user');
    $check($store->session('nextcloud-user') === serviceTestSession(), 'Unwrapped modern session was unreadable.');
    $store->migrate('nextcloud-user');
    $check(str_starts_with($modern->values['nextcloud-user:assinafy:oauth_session'], 'assinafy-v1:'), 'Migration left a modern session unencrypted.');
    $writes = $modern->writes;
    $store->migrate('nextcloud-user');
    $check($modern->writes === $writes, 'Migration rewrote an already migrated session.');
    $store->deleteSession('nextcloud-user');
    $check($legacy->deletes === 0 && $store->session('nextcloud-user') === null, 'One preferences row was deleted through both config APIs.');

    // Nextcloud 31 caches getUserValue() per request; a locked re-read must see another request's rotation.
    $legacy = new ServiceTestLegacyConfig();
    $store = new SessionStore($legacy, new ServiceTestCrypto());
    $store->saveSession('nextcloud-user', ['refreshToken' => 'first'] + serviceTestSession());
    $legacy->getUserValue('nextcloud-user', 'assinafy', 'oauth_session');
    (new SessionStore($legacy, new ServiceTestCrypto()))->saveSession('nextcloud-user', ['refreshToken' => 'rotated'] + serviceTestSession());
    $store->reload('nextcloud-user');
    $check(($store->session('nextcloud-user')['refreshToken'] ?? null) === 'rotated', 'A Nextcloud 31 re-read used the per-request configuration cache.');

    // Nextcloud 32+ reads Nextcloud 31 rows through IUserConfig, not the deprecated IConfig user API.
    $legacy = new ServiceTestLegacyConfig();
    (new SessionStore($legacy, new ServiceTestCrypto()))->saveSession('nextcloud-user', serviceTestSession());
    $modern = new ServiceTestModernConfig();
    $modern->values = &$legacy->values;
    $modern->lazyReadSkipsLegacyRows = true;
    $legacy->cachedReads = 0;
    $store = new SessionStore($legacy, new ServiceTestCrypto(), $modern);
    $check($store->session('nextcloud-user') === serviceTestSession() && $legacy->cachedReads === 0, 'Nextcloud 32+ read OAuth state through the deprecated IConfig API.');

    // Another request rotated the tokens after this request cached them: never replay the old refresh token.
    $client = new ServiceTestClient([]);
    [$oauth, $modern] = serviceTestOAuth(new ServiceTestClientService($client), ['expiresAt' => ServiceTestTimeFactory::NOW - 1] + serviceTestSession());
    $modern->cachesReads = true;
    $oauth->session();
    $modern->values['nextcloud-user:assinafy:oauth_session'] = json_encode(['accessToken' => 'rotated-access', 'refreshToken' => 'rotated-refresh'] + serviceTestSession(), JSON_THROW_ON_ERROR);
    $check($oauth->accessToken() === 'rotated-access', 'A locked refresh used a stale cached session.');
    $check($client->requests === [], 'A stale refresh token was replayed.');

    $locking = new class implements ILockingProvider {
        public int $busy = 2;
        public int $attempts = 0;

        public function acquireLock(string $path, int $type): void
        {
            $this->attempts++;
            if ($this->busy-- > 0) {
                throw new LockedException($path);
            }
        }

        public function releaseLock(string $path, int $type): void
        {
        }
    };
    [$oauth, $modern] = serviceTestOAuth(new ServiceTestClientService(new ServiceTestClient([])), serviceTestSession(), $locking);
    $oauth->invalidate();
    $check($locking->attempts === 3 && !isset($modern->values['nextcloud-user:assinafy:oauth_session']), 'A briefly held OAuth lock was not awaited.');
    $locking->busy = PHP_INT_MAX;
    try {
        $oauth->invalidate();
        $check(false, 'A permanently held OAuth lock was not reported.');
    } catch (AssinafyException $error) {
        $check($error->errorCode() === 'oauth_busy' && $error->httpStatus() === 409, 'A held OAuth lock did not return oauth_busy.');
    }

    $client = new ServiceTestClient([]);
    $clients = new ServiceTestClientService($client);
    [$oauth, $modern] = serviceTestOAuth($clients);
    $attempt = [
        'state' => 'expected-state',
        'verifier' => 'verifier',
        'createdAt' => ServiceTestTimeFactory::NOW,
        'redirectUri' => 'https://cloud.example.invalid/apps/assinafy/oauth/callback',
        'resource' => OAuthService::API_ORIGIN,
        'tokenEndpoint' => OAuthService::API_ORIGIN . '/v1/oauth/token',
        'revocationEndpoint' => OAuthService::API_ORIGIN . '/v1/oauth/revoke',
    ];
    $modern->values['nextcloud-user:assinafy:oauth_attempt'] = json_encode($attempt, JSON_THROW_ON_ERROR);
    $rejects(static fn () => $oauth->complete('wrong-state', 'authorization-code', OAuthService::ISSUER), 'Mismatched OAuth state was accepted.');
    $check(isset($modern->values['nextcloud-user:assinafy:oauth_attempt']), 'Invalid callback consumed a valid OAuth attempt.');
    $attempt['createdAt'] = ServiceTestTimeFactory::NOW - 601;
    $modern->values['nextcloud-user:assinafy:oauth_attempt'] = json_encode($attempt, JSON_THROW_ON_ERROR);
    $rejects(static fn () => $oauth->complete('expected-state', 'authorization-code', OAuthService::ISSUER), 'Expired OAuth state was accepted.');
    $check(isset($modern->values['nextcloud-user:assinafy:oauth_attempt']), 'Expired callback consumed an OAuth attempt.');
    $check($client->requests === [], 'Invalid OAuth callback reached token exchange.');

    $client = new ServiceTestClient([
        serviceTestApiResponse(['id' => 'document-test', 'status' => 'metadata_ready', 'assignment' => null]),
        serviceTestApiResponse(serviceTestEstimate()),
    ]);
    [$oauth] = serviceTestOAuth(new ServiceTestClientService($client), serviceTestSession());
    $api = new AssinafyClient($oauth, new ServiceTestClientService($client), new ServiceTestTimeFactory());
    $recipients = serviceTestRecipients();
    $recipients[] = [
        'name' => 'Certificate Signer',
        'email' => 'certificate@example.invalid',
        'method' => 'certificate',
        'notification' => 'email',
        'taxId' => '11.222.333/0001-81',
    ];
    $quote = $api->estimate('document-test', $recipients, true);
    $check(is_string($quote['quoteId']) && strlen($quote['quoteId']) === 64, 'Estimate did not return a quote fingerprint.');
    $estimateBody = json_decode($client->requests[1][2]['body'], true, 512, JSON_THROW_ON_ERROR);
    $check(!array_key_exists('step', $estimateBody['signers'][0]), 'Estimate payload contains an unsupported signing step.');
    $check($estimateBody['signers'][1]['verification_method'] === 'DigitalCertificate', 'Certificate estimate mapping is incorrect.');

    $client = new ServiceTestClient([]);
    [$oauth] = serviceTestOAuth(new ServiceTestClientService($client), serviceTestSession());
    $api = new AssinafyClient($oauth, new ServiceTestClientService($client), new ServiceTestTimeFactory());
    $rejects(static fn () => $api->saveContract('document-test', ['counterparty_name' => 'Example Company']), 'Standalone edit omitted updated_at.');
    $check($client->requests === [], 'Preconditionless standalone contract edit reached Assinafy.');
    $rejects(static fn () => $api->send('document-test', serviceTestRecipients(), false, 'quote', '', 30, ['start_date' => 'not-a-date']), 'Invalid contract reached the paid send path.');
    $check($client->requests === [], 'Invalid contract terms caused upstream work before assignment.');

    $client = new ServiceTestClient([serviceTestApiResponse(['document_id' => 'document-test'])]);
    [$oauth] = serviceTestOAuth(new ServiceTestClientService($client), serviceTestSession());
    $api = new AssinafyClient($oauth, new ServiceTestClientService($client), new ServiceTestTimeFactory());
    $terms = ['counterparty_name' => 'Example Company', 'updated_at' => '2026-10-31T12:00:00Z'];
    $api->saveContract('document-test', $terms);
    $updateBody = json_decode($client->requests[0][2]['body'], true, 512, JSON_THROW_ON_ERROR);
    $check($updateBody === $terms, 'Standalone contract update did not preserve its concurrency token.');
    $check($client->requests[0][1] === OAuthService::API_BASE . '/accounts/account-test/documents/document-test/contract', 'Contract update used the wrong upstream route.');

    $client = new ServiceTestClient([
        serviceTestApiResponse(['id' => 'document-test', 'status' => 'metadata_ready', 'assignment' => null]),
        serviceTestApiResponse(serviceTestEstimate()),
        serviceTestApiResponse(['id' => 'document-test', 'status' => 'metadata_ready', 'assignment' => null]),
        serviceTestApiResponse(serviceTestEstimate()),
        serviceTestApiResponse([['id' => 'signer-test', 'full_name' => 'Example Signer', 'email' => 'signer@example.invalid']]),
        serviceTestApiResponse(['id' => 'assignment-test']),
        serviceTestApiResponse(['document_id' => 'document-test', 'status' => 'draft']),
    ]);
    [$oauth] = serviceTestOAuth(new ServiceTestClientService($client), serviceTestSession());
    $api = new AssinafyClient($oauth, new ServiceTestClientService($client), new ServiceTestTimeFactory());
    $quote = $api->estimate('document-test', serviceTestRecipients(), false);
    $result = $api->send('document-test', serviceTestRecipients(), false, $quote['quoteId'], '', 30, ['counterparty_name' => 'Example Company']);
    $check($result['assignment']['id'] === 'assignment-test', 'Assignment result was not preserved.');
    $check($result['contract']['status'] === 'draft' && $result['contractError'] === null, 'New contract was not written after assignment.');
    $assignmentBody = json_decode($client->requests[5][2]['body'], true, 512, JSON_THROW_ON_ERROR);
    $check(
        $assignmentBody['expires_at'] === gmdate('c', ServiceTestTimeFactory::NOW + 30 * 86400),
        'Assignment expiry did not use the injected clock.',
    );
    $contractBody = json_decode($client->requests[6][2]['body'], true, 512, JSON_THROW_ON_ERROR);
    $check(!array_key_exists('updated_at', $contractBody), 'Initial contract creation incorrectly requires updated_at.');

    $client = new ServiceTestClient([
        serviceTestApiResponse(['id' => 'document-test', 'status' => 'metadata_ready', 'assignment' => null]),
        serviceTestApiResponse(serviceTestEstimate()),
        serviceTestApiResponse(['id' => 'document-test', 'status' => 'metadata_ready', 'assignment' => null]),
        serviceTestApiResponse(serviceTestEstimate()),
        serviceTestApiResponse([['id' => 'signer-test', 'full_name' => 'Example Signer', 'email' => 'signer@example.invalid']]),
        new RuntimeException('Synthetic transport failure'),
    ]);
    [$oauth] = serviceTestOAuth(new ServiceTestClientService($client), serviceTestSession());
    $api = new AssinafyClient($oauth, new ServiceTestClientService($client), new ServiceTestTimeFactory());
    $quote = $api->estimate('document-test', serviceTestRecipients(), false);
    $rejects(static fn () => $api->send('document-test', serviceTestRecipients(), false, $quote['quoteId'], '', 30, []), 'Ambiguous assignment transport failure did not fail explicitly.');
    $assignmentRequests = array_values(array_filter($client->requests, static fn (array $request): bool => str_ends_with($request[1], '/assignments')));
    $check(count($assignmentRequests) === 1, 'Ambiguous assignment creation was retried automatically.');
}
