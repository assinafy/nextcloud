<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use OCA\Assinafy\Domain\ContractInput;
use OCA\Assinafy\Domain\OAuthError;
use OCA\Assinafy\Domain\Quote;
use OCA\Assinafy\Domain\Recipient;
use OCA\Assinafy\Domain\TaxId;
use OCA\Assinafy\Domain\WebhookInput;
use OCA\Assinafy\Exception\AssinafyException;

$tests = 0;
$check = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (callable $operation, string $message) use (&$tests): void {
    $tests++;
    try {
        $operation();
    } catch (AssinafyException) {
        return;
    }
    throw new RuntimeException($message);
};

$check(TaxId::isValid('111.444.777-35'), 'Valid CPF rejected.');
$check(TaxId::isValid('11.222.333/0001-81'), 'Valid CNPJ rejected.');
$check(TaxId::isValid('12.ABC.345/01DE-35'), 'Valid alphanumeric CNPJ rejected.');
$check(TaxId::normalize('12.abc.345/01de-35') === '12ABC34501DE35', 'CNPJ normalization changed its identity.');
$check(!TaxId::isValid('111.111.111-11'), 'Repeated CPF accepted.');

$methods = [
    'email' => 'Email',
    'whatsapp' => 'Whatsapp',
    'biometric' => 'IdDocumentFaceMatchBasic',
    'certificate' => 'DigitalCertificate',
];
foreach ($methods as $method => $apiMethod) {
    $recipient = Recipient::fromArray([
        'name' => 'Example Signer',
        'email' => 'signer@example.invalid',
        'method' => $method,
        'phone' => '+55 11 99999-9999',
        'taxId' => '111.444.777-35',
    ]);
    $check($recipient->terms()['verification_method'] === $apiMethod, "Incorrect mapping for {$method}.");
}
$check(
    Recipient::fromArray([
        'name' => 'Example Signer',
        'email' => 'signer@example.invalid',
        'method' => 'email',
        'phone' => '+55 11 99999-9999',
        'taxId' => '111.444.777-35',
    ])->contact() === ['full_name' => 'Example Signer', 'email' => 'signer@example.invalid'],
    'Unused recipient identity data was forwarded.',
);
$rejects(
    static fn () => Recipient::fromArray(['name' => 'Example Signer', 'email' => 'signer@example.invalid', 'method' => 'whatsapp']),
    'WhatsApp without a phone was accepted.',
);
$rejects(
    static fn () => Recipient::fromArray([
        'name' => 'Example Signer',
        'email' => 'signer@example.invalid',
        'method' => 'email',
        'notification' => 'whatsapp',
        'phone' => '+55 11 99999-9999',
    ]),
    'Email verification with a WhatsApp notification was accepted.',
);
$check(
    Recipient::fromArray([
        'name' => 'Example Signer',
        'email' => 'signer@example.invalid',
        'method' => 'biometric',
        'notification' => 'whatsapp',
        'phone' => '+55 11 99999-9999',
        'taxId' => '111.444.777-35',
    ])->terms()['notification_methods'] === ['Whatsapp'],
    'Biometric WhatsApp notification was not mapped.',
);
$check(
    Recipient::fromArray([
        'name' => 'Example Signer',
        'email' => 'signer@example.invalid',
        'method' => 'certificate',
        'taxId' => '12.ABC.345/01DE-35',
    ])->contact()['government_id'] === '12ABC34501DE35',
    'Alphanumeric CNPJ was not forwarded.',
);
$rejects(
    static fn () => Recipient::fromArray([
        'name' => 'Example Signer',
        'email' => 'signer@example.invalid',
        'method' => 'biometric',
        'taxId' => '11.222.333/0001-81',
    ]),
    'Biometric CNPJ was accepted.',
);
$certificate = Recipient::fromArray([
    'name' => 'Certificate Signer',
    'email' => 'certificate@example.invalid',
    'method' => 'certificate',
    'taxId' => '11.222.333/0001-81',
]);
$email = Recipient::fromArray(['name' => 'Email Signer', 'email' => 'email@example.invalid', 'method' => 'email']);
Recipient::validateSigningOrder([$certificate, $email], true);
$rejects(static fn () => Recipient::validateSigningOrder([$certificate, $email], false), 'Parallel certificate co-signers were accepted.');

