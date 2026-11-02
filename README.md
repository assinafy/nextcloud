# Assinafy for Nextcloud

Assinafy for Nextcloud sends PDF files stored in Nextcloud to the Assinafy API, tracks documents and contract terms, and manages outbound webhook endpoints. Each user connects one Assinafy workspace with OAuth 2.0 Authorization Code and PKCE; access and refresh tokens remain encrypted in that user's Nextcloud configuration.

## Requirements

- Nextcloud 31–35
- PHP 8.3, 8.4, or 8.5 with JSON, mbstring, and OpenSSL
- HTTPS on the Nextcloud server and on webhook destinations
- An Assinafy OAuth public client with the callback URL shown in Nextcloud Administration settings
- An Assinafy plan and member permissions that allow the requested signing, contract, and webhook operations

## Install

The installed directory must be named `assinafy`.

```sh
cd /path/to/nextcloud/apps
tar -xzf assinafy-0.3.0.tar.gz
php /path/to/nextcloud/occ app:enable assinafy
```

For an App Store release, sign the `assinafy` directory with the Nextcloud app-signing process before building the final archive. The archive must contain one top-level `assinafy/` directory.

## Configure OAuth

1. In Nextcloud, open **Administration settings → Assinafy**.
2. Copy the displayed callback URL.
3. Register a public OAuth client in Assinafy with that exact callback URL. Do not configure a client secret.
4. Grant these scopes: `documents:read`, `documents:write`, `templates:read`, `templates:write`, `account:read`, `webhooks:write`, `openid`, `profile`, `email`, and `offline_access`.
5. Paste the OAuth client ID into Nextcloud and save.

The integration discovers the authorization, token, revocation, and protected-resource endpoints. It accepts only the configured Assinafy issuer and HTTPS endpoints on the expected Assinafy origins.

## Complete workflow

1. Open **Assinafy** from the Nextcloud application navigation.
2. Select **Connect Assinafy**, authorize the fixed scope set, and choose exactly one Assinafy workspace.
3. In **Send**, browse the current user's Nextcloud files and select a PDF no larger than 25 MB.
4. Set the Assinafy document title and add 1–50 recipients.
5. Choose a verification path for each recipient:

   - **Email:** `Email` verification with email notification.
   - **WhatsApp:** `Whatsapp` verification with WhatsApp notification; an international phone number is required.
   - **Biometric identity:** `IdDocumentFaceMatchBasic` verification; a valid CPF is required.
   - **A1 / A3 digital certificate:** `DigitalCertificate` verification; a valid CPF or CNPJ is required, including the alphanumeric CNPJ format.

   Email and WhatsApp verification use the matching notification channel. Biometric and digital-certificate verification may notify by email or WhatsApp; WhatsApp notification requires an international phone number.

6. Choose parallel signing or ordered signing. Ordered recipients receive a one-based `step`. A digital-certificate signer with co-signers requires ordered signing so each certificate signer is alone in a signing step.
7. Optionally add the recipient message, signing deadline, and contract lifecycle terms.
8. Select **Review price**. The app uploads the PDF, waits for Assinafy metadata processing, and requests the current `virtual` assignment estimate.
9. Review total credits, balances, itemized charges, extra document use, and resource availability.
10. Select **Send for signature**. The server obtains a new estimate and rejects the send when any charge component changed or resources are insufficient.
11. Use **Documents** to read recent Assinafy document state.
12. Use **Contracts** to search tracked terms, edit them with the required current `updated_at` precondition, renew from the current term, or terminate with an optional reason.
13. Use **Webhooks** to create, pause, resume, delete, or rotate a webhook endpoint; inspect recent deliveries; and retry a failed delivery.

When webhook signing is enabled, store the returned `whsec_…` secret immediately. OAuth applications receive it only when Assinafy creates it or rotates it. Receivers must verify the Standard Webhooks headers against the unmodified request body and reject stale timestamps.

## Operation boundaries

- The file picker uses Nextcloud's user-scoped file API. A file ID outside the authenticated user's file tree is rejected.
- A send uses `method: "virtual"`; no signature fields are placed on the document.
- A1 and A3 are signer-side ICP-Brasil certificate media handled by the same `DigitalCertificate` method.
- Contact email conflicts, WhatsApp phone conflicts, and taxpayer-ID conflicts stop the send instead of changing an existing Assinafy contact silently.
- If signing succeeds but contract-term storage fails, the response reports the signing assignment and the separate contract error.
- Assinafy does not publish an atomic maximum-price or idempotency field for assignment creation. The app re-estimates immediately before creation; a connection loss during creation can still leave the final result unknown. Check **Documents** before starting another request from the same source file.
- Disconnect removes the local encrypted grant before attempting token revocation.

## Documentation

- [User guide](docs/USER.md)
- [Administration guide](docs/ADMIN.md)
- [Extension API](docs/API.md)
- [Development and release](docs/DEVELOPMENT.md)
- [Security policy](SECURITY.md)

The upstream references are the [Assinafy API](https://api.assinafy.com.br/v1/docs) and the [Nextcloud app developer guide](https://docs.nextcloud.com/server/stable/developer_manual/).

## Development

```sh
composer install
composer lint
composer lint:style
composer test
composer test:phpunit
node --check js/app.js
node --check js/admin.js
```

`make check` runs the local checks. After committing the release contents, `make package` creates `build/assinafy-<version>.tar.gz` from `HEAD` with the required top-level directory.

## License

MIT
