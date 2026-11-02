<?php

declare(strict_types=1);

namespace OCA\Assinafy\Domain;

use OCA\Assinafy\Exception\AssinafyException;

final class Recipient
{
    private const METHODS = [
        'email' => 'Email',
        'whatsapp' => 'Whatsapp',
        'biometric' => 'IdDocumentFaceMatchBasic',
        'certificate' => 'DigitalCertificate',
    ];
    private const NOTIFICATIONS = [
        'email' => 'Email',
        'whatsapp' => 'Whatsapp',
    ];

    private function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $method,
        public readonly string $notification,
        public readonly ?string $phone,
        public readonly ?string $taxId,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $method = (string) ($input['method'] ?? '');
        $notification = (string) ($input['notification'] ?? ($method === 'whatsapp' ? 'whatsapp' : 'email'));
        $phone = $notification === 'whatsapp' ? self::phone((string) ($input['phone'] ?? '')) : null;
        $taxId = in_array($method, ['biometric', 'certificate'], true)
            ? TaxId::normalize((string) ($input['taxId'] ?? '')) ?: null
            : null;

        if ($name === '' || mb_strlen($name) > 255) {
            throw new AssinafyException('Recipient names must contain 1 to 255 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw new AssinafyException('Enter a valid recipient email address.');
        }
        if (!isset(self::METHODS[$method])) {
            throw new AssinafyException('Choose a supported signature method.');
        }
        if (!isset(self::NOTIFICATIONS[$notification])) {
            throw new AssinafyException('Choose a supported notification method.');
        }
        if (($method === 'email' && $notification !== 'email') || ($method === 'whatsapp' && $notification !== 'whatsapp')) {
            throw new AssinafyException('Email and WhatsApp verification must use their matching notification method.');
        }
        if ($notification === 'whatsapp' && $phone === null) {
            throw new AssinafyException('WhatsApp notifications need an international phone number.');
        }
        if ($method === 'biometric' && !TaxId::isCpf($taxId ?? '')) {
            throw new AssinafyException('Biometric signatures need a valid CPF.');
        }
        if ($method === 'certificate' && !TaxId::isValid($taxId ?? '')) {
            throw new AssinafyException('Certificate signatures need a valid CPF or CNPJ.');
        }

        return new self($name, strtolower($email), $method, $notification, $phone, $taxId);
    }

    /** @return array{verification_method: string, notification_methods: list<string>, step?: int} */
    public function terms(?int $step = null): array
    {
        return [
            'verification_method' => self::METHODS[$this->method],
            'notification_methods' => [self::NOTIFICATIONS[$this->notification]],
        ] + ($step === null ? [] : ['step' => $step]);
    }

    /** @param list<self> $recipients */
    public static function validateSigningOrder(array $recipients, bool $ordered): void
    {
        if (!$ordered && count($recipients) > 1) {
            foreach ($recipients as $recipient) {
                if ($recipient->method === 'certificate') {
                    throw new AssinafyException('Use ordered signing when a digital-certificate signer has co-signers.');
                }
            }
        }
    }

    /** @return array<string, string> */
    public function contact(): array
    {
        return array_filter([
            'full_name' => $this->name,
            'email' => $this->email,
            'whatsapp_phone_number' => $this->phone,
            'government_id' => $this->taxId,
        ], static fn (?string $value): bool => $value !== null);
    }

    private static function phone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return preg_match('/^[1-9]\d{9,14}$/', $digits) === 1 ? '+' . $digits : null;
    }
}
