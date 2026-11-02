<?php

declare(strict_types=1);

namespace OCA\Assinafy\Service;

use JsonException;
use OCA\Assinafy\Domain\ContractInput;
use OCA\Assinafy\Domain\Quote;
use OCA\Assinafy\Domain\Recipient;
use OCA\Assinafy\Domain\TaxId;
use OCA\Assinafy\Domain\WebhookInput;
use OCA\Assinafy\Exception\AssinafyException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use Throwable;

final class AssinafyClient
{
    public function __construct(
        private readonly OAuthService $oauth,
        private readonly IClientService $clients,
        private readonly ITimeFactory $timeFactory,
    ) {
    }

    /** @return array<array-key, mixed> */
    public function prepare(File $file, string $title): array
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 250) {
            throw new AssinafyException('Document titles must contain 1 to 250 characters.');
        }
        $document = $this->upload($file);
        $documentId = $this->id($document, 'document');
        $filename = str_ends_with(mb_strtolower($title), '.pdf') ? $title : $title . '.pdf';
        if ((string) ($document['name'] ?? '') !== $filename) {
            $document = $this->request('PATCH', '/documents/' . rawurlencode($documentId), json: ['name' => $filename]);
        }
        return $document;
    }

    /** @param list<array<string, mixed>> $input @return array{estimate: array<string, mixed>, quoteId: string} */
    public function estimate(string $documentId, array $input, bool $ordered): array
    {
        $recipients = $this->recipients($input, $ordered);
        $document = $this->request('GET', '/documents/' . rawurlencode($documentId));
        if (($document['status'] ?? null) !== 'metadata_ready' || !empty($document['assignment'])) {
            throw new AssinafyException('The document is still processing or has already been sent.', 409, 'document_not_ready');
        }
        $estimate = $this->request('POST', '/documents/' . rawurlencode($documentId) . '/assignments/estimate-cost', json: [
            'method' => 'virtual',
            'signers' => array_map(
                static fn (Recipient $recipient): array => $recipient->terms(),
                $recipients,
            ),
        ]);
        return ['estimate' => $estimate, 'quoteId' => Quote::fingerprint($estimate)];
    }

    /**
     * @param list<array<string, mixed>> $input
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function send(
        string $documentId,
        array $input,
        bool $ordered,
        string $quoteId,
        string $message,
        int $expiresInDays,
        array $contract,
    ): array {
        if ($expiresInDays < 1 || $expiresInDays > 365) {
            throw new AssinafyException('The signing deadline must be 1 to 365 days.');
        }
        if (mb_strlen($message) > 2000) {
            throw new AssinafyException('The signing message must be at most 2,000 characters.');
        }
        $contractTerms = $contract === [] ? [] : ContractInput::sanitize($contract);
        $recipients = $this->recipients($input, $ordered);
        $current = $this->estimate($documentId, $input, $ordered);
        if (!hash_equals($current['quoteId'], $quoteId)) {
            throw new AssinafyException('The Assinafy price changed. Review the new estimate before sending.', 409, 'quote_changed');
        }
        if (($current['estimate']['has_sufficient_resources'] ?? false) !== true) {
            throw new AssinafyException('The Assinafy workspace has insufficient resources.', 409, 'insufficient_resources');
        }

        $signers = [];
        foreach ($recipients as $index => $recipient) {
            $signers[] = ['id' => $this->resolveSigner($recipient)] + $recipient->terms($ordered ? $index + 1 : null);
        }
        $assignment = $this->request('POST', '/documents/' . rawurlencode($documentId) . '/assignments', json: [
            'method' => 'virtual',
            'signers' => $signers,
            'message' => trim($message),
            'expires_at' => gmdate('c', $this->timeFactory->getTime() + $expiresInDays * 86400),
        ], timeout: 180);

        $result = ['assignment' => $assignment, 'contract' => null, 'contractError' => null];
        if ($contractTerms !== []) {
            try {
                $result['contract'] = $this->writeContract($documentId, $contractTerms);
            } catch (AssinafyException $error) {
                // The paid send already happened; report the separate CLM failure without implying it did not.
                $result['contractError'] = $error->getMessage();
            }
        }
        return $result;
    }

    /** @return list<mixed> */
    public function documents(string $search, string $status, int $page): array
    {
        $query = ['search' => trim($search), 'sort' => '-updated_at', 'page' => max(1, $page), 'per-page' => 50];
        if ($status !== '') {
            $query['status'] = $status;
        }
        return $this->list($this->accountPath('/documents'), $query);
    }

    /** @return list<mixed> */
    public function contracts(array $filters): array
    {
        $allowed = array_intersect_key($filters, array_flip([
            'status',
            'search',
            'counterparty',
            'end_date_from',
            'end_date_to',
            'ending_within_days',
            'external_system',
            'external_id',
            'sort',
            'page',
            'per-page',
        ]));
        $allowed['expand'] = 'document';
        $allowed['page'] = max(1, (int) ($allowed['page'] ?? 1));
        $allowed['per-page'] = min(100, max(1, (int) ($allowed['per-page'] ?? 25)));
        return $this->list($this->accountPath('/contracts'), $allowed);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function saveContract(string $documentId, array $input): array
    {
        $terms = ContractInput::sanitize($input);
        if (!isset($terms['updated_at'])) {
            throw new AssinafyException('Contract edits require the current update timestamp.', 400, 'updated_at_required');
        }
        return $this->writeContract($documentId, $terms);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function renewContract(string $documentId, array $input): array
    {
        $body = [];
        if (isset($input['term']) && (is_int($input['term']) || ctype_digit((string) $input['term'])) && (int) $input['term'] >= 1) {
            $body['term'] = (int) $input['term'];
        } elseif (isset($input['term'])) {
            throw new AssinafyException('Renewal requires the current contract term.');
        }
        $updatedAt = trim((string) ($input['updated_at'] ?? ''));
        if ($updatedAt !== '') {
            $body += ContractInput::sanitize(['updated_at' => $updatedAt], true);
        }
        if ($body === []) {
            throw new AssinafyException('Renewal requires the current contract term.');
        }
        return $this->request('POST', $this->accountPath('/documents/' . rawurlencode($documentId) . '/contract/renew'), json: $body);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function terminateContract(string $documentId, array $input): array
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if (mb_strlen($reason) > 500) {
            throw new AssinafyException('Termination reasons must be at most 500 characters.');
        }
        $body = $reason === '' ? [] : ['reason' => $reason];
        $updatedAt = trim((string) ($input['updated_at'] ?? ''));
        if ($updatedAt !== '') {
            $body += ContractInput::sanitize(['updated_at' => $updatedAt], true);
        }
        return $this->request('POST', $this->accountPath('/documents/' . rawurlencode($documentId) . '/contract/terminate'), json: $body);
    }

    /** @return list<mixed> */
    public function webhookEventTypes(): array
    {
        return $this->list('/webhooks/event-types');
    }

    /** @return list<mixed> */
    public function webhookEndpoints(): array
    {
        return $this->list($this->accountPath('/webhooks/endpoints'));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createWebhookEndpoint(array $input): array
    {
        return $this->request('POST', $this->accountPath('/webhooks/endpoints'), json: WebhookInput::sanitize($input));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function updateWebhookEndpoint(string $endpointId, array $input): array
    {
        return $this->request('PUT', $this->accountPath('/webhooks/endpoints/' . rawurlencode($endpointId)), json: WebhookInput::sanitize($input, true));
    }

    public function deleteWebhookEndpoint(string $endpointId): void
    {
        $this->request('DELETE', $this->accountPath('/webhooks/endpoints/' . rawurlencode($endpointId)));
    }

    public function rotateWebhookSecret(string $endpointId): string
    {
        $data = $this->request('POST', $this->accountPath('/webhooks/endpoints/' . rawurlencode($endpointId) . '/secret/rotate'));
        $secret = $data['secret'] ?? null;
        if (!is_string($secret) || !str_starts_with($secret, 'whsec_')) {
            throw new AssinafyException('Assinafy did not return the new webhook secret.', 502, 'invalid_response');
        }
        return $secret;
    }

    /** @return list<mixed> */
    public function webhookDeliveries(array $filters): array
    {
        $allowed = array_intersect_key($filters, array_flip(['endpoint_id', 'event', 'delivered', 'from', 'to', 'page', 'per-page']));
        $allowed['page'] = max(1, (int) ($allowed['page'] ?? 1));
        $allowed['per-page'] = min(100, max(1, (int) ($allowed['per-page'] ?? 20)));
        return $this->list($this->accountPath('/webhooks'), $allowed);
    }

    /** @return array<array-key, mixed> */
    public function retryWebhookDelivery(string $deliveryId): array
    {
        return $this->request('POST', $this->accountPath('/webhooks/' . rawurlencode($deliveryId) . '/retry'));
    }

    /** @param array<string, mixed> $terms @return array<string, mixed> */
    private function writeContract(string $documentId, array $terms): array
    {
        return $this->request('PUT', $this->accountPath('/documents/' . rawurlencode($documentId) . '/contract'), json: $terms);
    }

    /** @return array<string, mixed> */
    private function upload(File $file): array
    {
        return $this->perform('POST', $this->accountPath('/documents'), function (string $token) use ($file): array {
            $stream = $file->fopen('r');
            if (!is_resource($stream)) {
                throw new AssinafyException('The Nextcloud file could not be read.', 500, 'file_read_failed');
            }
            return [
                'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token],
                'multipart' => [[
                    'name' => 'file',
                    'contents' => $stream,
                    'filename' => $file->getName(),
                    'headers' => ['Content-Type' => 'application/pdf'],
                ]],
                'timeout' => 180,
                'connect_timeout' => 15,
                'http_errors' => false,
            ];
        });
    }

    /** @param array<string, scalar|null> $query @return list<mixed> */
    private function list(string $path, array $query = []): array
    {
        $data = $this->request('GET', $path, $query);
        if (!array_is_list($data)) {
            throw new AssinafyException('Assinafy returned an invalid list.', 502, 'invalid_response');
        }
        return $data;
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>|list<mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $json = null, int $timeout = 30): array
    {
        $url = OAuthService::API_BASE . $path;
        $filtered = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
        if ($filtered !== []) {
            $url .= '?' . http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
        }
        return $this->perform($method, $url, static function (string $token) use ($json, $timeout): array {
            $options = [
                'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token],
                'timeout' => $timeout,
                'connect_timeout' => 10,
                'http_errors' => false,
            ];
            if ($json !== null) {
                $options['headers']['Content-Type'] = 'application/json';
                $options['body'] = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            return $options;
        });
    }

    /** @param callable(string): array<string, mixed> $options @return array<string, mixed>|list<mixed> */
    private function perform(string $method, string $url, callable $options): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $attempt === 0 ? $this->oauth->accessToken() : (string) $this->oauth->refresh(true)['accessToken'];
            $requestOptions = $options($token);
            try {
                $response = $this->clients->newClient()->request($method, $url, $requestOptions);
            } catch (Throwable $error) {
                throw new AssinafyException('Assinafy could not be reached.', 503, 'transport_error');
            } finally {
                foreach ($requestOptions['multipart'] ?? [] as $part) {
                    if (is_array($part) && is_resource($part['contents'] ?? null)) {
                        fclose($part['contents']);
                    }
                }
            }
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $decoded = $this->decode($body);
            if ($status === 401 && $attempt === 0) {
                continue;
            }
            if ($status === 401) {
                $this->oauth->invalidate();
                throw new AssinafyException('Your Assinafy session expired. Connect again.', 401, 'session_expired');
            }
            if ($status < 200 || $status >= 300) {
                $message = is_array($decoded) && is_string($decoded['message'] ?? null)
                    ? $decoded['message']
                    : 'Assinafy rejected the request.';
                throw new AssinafyException($message, $status >= 400 && $status < 600 ? $status : 502, 'api_error');
            }
            $data = is_array($decoded) && array_key_exists('status', $decoded) && array_key_exists('data', $decoded)
                ? $decoded['data']
                : $decoded;
            if (!is_array($data)) {
                throw new AssinafyException('Assinafy returned an invalid response.', 502, 'invalid_response');
            }
            return $data;
        }
        throw new AssinafyException('Your Assinafy session expired. Connect again.', 401, 'session_expired');
    }

    /** @return array<string, mixed>|list<mixed>|null */
    private function decode(string $body): ?array
    {
        if ($body === '') {
            return [];
        }
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }

    /** @param list<array<string, mixed>> $input @return list<Recipient> */
    private function recipients(array $input, bool $ordered): array
    {
        if ($input === [] || count($input) > 50) {
            throw new AssinafyException('Add between 1 and 50 recipients.');
        }
        $recipients = [];
        foreach ($input as $recipient) {
            if (!is_array($recipient)) {
                throw new AssinafyException('Each recipient must be an object.');
            }
            $recipients[] = Recipient::fromArray($recipient);
        }
        Recipient::validateSigningOrder($recipients, $ordered);
        return $recipients;
    }

    private function resolveSigner(Recipient $recipient): string
    {
        $matches = $this->list($this->accountPath('/signers'), ['search' => $recipient->email, 'per-page' => 50, 'page' => 1]);
        foreach ($matches as $match) {
            if (!is_array($match) || strtolower(trim((string) ($match['email'] ?? ''))) !== $recipient->email) {
                continue;
            }
            if (trim((string) ($match['full_name'] ?? '')) !== $recipient->name) {
                throw new AssinafyException('A saved Assinafy contact uses this email with a different name.', 409, 'contact_conflict');
            }
            if ($recipient->phone !== null && $this->phone((string) ($match['whatsapp_phone_number'] ?? '')) !== $recipient->phone) {
                throw new AssinafyException('Update the saved Assinafy contact with the intended WhatsApp number first.', 409, 'contact_conflict');
            }
            if ($recipient->taxId !== null && TaxId::normalize((string) ($match['government_id'] ?? '')) !== $recipient->taxId) {
                throw new AssinafyException('Update the saved Assinafy contact with the intended CPF or CNPJ first.', 409, 'contact_conflict');
            }
            return $this->id($match, 'signer');
        }

        return $this->id(
            $this->request('POST', $this->accountPath('/signers'), json: $recipient->contact()),
            'signer',
        );
    }

    /** @param array<array-key, mixed> $data */
    private function id(array $data, string $resource): string
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new AssinafyException("Assinafy returned an invalid {$resource}.", 502, 'invalid_response');
        }
        return $id;
    }

    private function accountPath(string $suffix): string
    {
        $session = $this->oauth->session();
        $accountId = is_array($session) ? ($session['accountId'] ?? null) : null;
        if (!is_string($accountId) || $accountId === '') {
            throw new AssinafyException('Connect your Assinafy account first.', 401, 'not_connected');
        }
        return '/accounts/' . rawurlencode($accountId) . $suffix;
    }

    private function phone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits === '' ? null : '+' . $digits;
    }
}
