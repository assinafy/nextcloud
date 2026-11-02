<?php

declare(strict_types=1);

namespace OCA\Assinafy\Service;

use OCA\Assinafy\AppInfo\Application;
use OCA\Assinafy\Exception\AssinafyException;
use OCP\IAppConfig;

final class ConfigService
{
    private const CLIENT_ID = 'oauth_client_id';

    public function __construct(private readonly IAppConfig $config)
    {
    }

    public function clientId(): string
    {
        return trim($this->config->getValueString(Application::APP_ID, self::CLIENT_ID, '', true));
    }

    public function setClientId(string $clientId): void
    {
        $clientId = trim($clientId);
        if ($clientId === '' || strlen($clientId) > 255 || preg_match('/^[A-Za-z0-9._~-]+$/', $clientId) !== 1) {
            throw new AssinafyException('Enter a valid OAuth client ID.', 400, 'invalid_client_id');
        }
        $this->config->setValueString(
            Application::APP_ID,
            self::CLIENT_ID,
            $clientId,
            lazy: true,
        );
    }
}
