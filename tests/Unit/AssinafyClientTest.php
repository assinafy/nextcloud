<?php

declare(strict_types=1);

namespace OCA\Assinafy\Tests\Unit;

use OCA\Assinafy\Domain\Quote;
use OCA\Assinafy\Exception\AssinafyException;
use OCA\Assinafy\Service\AssinafyClient;
use OCA\Assinafy\Service\OAuthService;
use OCA\Assinafy\Tests\ServiceTestClient;
use OCA\Assinafy\Tests\ServiceTestClientService;
use OCA\Assinafy\Tests\ServiceTestResponse;
use OCA\Assinafy\Tests\ServiceTestTimeFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function OCA\Assinafy\Tests\serviceTestApiResponse;
use function OCA\Assinafy\Tests\serviceTestEstimate;
use function OCA\Assinafy\Tests\serviceTestOAuth;
use function OCA\Assinafy\Tests\serviceTestRecipients;
use function OCA\Assinafy\Tests\serviceTestSession;

require_once dirname(__DIR__) . '/ServiceChecks.php';

final class AssinafyClientTest extends TestCase
{
    #[Test]
    public function allVerificationAndNotificationPairsReachThePaidAssignment(): void
    {
        foreach ([['email', 'email', 'Email'], ['whatsapp', 'whatsapp', 'Whatsapp'], ['biometric', 'email', 'IdDocumentFaceMatchBasic'], ['biometric', 'whatsapp', 'IdDocumentFaceMatchBasic'], ['certificate', 'email', 'DigitalCertificate'], ['certificate', 'whatsapp', 'DigitalCertificate']] as [$method, $channel, $upstream]) {
            [$api, $http] = $this->client([
                $this->ready(), serviceTestApiResponse(serviceTestEstimate()), serviceTestApiResponse([]),
                serviceTestApiResponse(['id' => 'signer-test']), serviceTestApiResponse(['id' => 'assignment-test']),
            ]);
            $recipient = array_replace(serviceTestRecipients()[0], ['method' => $method, 'notification' => $channel, 'phone' => '+5511999999999', 'taxId' => '11144477735']);
            $result = $api->send('doc', [$recipient], false, Quote::fingerprint(serviceTestEstimate()), '', 30, []);
            self::assertSame('assignment-test', $result['assignment']['id']);
            $body = json_decode($http->requests[4][2]['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($upstream, $body['signers'][0]['verification_method']);
            self::assertSame([ucfirst($channel)], $body['signers'][0]['notification_methods']);
            self::assertSame(0, $body['max_total_credits']);
            $contact = json_decode($http->requests[3][2]['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(in_array($method, ['biometric', 'certificate'], true), isset($contact['government_id']));
            self::assertSame($channel === 'whatsapp', isset($contact['whatsapp_phone_number']));
        }
    }

    #[Test]
    public function paidSendPreservesPartialContractFailure(): void
    {
        [$api] = $this->client([
            $this->ready(), serviceTestApiResponse(serviceTestEstimate()), serviceTestApiResponse([]),
            serviceTestApiResponse(['id' => 'signer-test']), serviceTestApiResponse(['id' => 'assignment-test']),
            serviceTestApiResponse(null, 403),
        ]);
        $result = $api->send('doc', serviceTestRecipients(), false, Quote::fingerprint(serviceTestEstimate()), '', 30, ['counterparty_name' => 'Example Company']);
        self::assertSame('assignment-test', $result['assignment']['id']);
        self::assertNull($result['contract']);
        self::assertNotEmpty($result['contractError']);
    }

    #[Test]
    public function aCreditCeilingRefusalRequiresAnotherPriceReview(): void
    {
        [$api, $http] = $this->client([
            $this->ready(), serviceTestApiResponse(serviceTestEstimate()), serviceTestApiResponse([]),
            serviceTestApiResponse(['id' => 'signer-test']),
            new ServiceTestResponse(409, '{"status":409,"code":"CreditCeilingExceeded","data":null}'),
        ]);
        try {
            $api->send('doc', serviceTestRecipients(), false, Quote::fingerprint(serviceTestEstimate()), '', 30, []);
            self::fail('Credit ceiling refusal was ignored.');
        } catch (AssinafyException $error) {
            self::assertSame('quote_changed', $error->errorCode());
            self::assertCount(5, $http->requests);
        }
    }

    #[Test]
    public function envelopeReestimatesAndUsesOneCreditCeilingForAllDocuments(): void
    {
        [$api, $http] = $this->client([
            $this->ready(), $this->ready(), serviceTestApiResponse([]), serviceTestApiResponse(['id' => 'signer-test']),
            serviceTestApiResponse(serviceTestEstimate()), serviceTestApiResponse(['id' => 'envelope-test']),
        ]);
        $result = $api->sendEnvelope('Agreement', ['doc-one', 'doc-two'], serviceTestRecipients(), Quote::fingerprint(serviceTestEstimate()), 'Please sign.', 30);
        self::assertSame('envelope-test', $result['id']);
        self::assertSame(OAuthService::API_BASE . '/accounts/account-test/envelopes/estimate-cost', $http->requests[4][1]);
        $body = json_decode($http->requests[5][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['max_total_credits']);
        self::assertSame(['doc-one', 'doc-two'], array_column($body['documents'], 'id'));
        self::assertSame(['signer-test'], $body['documents'][1]['signer_ids']);
        self::assertArrayNotHasKey('step', $body['signers'][0]);
    }

    #[Test]
    public function envelopeRefusesChangedPricesAndNeverRetriesAnUncertainCreation(): void
    {
        foreach ([new RuntimeException('Synthetic connection loss'), serviceTestApiResponse(null, 500)] as $failure) {
            [$api, $http] = $this->client([
                $this->ready(), serviceTestApiResponse([]), serviceTestApiResponse(['id' => 'signer-test']),
                serviceTestApiResponse(serviceTestEstimate()), $failure,
            ]);
            try {
                $api->sendEnvelope('Agreement', ['doc-one'], serviceTestRecipients(), Quote::fingerprint(serviceTestEstimate()), '', 30);
                self::fail('Uncertain creation was accepted.');
            } catch (AssinafyException) {
                self::assertCount(5, $http->requests);
            }
        }
        [$api, $http] = $this->client([
            $this->ready(), serviceTestApiResponse([]), serviceTestApiResponse(['id' => 'signer-test']), serviceTestApiResponse(serviceTestEstimate()),
        ]);
        try {
            $api->sendEnvelope('Agreement', ['doc-one'], serviceTestRecipients(), 'old-price', '', 30);
            self::fail('Changed price was accepted.');
        } catch (AssinafyException $error) {
            self::assertSame('quote_changed', $error->errorCode());
            self::assertCount(4, $http->requests);
        }
    }

    #[Test]
    public function envelopeRejectsInvalidDocumentsAndCertificateCosignersBeforeHttp(): void
    {
        [$api, $http] = $this->client([]);
        foreach ([[], ['doc', 'doc'], [['nested']], array_fill(0, 21, 'doc')] as $ids) {
            try {
                $api->estimateEnvelope('Agreement', $ids, serviceTestRecipients(), 30);
                self::fail('Invalid documents were accepted.');
            } catch (AssinafyException) {
                self::assertSame([], $http->requests);
            }
        }
        $recipients = serviceTestRecipients();
        $recipients[] = ['name' => 'Certificate Signer', 'email' => 'certificate@example.invalid', 'method' => 'certificate', 'taxId' => '11222333000181'];
        $this->expectException(AssinafyException::class);
        $api->estimateEnvelope('Agreement', ['doc'], $recipients, 30);
    }

    #[Test]
    public function webhookLifecycleUsesTheDocumentedMethodsAndTransientSecret(): void
    {
        [$api, $http] = $this->client([
            serviceTestApiResponse([['id' => 'document_ready']]), serviceTestApiResponse([]),
            serviceTestApiResponse(['id' => 'endpoint', 'secret' => 'whsec_synthetic']), serviceTestApiResponse(['id' => 'endpoint']),
            serviceTestApiResponse(['secret' => 'whsec_synthetic_rotated']), serviceTestApiResponse([]),
            serviceTestApiResponse(['id' => 'retry']), serviceTestApiResponse([]),
        ]);
        self::assertSame('document_ready', $api->webhookEventTypes()[0]['id']);
        self::assertSame([], $api->webhookEndpoints());
        self::assertSame('whsec_synthetic', $api->createWebhookEndpoint(['name' => 'Example', 'url' => 'https://hooks.example.invalid/assinafy', 'email' => 'hooks@example.invalid', 'events' => ['document_ready']])['secret']);
        $api->updateWebhookEndpoint('endpoint', ['is_active' => false]);
        self::assertSame('whsec_synthetic_rotated', $api->rotateWebhookSecret('endpoint'));
        $api->webhookDeliveries(['per-page' => 100, 'delivered' => 'false']);
        self::assertSame('retry', $api->retryWebhookDelivery('delivery')['id']);
        $api->deleteWebhookEndpoint('endpoint');
        self::assertSame(['GET', 'GET', 'POST', 'PUT', 'POST', 'GET', 'POST', 'DELETE'], array_column($http->requests, 0));
        self::assertStringContainsString('per-page=50', $http->requests[5][1]);
        self::assertStringEndsWith('/webhooks/delivery/retry', $http->requests[6][1]);
    }

    #[Test]
    public function envelopeReadAndCancellationUseAccountScopedRoutes(): void
    {
        [$api, $http] = $this->client([serviceTestApiResponse([]), serviceTestApiResponse([['id' => 'doc', 'cancelled' => true]])]);
        self::assertSame([], $api->envelopes(2));
        self::assertTrue($api->cancelEnvelope('envelope-test')[0]['cancelled']);
        self::assertSame(OAuthService::API_BASE . '/accounts/account-test/envelopes?page=2&per-page=20', $http->requests[0][1]);
        self::assertSame('PUT', $http->requests[1][0]);
    }

    #[Test]
    public function contractLifecycleRejectsNestedInputBeforeHttp(): void
    {
        [$api, $http] = $this->client([]);
        foreach ([['renewContract', ['term' => []]], ['renewContract', ['term' => 1, 'updated_at' => []]], ['terminateContract', ['reason' => []]], ['terminateContract', ['updated_at' => []]]] as [$method, $input]) {
            try {
                $api->$method('doc', $input);
                self::fail('Nested contract input was accepted.');
            } catch (AssinafyException) {
                self::assertSame([], $http->requests);
            }
        }
    }

    /** @return array{AssinafyClient, ServiceTestClient} */
    private function client(array $responses): array
    {
        $http = new ServiceTestClient($responses);
        $clients = new ServiceTestClientService($http);
        [$oauth] = serviceTestOAuth($clients, serviceTestSession());
        return [new AssinafyClient($oauth, $clients, new ServiceTestTimeFactory()), $http];
    }

    private function ready(): ServiceTestResponse
    {
        return serviceTestApiResponse(['id' => 'doc', 'status' => 'metadata_ready', 'assignment' => null]);
    }
}
