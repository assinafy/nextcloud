<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace OCP;

if (!interface_exists(IAppConfig::class)) {
    interface IAppConfig
    {
        public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string;

        public function setValueString(string $app, string $key, string $value, bool $lazy = false): void;
    }
}

if (!interface_exists(IConfig::class)) {
    interface IConfig
    {
        public function getUserValue(string $userId, string $appName, string $key, string $default = ''): string;

        /** @param list<string> $userIds @return array<string, string> */
        public function getUserValueForUsers(string $appName, string $key, array $userIds): array;

        public function setUserValue(string $userId, string $appName, string $key, string $value): void;

        public function deleteUserValue(string $userId, string $appName, string $key): void;
    }
}

if (!interface_exists(IURLGenerator::class)) {
    interface IURLGenerator
    {
    }
}

if (!interface_exists(IUser::class)) {
    interface IUser
    {
        public function getUID(): string;
    }
}

if (!interface_exists(IUserSession::class)) {
    interface IUserSession
    {
        public function getUser(): ?IUser;
    }
}

namespace OCP\Config;

if (!interface_exists(IUserConfig::class)) {
    interface IUserConfig
    {
        public const FLAG_SENSITIVE = 1;

        public function getValueString(string $userId, string $app, string $key, string $default = '', bool $lazy = false): string;

        public function isSensitive(string $userId, string $app, string $key, ?bool $lazy = false): bool;

        public function setValueString(
            string $userId,
            string $app,
            string $key,
            string $value,
            bool $lazy = false,
            int $flags = 0,
        ): void;

        public function deleteUserConfig(string $userId, string $app, string $key): void;

        public function clearCache(string $userId, bool $reload = false): void;
    }
}

namespace OCP\Files;

if (!interface_exists(Node::class)) {
    interface Node
    {
        public function getId(): int;

        public function getName(): string;

        public function getMimetype(): string;
    }
}

if (!interface_exists(File::class)) {
    interface File extends Node
    {
        public function getSize(): int;
    }
}

if (!interface_exists(Folder::class)) {
    interface Folder extends Node
    {
        public function get(string $path): Node;

        /** @return list<Node> */
        public function getDirectoryListing(): array;

        /** @return list<Node> */
        public function getById(int $id): array;
    }
}

if (!interface_exists(IRootFolder::class)) {
    interface IRootFolder extends Folder
    {
        public function getUserFolder(string $userId): Folder;
    }
}

if (!class_exists(NotFoundException::class)) {
    class NotFoundException extends \RuntimeException
    {
    }
}

if (!class_exists(NotPermittedException::class)) {
    class NotPermittedException extends \RuntimeException
    {
    }
}

namespace OCP\Security;

if (!interface_exists(ICrypto::class)) {
    interface ICrypto
    {
        public function encrypt(string $plaintext, string $password = ''): string;

        public function decrypt(string $authenticatedCiphertext, string $password = ''): string;
    }
}

if (!interface_exists(ISecureRandom::class)) {
    interface ISecureRandom
    {
        public const CHAR_ALPHANUMERIC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

        public function generate(
            int $length,
            string $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
        ): string;
    }
}

namespace OCP\AppFramework;

if (!class_exists(App::class)) {
    class App
    {
        public function __construct(string $appId)
        {
        }
    }
}

namespace OCP\AppFramework\Utility;

if (!interface_exists(ITimeFactory::class)) {
    interface ITimeFactory
    {
        public function getTime(): int;
    }
}

namespace OCP\AppFramework\Bootstrap;

if (!interface_exists(IBootstrap::class)) {
    interface IBootstrap
    {
        public function register(IRegistrationContext $context): void;

        public function boot(IBootContext $context): void;
    }
}

if (!interface_exists(IRegistrationContext::class)) {
    interface IRegistrationContext
    {
        public function registerService(string $name, callable $factory, bool $shared = true): void;
    }
}

if (!interface_exists(IBootContext::class)) {
    interface IBootContext
    {
    }
}

namespace OCP\Http\Client;

if (!interface_exists(IResponse::class)) {
    interface IResponse
    {
        public function getStatusCode(): int;

        public function getBody(): string;
    }
}

if (!interface_exists(IClient::class)) {
    interface IClient
    {
        /** @param array<string, mixed> $options */
        public function request(string $method, string $url, array $options = []): IResponse;
    }
}

if (!interface_exists(IClientService::class)) {
    interface IClientService
    {
        public function newClient(): IClient;
    }
}

namespace OCP\Lock;

if (!interface_exists(ILockingProvider::class)) {
    interface ILockingProvider
    {
        public const LOCK_EXCLUSIVE = 2;

        public function acquireLock(string $path, int $type): void;

        public function releaseLock(string $path, int $type): void;
    }
}

if (!class_exists(LockedException::class)) {
    class LockedException extends \Exception
    {
        public function __construct(string $path = '')
        {
            parent::__construct('"' . $path . '" is locked');
        }
    }
}
