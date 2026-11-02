<?php

declare(strict_types=1);

namespace OCA\Assinafy\Settings;

use OCA\Assinafy\AppInfo\Application;
use OCA\Assinafy\Service\ConfigService;
use OCA\Assinafy\Service\OAuthService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

final class Admin implements ISettings
{
    public function __construct(
        private readonly ConfigService $config,
        private readonly OAuthService $oauth,
    ) {
    }

    public function getForm(): TemplateResponse
    {
        Util::addScript(Application::APP_ID, 'admin');
        Util::addStyle(Application::APP_ID, 'style');
        return new TemplateResponse(Application::APP_ID, 'admin', [
            'clientId' => $this->config->clientId(),
            'callbackUrl' => $this->oauth->callbackUrl(),
        ]);
    }

    public function getSection(): string
    {
        return Application::APP_ID;
    }

    public function getPriority(): int
    {
        return 40;
    }
}
