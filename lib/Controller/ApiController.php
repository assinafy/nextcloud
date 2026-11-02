<?php

declare(strict_types=1);

namespace OCA\Assinafy\Controller;

use OCA\Assinafy\AppInfo\Application;
use OCA\Assinafy\Exception\AssinafyException;
use OCA\Assinafy\Service\AssinafyClient;
use OCA\Assinafy\Service\FileService;
use OCA\Assinafy\Service\OAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

final class ApiController extends Controller
{
    use LogsUnexpectedFailures;

    public function __construct(
        IRequest $request,
        private readonly OAuthService $oauth,
        private readonly FileService $files,
        private readonly AssinafyClient $client,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function bootstrap(): DataResponse
    {
        return $this->run(function (): array {
            $session = $this->oauth->session();
            return [
                'configured' => $this->oauth->isConfigured(),
                'connected' => $session !== null,
                'account' => $session === null ? null : [
                    'name' => (string) ($session['accountName'] ?? ''),
                    'user' => (string) ($session['name'] ?? ''),
                    'email' => (string) ($session['email'] ?? ''),
                    'scopes' => is_array($session['scopes'] ?? null) ? $session['scopes'] : [],
                ],
            ];
        });
    }

    #[NoAdminRequired]
    public function files(string $path = ''): DataResponse
    {
        return $this->run(fn (): array => ['items' => $this->files->list($path)]);
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function prepareDocument(int $fileId, string $title): DataResponse
    {
        return $this->run(fn (): array => $this->client->prepare($this->files->pdf($fileId), $title));
    }

    /** @param list<array<string, mixed>> $recipients */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function estimateDocument(string $documentId, array $recipients, bool $ordered = false): DataResponse
    {
        return $this->run(fn (): array => $this->client->estimate($documentId, $recipients, $ordered));
    }

    /** @param list<array<string, mixed>> $recipients @param array<string, mixed> $contract */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function sendDocument(
        string $documentId,
        array $recipients,
        string $quoteId,
        bool $ordered = false,
        string $message = '',
        int $expiresInDays = 30,
        array $contract = [],
    ): DataResponse {
        return $this->run(fn (): array => $this->client->send(
            $documentId,
            $recipients,
            $ordered,
            $quoteId,
            $message,
            $expiresInDays,
            $contract,
        ));
    }

    #[NoAdminRequired]
    public function documents(string $search = '', string $status = '', int $page = 1): DataResponse
    {
        return $this->run(fn (): array => ['items' => $this->client->documents($search, $status, $page)]);
    }

    #[NoAdminRequired]
    public function contracts(): DataResponse
    {
        return $this->run(fn (): array => ['items' => $this->client->contracts($this->request->getParams())]);
    }

    /** @param array<string, mixed> $terms */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function saveContract(string $documentId, array $terms): DataResponse
    {
        return $this->run(fn (): array => $this->client->saveContract($documentId, $terms));
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function renewContract(string $documentId, int $term, string $updatedAt = ''): DataResponse
    {
        return $this->run(fn (): array => $this->client->renewContract($documentId, ['term' => $term, 'updated_at' => $updatedAt]));
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function terminateContract(string $documentId, string $reason = '', string $updatedAt = ''): DataResponse
    {
        return $this->run(fn (): array => $this->client->terminateContract($documentId, ['reason' => $reason, 'updated_at' => $updatedAt]));
    }

    #[NoAdminRequired]
    public function webhookEventTypes(): DataResponse
    {
        return $this->run(fn (): array => ['items' => $this->client->webhookEventTypes()]);
    }

    #[NoAdminRequired]
    public function webhookEndpoints(): DataResponse
    {
        return $this->run(fn (): array => ['items' => $this->client->webhookEndpoints()]);
    }

    /** @param list<string> $events */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function createWebhookEndpoint(
        string $url,
        string $email,
        array $events,
        string $name = '',
        bool $isActive = true,
        bool $signingEnabled = true,
    ): DataResponse {
        return $this->run(fn (): array => $this->client->createWebhookEndpoint([
            'url' => $url,
            'email' => $email,
            'events' => $events,
            'name' => $name,
            'is_active' => $isActive,
            'signing_enabled' => $signingEnabled,
        ]));
    }

    /** @param array<string, mixed> $changes */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function updateWebhookEndpoint(string $endpointId, array $changes): DataResponse
    {
        return $this->run(fn (): array => $this->client->updateWebhookEndpoint($endpointId, $changes));
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function deleteWebhookEndpoint(string $endpointId): DataResponse
    {
        return $this->run(function () use ($endpointId): array {
            $this->client->deleteWebhookEndpoint($endpointId);
            return ['deleted' => true];
        });
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function rotateWebhookSecret(string $endpointId): DataResponse
    {
        return $this->run(fn (): array => ['secret' => $this->client->rotateWebhookSecret($endpointId)]);
    }

    #[NoAdminRequired]
    public function webhookDeliveries(): DataResponse
    {
        return $this->run(fn (): array => ['items' => $this->client->webhookDeliveries($this->request->getParams())]);
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function retryWebhookDelivery(string $deliveryId): DataResponse
    {
        return $this->run(fn (): array => $this->client->retryWebhookDelivery($deliveryId));
    }

    /** @param callable(): array<string, mixed> $operation */
    private function run(callable $operation): DataResponse
    {
        try {
            return new DataResponse($operation());
        } catch (AssinafyException $error) {
            return new DataResponse(
                ['error' => $error->errorCode(), 'message' => $error->getMessage()],
                $error->httpStatus(),
            );
        } catch (Throwable $error) {
            $this->logUnexpected('Unhandled Assinafy API failure.', $error);
            return new DataResponse(
                ['error' => 'internal_error', 'message' => 'The operation could not be completed.'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
