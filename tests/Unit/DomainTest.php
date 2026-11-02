<?php

declare(strict_types=1);

namespace OCA\Assinafy\Tests\Unit;

use OCA\Assinafy\Domain\ContractInput;
use OCA\Assinafy\Domain\Quote;
use OCA\Assinafy\Domain\Recipient;
use OCA\Assinafy\Domain\TaxId;
use OCA\Assinafy\Domain\WebhookInput;
use OCA\Assinafy\Exception\AssinafyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}> */
    public static function recipientMethods(): iterable
    {
        yield 'email' => ['email', [], ['verification_method' => 'Email', 'notification_methods' => ['Email']]];
        yield 'whatsapp' => ['whatsapp', ['phone' => '+55 11 99999-9999'], ['verification_method' => 'Whatsapp', 'notification_methods' => ['Whatsapp']]];
        yield 'biometric' => ['biometric', ['taxId' => '111.444.777-35'], ['verification_method' => 'IdDocumentFaceMatchBasic', 'notification_methods' => ['Email']]];
        yield 'biometric with WhatsApp notice' => ['biometric', ['notification' => 'whatsapp', 'phone' => '+55 11 99999-9999', 'taxId' => '111.444.777-35'], ['verification_method' => 'IdDocumentFaceMatchBasic', 'notification_methods' => ['Whatsapp']]];
        yield 'certificate' => ['certificate', ['taxId' => '11.222.333/0001-81'], ['verification_method' => 'DigitalCertificate', 'notification_methods' => ['Email']]];
        yield 'certificate with WhatsApp notice' => ['certificate', ['notification' => 'whatsapp', 'phone' => '+55 11 99999-9999', 'taxId' => '12.ABC.345/01DE-35'], ['verification_method' => 'DigitalCertificate', 'notification_methods' => ['Whatsapp']]];
    }

    /** @param array<string, mixed> $extra @param array<string, mixed> $expected */
    #[Test]
    #[DataProvider('recipientMethods')]
    public function itMapsSupportedSignatureMethods(string $method, array $extra, array $expected): void
    {
        $recipient = Recipient::fromArray(['name' => 'Example Signer', 'email' => 'signer@example.invalid', 'method' => $method] + $extra);

        self::assertSame($expected, $recipient->terms());
        self::assertSame($expected + ['step' => 2], $recipient->terms(2));
    }

    #[Test]
    public function itValidatesCpfAndCnpjChecksums(): void
    {
        self::assertTrue(TaxId::isValid('111.444.777-35'));
        self::assertTrue(TaxId::isValid('11.222.333/0001-81'));
        self::assertTrue(TaxId::isValid('12.ABC.345/01DE-35'));
        self::assertSame('12ABC34501DE35', TaxId::normalize('12.abc.345/01de-35'));
        self::assertFalse(TaxId::isValid('111.111.111-11'));
        self::assertFalse(TaxId::isValid('11.222.333/0001-82'));
        self::assertFalse(TaxId::isCpf('12.ABC.345/01DE-35'));
    }

    #[Test]
    public function itRejectsMissingMethodRequirements(): void
    {
        $this->expectException(AssinafyException::class);
        Recipient::fromArray(['name' => 'Example Signer', 'email' => 'signer@example.invalid', 'method' => 'whatsapp']);
    }

    #[Test]
    public function verificationAndNotificationMustMatch(): void
    {
        $this->expectException(AssinafyException::class);
        Recipient::fromArray([
            'name' => 'Example Signer',
            'email' => 'signer@example.invalid',
            'method' => 'email',
            'notification' => 'whatsapp',
            'phone' => '+55 11 99999-9999',
        ]);
    }

    #[Test]
    public function biometricIdentityRejectsCnpj(): void
    {
        $this->expectException(AssinafyException::class);
        Recipient::fromArray([
            'name' => 'Example Signer',
            'email' => 'signer@example.invalid',
            'method' => 'biometric',
            'taxId' => '11.222.333/0001-81',
        ]);
    }

    #[Test]
    public function certificateCoSignersRequireOrderedSigning(): void
    {
        $recipients = [
            Recipient::fromArray(['name' => 'Certificate Signer', 'email' => 'certificate@example.invalid', 'method' => 'certificate', 'taxId' => '11.222.333/0001-81']),
            Recipient::fromArray(['name' => 'Email Signer', 'email' => 'email@example.invalid', 'method' => 'email']),
        ];
        Recipient::validateSigningOrder($recipients, true);

        $this->expectException(AssinafyException::class);
        Recipient::validateSigningOrder($recipients, false);
    }

    #[Test]
    public function quoteFingerprintIncludesEveryChargeComponent(): void
    {
        $first = [
            'documents' => 1,
            'credits' => 0.9,
            'needs_extra_document' => true,
            'extra_document_cost' => 1.0,
            'total_credits' => 1.9,
            'has_sufficient_resources' => true,
            'breakdown' => [['code' => 'NotificationWhatsapp', 'name' => 'WhatsApp', 'quantity' => 2, 'cost' => 0.9]],
        ];
        $reordered = $first;
        $reordered['breakdown'] = array_reverse($first['breakdown']);
        $changed = $first;
        $changed['breakdown'][0]['quantity'] = 3;
        $allowanceChanged = $first;
        $allowanceChanged['needs_extra_document'] = false;

        self::assertSame(Quote::fingerprint($first), Quote::fingerprint($reordered));
        self::assertNotSame(Quote::fingerprint($first), Quote::fingerprint($changed));
        self::assertNotSame(Quote::fingerprint($first), Quote::fingerprint($allowanceChanged));
    }

    #[Test]
    public function quoteRequiresTheDocumentedTotal(): void
    {
        $this->expectException(AssinafyException::class);
        Quote::fingerprint(['has_sufficient_resources' => true]);
    }

    #[Test]
    public function itSanitizesContractTerms(): void
    {
        self::assertSame([
            'counterparty_name' => 'Example Company',
            'start_date' => '2026-01-01',
            'end_date' => null,
            'renewal_term_months' => 12,
            'value' => 0.0,
            'currency' => 'BRL',
            'auto_renew' => true,
        ], ContractInput::sanitize([
            'counterparty_name' => ' Example Company ',
            'start_date' => '2026-01-01',
            'end_date' => '',
            'renewal_term_months' => '12',
            'value' => '0',
            'currency' => 'brl',
            'auto_renew' => 'true',
        ]));
    }

    #[Test]
    public function contractNoticeMayStartOnTheEndDate(): void
    {
        self::assertSame(['notice_period_days' => 0], ContractInput::sanitize(['notice_period_days' => 0]));
    }

    #[Test]
    public function contractTaxpayerIdsUseTheApiFormat(): void
    {
        self::assertSame(
            ['counterparty_taxpayer_id' => '12ABC34501DE35'],
            ContractInput::sanitize(['counterparty_taxpayer_id' => '12.abc.345/01de-35']),
        );
    }

    #[Test]
    public function contractUpdateTimestampMustUseTheApiFormat(): void
    {
        self::assertSame(
            ['updated_at' => '2026-10-31T12:00:00.123-03:00'],
            ContractInput::sanitize(['updated_at' => '2026-10-31T12:00:00.123-03:00'], true),
        );

        $this->expectException(AssinafyException::class);
        ContractInput::sanitize(['updated_at' => 'tomorrow'], true);
    }

    #[Test]
    public function itRejectsPrivateOrCredentialedWebhookUrls(): void
    {
        foreach (['https://127.0.0.1/hook', 'https://user@example.invalid/hook', 'http://hooks.example.invalid'] as $url) {
            try {
                WebhookInput::sanitize(['url' => $url, 'email' => 'webhooks@example.invalid', 'events' => ['document_signed']]);
                self::fail('Expected invalid webhook URL to be rejected.');
            } catch (AssinafyException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function itAcceptsACompleteWebhookEndpoint(): void
    {
        self::assertSame([
            'url' => 'https://hooks.example.invalid/assinafy',
            'email' => 'webhooks@example.invalid',
            'events' => ['document_signed'],
            'name' => 'Production hook',
            'is_active' => true,
            'signing_enabled' => true,
        ], WebhookInput::sanitize([
            'url' => 'https://hooks.example.invalid/assinafy',
            'email' => 'webhooks@example.invalid',
            'events' => ['document_signed', 'document_signed'],
            'name' => 'Production hook',
            'is_active' => true,
            'signing_enabled' => true,
        ]));
    }

    #[Test]
    public function itRejectsNonPublicIpv6WebhookUrls(): void
    {
        foreach (['https://[::1]/hook', 'https://[fd00::1]/hook', 'https://[fe80::1]/hook'] as $url) {
            try {
                WebhookInput::sanitize(['url' => $url, 'email' => 'webhooks@example.invalid', 'events' => ['document_signed']]);
                self::fail('Expected non-public IPv6 URL to be rejected.');
            } catch (AssinafyException) {
                self::assertTrue(true);
            }
        }
    }
}
