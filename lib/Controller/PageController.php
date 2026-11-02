<?php

declare(strict_types=1);

namespace OCA\Assinafy\Controller;

use OCA\Assinafy\AppInfo\Application;
use OCA\Assinafy\Domain\OAuthError;
use OCA\Assinafy\Service\OAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

final class PageController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly OAuthService $oauth,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        Util::addScript(Application::APP_ID, 'app');
        Util::addStyle(Application::APP_ID, 'style');

        return new TemplateResponse(Application::APP_ID, 'main', [
            'configured' => $this->oauth->isConfigured(),
            'error' => OAuthError::message(trim((string) $this->request->getParam('error', ''))),
        ]);
    }
}
