<?php

declare(strict_types=1);

namespace OCA\Assinafy\Controller;

use OCA\Assinafy\AppInfo\Application;
use OCA\Assinafy\Domain\OAuthError;
use OCA\Assinafy\Exception\AssinafyException;
use OCA\Assinafy\Service\OAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

final class AuthController extends Controller
{
    use LogsUnexpectedFailures;

    public function __construct(
        IRequest $request,
        private readonly OAuthService $oauth,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function connect(): DataResponse
    {
        try {
            return new DataResponse(['url' => $this->oauth->authorizationUrl()]);
        } catch (AssinafyException $error) {
            return new DataResponse(['error' => $error->errorCode(), 'message' => $error->getMessage()], $error->httpStatus());
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function callback(): RedirectResponse
    {
        $error = $this->request->getParam('error');
        if (is_string($error) && $error !== '') {
            return $this->back('access_denied');
        }

        $state = $this->request->getParam('state');
        $code = $this->request->getParam('code');
        $issuer = $this->request->getParam('iss');
        if (!is_string($state) || !is_string($code) || !is_string($issuer)) {
            return $this->back('incomplete_response');
        }

        try {
            $this->oauth->complete($state, $code, $issuer);
            return new RedirectResponse($this->urlGenerator->linkToRoute('assinafy.page.index'));
        } catch (AssinafyException $failure) {
            return $this->back($failure->errorCode());
        } catch (Throwable $failure) {
            $this->logUnexpected('OAuth completion failed unexpectedly.', $failure);
            return $this->back('connection_failed');
        }
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function disconnect(): DataResponse
    {
        try {
            $this->oauth->disconnect();
        } catch (AssinafyException $error) {
            return new DataResponse(['error' => $error->errorCode(), 'message' => $error->getMessage()], $error->httpStatus());
        }
        return new DataResponse(['connected' => false]);
    }

    private function back(string $code): RedirectResponse
    {
        return new RedirectResponse(
            $this->urlGenerator->linkToRoute('assinafy.page.index') . '?' . http_build_query(['error' => OAuthError::code($code)], '', '&', PHP_QUERY_RFC3986),
        );
    }
}
