# Changelog

All notable changes to this project are documented in this file. The format follows Keep a Changelog and the project uses Semantic Versioning.

## [0.3.0] - 2026-11-02

First public release.

### Added

- Send PDF files from the user's Nextcloud storage to Assinafy for signature with email, WhatsApp, biometric identity (CPF), or ICP-Brasil A1/A3 digital-certificate (CPF or CNPJ) verification, parallel or ordered signing, a message, and a deadline.
- Price review before sending; the server re-estimates immediately before a paid send and stops when any charge changed or resources are insufficient.
- Recent document status, contract lifecycle terms with editing, renewal, and termination, and webhook endpoint management with delivery history, retry, and signing-secret rotation.
- Per-user OAuth 2.0 connection with PKCE to exactly one Assinafy workspace. Tokens stay encrypted on the Nextcloud server, refresh tokens rotate, and concurrent requests share a single refresh.
- Nextcloud 31–35 on PHP 8.3–8.5, in English and Brazilian Portuguese.

## [0.2.0] - 2026-10-31

### Added

- Email or WhatsApp notification selection for biometric and digital-certificate signers.
- Alphanumeric CNPJ validation and normalization for contacts and contract terms.

### Fixed

- Biometric CPF requirements and digital-certificate signing-step validation now match the Assinafy assignment rules.
- OAuth completion, refresh, invalidation, and disconnect now serialize per user and revoke an unused rotating grant after a failed connection.
- Sensitive OAuth session storage uses `IUserConfig` on Nextcloud 32–35 and authenticated encryption through `IConfig` on Nextcloud 31, with migration on read.
- Standalone contract edits require the current `updated_at` timestamp, while optional terms are validated before assignment and initial creation remains timestamp-free.
- Invalid OAuth callbacks preserve the valid pending attempt, and ordered estimate payloads omit assignment-only signing steps.
- Non-public IPv6 webhook destinations are rejected before provider submission.
- Successful sends and changed quotes leave the send action disabled until a new workflow or estimate is available.
- Package archive names derive their version from the same committed metadata included in the archive.
- App metadata follows the App Store schema, which is enforced by the standard checks.

## [0.1.0] - 2026-10-31

### Added

- Nextcloud 31–35 application for sending user-owned PDF files through Assinafy.
- OAuth 2.0 Authorization Code with S256 PKCE, discovery, rotating refresh tokens, revocation, and encrypted per-user session storage.
- Email, WhatsApp, biometric identity, and digital-certificate signature methods with matching notification and recipient requirements.
- Cost estimation, resource checks, full quote identity validation, ordered signing, messages, and deadlines.
- Document listing and contract lifecycle term editing, renewal, and termination.
- Webhook event discovery, endpoint management, signing-secret rotation, delivery history, and manual retry.
- Responsive English interface and Brazilian Portuguese localization.
- Administration settings, automated checks, release packaging, and operator documentation.

[0.3.0]: https://github.com/assinafy/nextcloud/releases/tag/v0.3.0
[0.2.0]: https://github.com/assinafy/nextcloud/releases/tag/v0.2.0
[0.1.0]: https://github.com/assinafy/nextcloud/releases/tag/v0.1.0
