<?php

declare(strict_types=1);

namespace OCA\Assinafy\Controller;

use OCA\Assinafy\AppInfo\Application;
use OCA\Assinafy\Exception\AssinafyException;
use OCA\Assinafy\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class SettingsController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly ConfigService $config,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[UserRateLimit(limit: 10, period: 60)]
    public function save(string $clientId): DataResponse
    {
        try {
            $this->config->setClientId($clientId);
        } catch (AssinafyException $error) {
            return new DataResponse(['error' => $error->errorCode(), 'message' => $error->getMessage()], $error->httpStatus());
        }
        return new DataResponse(['saved' => true]);
    }
}
