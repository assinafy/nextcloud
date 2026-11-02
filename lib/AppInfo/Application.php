<?php

declare(strict_types=1);

namespace OCA\Assinafy\AppInfo;

use OCA\Assinafy\Service\SessionStore;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\Security\ICrypto;

final class Application extends App implements IBootstrap
{
    public const APP_ID = 'assinafy';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerService(SessionStore::class, static function ($container): SessionStore {
            return new SessionStore(
                $container->get(IConfig::class),
                $container->get(ICrypto::class),
                interface_exists(IUserConfig::class) ? $container->get(IUserConfig::class) : null,
            );
        });
    }

    public function boot(IBootContext $context): void
    {
    }
}
