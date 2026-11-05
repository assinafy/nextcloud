<?php

declare(strict_types=1);

namespace OCA\Assinafy\Tests\Unit;

use OCA\Assinafy\Exception\AssinafyException;
use OCA\Assinafy\Listener\UserDeletedListener;
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
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OAuthServiceTest extends TestCase
{
    private const NOW = 1800000000;

    /** @var array<string, string> */
    private array $userValues;
    private bool $failWrites;
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests;
    /** @var list<string> */
    private array $events;
    private IClient $client;
    private OAuthService $oauth;

    protected function setUp(): void
    {
        $this->userValues = [];
        $this->failWrites = false;
        $this->requests = [];
        $this->events = [];

        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('public-client');

        $userConfig = $this->createStub(IUserConfig::class);
        $userConfig->method('getValueString')->willReturnCallback(
            fn (string $userId, string $app, string $key, string $default = ''): string => $this->userValues["{$userId}:{$app}:{$key}"] ?? $default,
        );
        $userConfig->method('setValueString')->willReturnCallback(
            function (string $userId, string $app, string $key, string $value): void {
                if ($this->failWrites) {
                    throw new RuntimeException('Synthetic storage failure.');
                }
                $this->events[] = 'write:' . $key;
                $this->userValues["{$userId}:{$app}:{$key}"] = $value;
            },
        );
        $userConfig->method('deleteUserConfig')->willReturnCallback(
            function (string $userId, string $app, string $key): void {
                $this->events[] = 'delete:' . $key;
                unset($this->userValues["{$userId}:{$app}:{$key}"]);
            },
        );
        $userConfig->method('isSensitive')->willReturn(true);
        $crypto = $this->createStub(ICrypto::class);
        $crypto->method('encrypt')->willReturnArgument(0);
        $crypto->method('decrypt')->willReturnArgument(0);

        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('nextcloud-user');
        $userSession = $this->createStub(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $locking = $this->createStub(ILockingProvider::class);
        $locking->method('acquireLock')->willReturnCallback(function (): void {
            $this->events[] = 'lock';
        });
        $locking->method('releaseLock')->willReturnCallback(function (): void {
            $this->events[] = 'unlock';
        });

        $this->client = $this->createStub(IClient::class);
        $clients = $this->createStub(IClientService::class);
        $clients->method('newClient')->willReturn($this->client);
        $timeFactory = $this->createStub(ITimeFactory::class);
        $timeFactory->method('getTime')->willReturn(self::NOW);

        $this->oauth = new OAuthService(
            new ConfigService($appConfig),
            new SessionStore($this->createStub(IConfig::class), $crypto, $userConfig),
            $clients,
            $this->createStub(IURLGenerator::class),
            $userSession,
            $locking,
            $timeFactory,
            $this->createStub(ISecureRandom::class),
        );
    }

    #[Test]
    public function failedConnectionRevokesTheUnusedRefreshGrant(): void
    {
        $this->put('oauth_attempt', [
            'state' => 'expected-state',
            'verifier' => 'verifier',
            'createdAt' => self::NOW,
            'redirectUri' => 'https://cloud.example.invalid/apps/assinafy/oauth/callback',
            'resource' => OAuthService::API_ORIGIN,
            'tokenEndpoint' => OAuthService::API_ORIGIN . '/oauth/token',
            'revocationEndpoint' => OAuthService::API_ORIGIN . '/oauth/revoke',
        ]);
        $responses = [
            $this->response(200, ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            $this->response(200, ['status' => 200, 'data' => []]),
            $this->response(200, ['status' => 200, 'data' => [['id' => 'account']]]),
            $this->response(200, []),
        ];
        $this->respondWith($responses);

        try {
            $this->oauth->complete('expected-state', 'authorization-code', OAuthService::ISSUER);
            self::fail('Invalid profile was accepted.');
        } catch (AssinafyException) {
            self::assertSame('unlock', $this->events[array_key_last($this->events)]);
        }

        parse_str((string) $this->requests[3][2]['body'], $revocation);
        self::assertSame('refresh', $revocation['token']);
        self::assertSame('refresh_token', $revocation['token_type_hint']);
        self::assertArrayNotHasKey($this->key('oauth_session'), $this->userValues);
    }

    #[Test]
    public function invalidRotatedTokensAreRevokedAndTheSessionIsRemoved(): void
    {
        $this->put('oauth_session', $this->session());
        $this->respondWith([
            $this->response(200, ['access_token' => 'replacement-access', 'refresh_token' => 'replacement-refresh', 'expires_in' => '3600', 'token_type' => 'Bearer']),
            $this->response(200, []),
        ]);

        try {
            $this->oauth->refresh(true);
            self::fail('Malformed rotated tokens were accepted.');
        } catch (AssinafyException $error) {
            self::assertSame('session_expired', $error->errorCode());
        }

        parse_str((string) $this->requests[1][2]['body'], $revocation);
        self::assertSame('replacement-refresh', $revocation['token']);
        self::assertArrayNotHasKey($this->key('oauth_session'), $this->userValues);
        self::assertSame('unlock', $this->events[array_key_last($this->events)]);
    }

    #[Test]
    public function storageFailureAfterRotationStaysOperationalAndRetiresTheNewGrant(): void
    {
        $this->put('oauth_session', $this->session());
        $this->failWrites = true;
        $this->respondWith([
            $this->response(200, ['access_token' => 'replacement-access', 'refresh_token' => 'replacement-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            $this->response(200, []),
        ]);

        try {
            $this->oauth->refresh(true);
            self::fail('A storage failure was hidden.');
        } catch (AssinafyException $error) {
            self::fail('A storage failure was reported as ' . $error->errorCode() . '.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic storage failure.', $error->getMessage());
        }

        parse_str((string) $this->requests[1][2]['body'], $revocation);
        self::assertSame('replacement-refresh', $revocation['token']);
        self::assertSame('unlock', $this->events[array_key_last($this->events)]);
    }

    #[Test]
    public function disconnectDeletesStateUnderTheUserLockBeforeRevocation(): void
    {
        $this->put('oauth_session', $this->session());
        $this->put('oauth_attempt', ['state' => 'unused']);
        $this->respondWith([$this->response(200, [])]);

        $this->oauth->disconnect();

        self::assertSame(['lock', 'delete:oauth_session', 'delete:oauth_attempt', 'unlock', 'request'], $this->events);
        self::assertArrayNotHasKey($this->key('oauth_session'), $this->userValues);
        self::assertArrayNotHasKey($this->key('oauth_attempt'), $this->userValues);
    }

    #[Test]
    public function deletingAUserDisconnectsTheirOAuthGrant(): void
    {
        $this->put('oauth_session', $this->session());
        $this->respondWith([$this->response(200, [])]);
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('nextcloud-user');

        (new UserDeletedListener($this->oauth))->handle(new BeforeUserDeletedEvent($user));

        self::assertArrayNotHasKey($this->key('oauth_session'), $this->userValues);
        parse_str((string) $this->requests[0][2]['body'], $revocation);
        self::assertSame('refresh', $revocation['token']);
    }

    /** @param list<IResponse> $responses */
    private function respondWith(array $responses): void
    {
        $this->client->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) use (&$responses): IResponse {
                $this->events[] = 'request';
                $this->requests[] = [$method, $url, $options];
                $response = array_shift($responses);
                self::assertInstanceOf(IResponse::class, $response);
                return $response;
            },
        );
    }

    /** @param array<string, mixed> $body */
    private function response(int $status, array $body): IResponse
    {
        $response = $this->createStub(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        return $response;
    }

    /** @param array<string, mixed> $value */
    private function put(string $key, array $value): void
    {
        $this->userValues[$this->key($key)] = 'assinafy-v1:' . json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function key(string $key): string
    {
        return 'nextcloud-user:assinafy:' . $key;
    }

    /** @return array<string, mixed> */
    private function session(): array
    {
        return [
            'accessToken' => 'access',
            'refreshToken' => 'refresh',
            'expiresAt' => self::NOW - 1,
            'tokenEndpoint' => OAuthService::API_ORIGIN . '/oauth/token',
            'revocationEndpoint' => OAuthService::API_ORIGIN . '/oauth/revoke',
        ];
    }
}