$estimate = [
    'documents' => 1,
    'credits' => 0.9,
    'total_credits' => 1.9,
    'needs_extra_document' => true,
    'extra_document_cost' => 1.0,
    'has_sufficient_resources' => true,
    'breakdown' => [['code' => 'NotificationWhatsapp', 'name' => 'WhatsApp', 'quantity' => 2, 'cost' => 0.9]],
];
$changed = $estimate;
$changed['breakdown'][0]['quantity'] = 3;
$check(Quote::fingerprint($estimate) !== Quote::fingerprint($changed), 'Quote change was not detected.');
$changed = $estimate;
$changed['needs_extra_document'] = false;
$check(Quote::fingerprint($estimate) !== Quote::fingerprint($changed), 'Document allowance change was not detected.');
$rejects(static fn () => Quote::fingerprint(['has_sufficient_resources' => true]), 'Estimate without a total was accepted.');

$contract = ContractInput::sanitize(['start_date' => '2026-01-01', 'currency' => 'brl', 'value' => '0']);
$check($contract === ['start_date' => '2026-01-01', 'value' => 0.0, 'currency' => 'BRL'], 'Contract terms were not normalized.');
$check(
    ContractInput::sanitize(['counterparty_taxpayer_id' => '12.abc.345/01de-35']) === ['counterparty_taxpayer_id' => '12ABC34501DE35'],
    'Contract CNPJ was not normalized.',
);
$rejects(static fn () => ContractInput::sanitize(['start_date' => '01/01/2026']), 'Invalid contract date accepted.');
$check(
    ContractInput::sanitize(['updated_at' => '2026-10-31T12:00:00Z'], true) === ['updated_at' => '2026-10-31T12:00:00Z'],
    'Valid contract update timestamp rejected.',
);
$rejects(static fn () => ContractInput::sanitize(['updated_at' => 'tomorrow'], true), 'Loose contract update timestamp accepted.');

$webhook = WebhookInput::sanitize([
    'url' => 'https://hooks.example.invalid/assinafy',
    'email' => 'webhooks@example.invalid',
    'events' => ['document_signed'],
]);
$check($webhook['events'] === ['document_signed'], 'Webhook event was not preserved.');
$rejects(
    static fn () => WebhookInput::sanitize(['url' => 'https://user@example.invalid/hook', 'email' => 'webhooks@example.invalid', 'events' => ['document_signed']]),
    'Credentialed webhook URL accepted.',
);
$rejects(
    static fn () => WebhookInput::sanitize(['url' => 'https://10.0.0.1/hook', 'email' => 'webhooks@example.invalid', 'events' => ['document_signed']]),
    'Private webhook URL accepted.',
);
$rejects(
    static fn () => WebhookInput::sanitize(['url' => 'https://[::1]/hook', 'email' => 'webhooks@example.invalid', 'events' => ['document_signed']]),
    'IPv6 loopback webhook URL accepted.',
);
$rejects(
    static fn () => WebhookInput::sanitize(['url' => 'https://[fd00::1]/hook', 'email' => 'webhooks@example.invalid', 'events' => ['document_signed']]),
    'IPv6 private webhook URL accepted.',
);

$check(OAuthError::message('access_denied') === 'Assinafy access was not granted.', 'Known OAuth callback error lost its message.');
$check(OAuthError::message('Your account is locked, email support@example.invalid') === '', 'Arbitrary callback error text was displayed.');
$check(OAuthError::code('invalid_oauth_response') === 'invalid_oauth_response', 'Known OAuth callback error code was replaced.');
$check(OAuthError::code('unexpected_failure') === 'connection_failed', 'Unknown OAuth failure code was passed to the page.');

require_once __DIR__ . '/ServiceChecks.php';
\OCA\Assinafy\Tests\runServiceChecks($check, $rejects);

fwrite(STDOUT, "{$tests} domain and service checks passed.\n");
