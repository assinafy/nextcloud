<?php

declare(strict_types=1);

namespace OCA\Assinafy\Service;

use JsonException;
use OCA\Assinafy\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Throwable;

final class SessionStore
{
    private const SESSION = 'oauth_session';
    private const ATTEMPT = 'oauth_attempt';
    private const ENCRYPTED_PREFIX = 'assinafy-v1:';

    /** @var array<string, array<string, mixed>|null> Decoded values for this request, keyed by user and config key. */
    private array $memo = [];

    /** @param IConfig $legacyConfig Used only on Nextcloud 31, where IUserConfig is not public API. */
    public function __construct(
        private readonly IConfig $legacyConfig,
        private readonly ICrypto $crypto,
        private readonly ?IUserConfig $config = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function session(string $userId): ?array
    {
        return $this->read($userId, self::SESSION);
    }

    /** @param array<string, mixed> $session */
    public function saveSession(string $userId, array $session): void
    {
        $this->write($userId, self::SESSION, $session);
    }

    public function deleteSession(string $userId): void
    {
        $this->delete($userId, self::SESSION);
    }

    /** @return array<string, mixed>|null */
    public function attempt(string $userId): ?array
    {
        return $this->read($userId, self::ATTEMPT);
    }

    /** @param array<string, mixed> $attempt */
    public function saveAttempt(string $userId, array $attempt): void
    {
        $this->write($userId, self::ATTEMPT, $attempt);
    }

    public function deleteAttempt(string $userId): void
    {
        $this->delete($userId, self::ATTEMPT);
    }

    /** Forgets this request's cached values so the next read sees writes made by concurrent requests. */
    public function reload(string $userId): void
    {
        unset($this->memo[$userId . ':' . self::SESSION], $this->memo[$userId . ':' . self::ATTEMPT]);
        $this->config?->clearCache($userId);
    }

    /** Rewrites values stored by earlier versions as app-encrypted, lazy, sensitive configuration. */
    public function migrate(string $userId): void
    {
        foreach ([self::SESSION, self::ATTEMPT] as $key) {
            $stored = $this->stored($userId, $key);
            $decoded = $stored === '' ? null : $this->decode($stored);
            if (
                $decoded !== null
                && (
                    !str_starts_with($stored, self::ENCRYPTED_PREFIX)
                    || ($this->config !== null && !$this->config->isSensitive($userId, Application::APP_ID, $key, null))
                )
            ) {
                $this->write($userId, $key, $decoded);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function read(string $userId, string $key): ?array
    {
        $id = $userId . ':' . $key;
        if (!array_key_exists($id, $this->memo)) {
            $stored = $this->stored($userId, $key);
            $this->memo[$id] = $stored === '' ? null : $this->decode($stored);
        }
        return $this->memo[$id];
    }

    private function stored(string $userId, string $key): string
    {
        if ($this->config !== null) {
            $stored = $this->config->getValueString($userId, Application::APP_ID, $key, '', true);
            // A Nextcloud 31 row stays non-lazy until migrated, so the lazy read can miss it.
            return $stored !== '' ? $stored : $this->config->getValueString($userId, Application::APP_ID, $key, '', false);
        }
        // Nextcloud 31: unlike getUserValue(), this query bypasses the per-request configuration cache.
        return (string) ($this->legacyConfig->getUserValueForUsers(Application::APP_ID, $key, [$userId])[$userId] ?? '');
    }

    /** @param array<string, mixed> $value */
    private function write(string $userId, string $key, array $value): void
    {
        // Encrypt on every version: Nextcloud 35 stores FLAG_SENSITIVE user values in plaintext.
        $stored = self::ENCRYPTED_PREFIX . $this->crypto->encrypt(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($this->config !== null) {
            $this->config->setValueString(
                $userId,
                Application::APP_ID,
                $key,
                $stored,
                lazy: true,
                flags: IUserConfig::FLAG_SENSITIVE,
            );
        } else {
            $this->legacyConfig->setUserValue($userId, Application::APP_ID, $key, $stored);
        }
        $this->memo[$userId . ':' . $key] = $value;
    }

    private function delete(string $userId, string $key): void
    {
        // Both config APIs address the same preferences row, so one delete removes it.
        if ($this->config !== null) {
            $this->config->deleteUserConfig($userId, Application::APP_ID, $key);
        } else {
            $this->legacyConfig->deleteUserValue($userId, Application::APP_ID, $key);
        }
        $this->memo[$userId . ':' . $key] = null;
    }

    /** @return array<string, mixed>|null */
    private function decode(string $value): ?array
    {
        if (str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            try {
                $value = $this->crypto->decrypt(substr($value, strlen(self::ENCRYPTED_PREFIX)));
            } catch (Throwable) {
                return null;
            }
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }
}
