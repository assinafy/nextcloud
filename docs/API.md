# Extension API

These routes are same-origin Nextcloud application routes under `/apps/assinafy`. They are intended for the bundled interface, not third-party cross-origin clients.

All routes require an authenticated Nextcloud session. Writes also require the normal Nextcloud `requesttoken` CSRF header. JSON writes use `Content-Type: application/json`. OAuth access and refresh tokens are never returned.

Successful Assinafy responses normally arrive as `{ "status": 200, "message": "", "data": ... }`. The extension removes that outer envelope and returns `data`. Fields added by a compatible Assinafy response are preserved.

Errors use:

```json
{
  "error": "stable_local_code",
  "message": "Actionable message"
}
```

`400` indicates invalid input, `401` a missing or expired connection, `403` an Assinafy permission/feature refusal, `404` a missing user-owned or Assinafy resource, `409` a changed state or quote, or `oauth_busy` while another request updates the same user's OAuth connection, `429` rate limiting, `502` an invalid upstream response, and `503` transport failure.

### `GET /`

Renders the Nextcloud application HTML with the connection controls and Send, Documents, Envelopes, Contracts, and Webhooks work areas. The optional `error` query is restricted to the OAuth error codes listed below; unknown text is not displayed. It requires login but is exempt from CSRF because rendering is read-only.

## OAuth and state

### `POST /oauth/connect`

Request:

```json
{}
```

Response:

```json
{
  "url": "https://auth.assinafy.com.br/authorize?..."
}
```

The server creates a 10-minute OAuth attempt with a random state and S256 verifier. The URL requests the fixed scope set and discovered API resource.

### `GET /oauth/callback`

Query parameters from Assinafy:

```text
state=<opaque>&code=<authorization-code>&iss=https%3A%2F%2Fauth.assinafy.com.br
```

On success, redirects to `/apps/assinafy/`. On refusal or validation failure, redirects with an `error` code: `access_denied`, `incomplete_response`, `invalid_oauth_response`, `oauth_exchange_failed`, `workspace_required`, `oauth_busy`, `transport_error`, or `connection_failed`. The page shows the fixed message for a known code and ignores any other value. State, issuer, age, redirect URI, resource, and PKCE verifier are checked before token exchange. Exactly one workspace must be returned by `GET /v1/accounts`. If token exchange succeeds but account validation or local session storage fails, the unused refresh grant is revoked.

### `POST /oauth/disconnect`

Request:

```json
{}
```

Response:

```json
{
  "connected": false
}
```

Local OAuth state is deleted before best-effort refresh-token revocation.

### `GET /api/bootstrap`

Response when connected:

```json
{
  "configured": true,
  "connected": true,
  "account": {
    "name": "Example Workspace",
    "user": "Example User",
    "email": "user@example.invalid",
    "scopes": [
      "documents:read",
      "documents:write",
      "templates:read",
      "account:read",
      "openid",
      "profile",
      "email",
      "offline_access",
      "templates:write",
      "webhooks:write"
    ]
  }
}
```

Response when disconnected:

```json
{
  "configured": true,
  "connected": false,
  "account": null
}
```

## Nextcloud files and signing

### `GET /api/files?path=Contracts/2026`

Only folders and PDF files from the authenticated user's Nextcloud file tree are returned.

```json
{
  "items": [
    {
      "id": 42,
      "name": "Customer",
      "path": "Contracts/2026/Customer",
      "type": "folder",
      "size": 0,
      "mime": "httpd/unix-directory"
    },
    {
      "id": 84,
      "name": "Agreement.pdf",
      "path": "Contracts/2026/Agreement.pdf",
      "type": "file",
      "size": 245760,
      "mime": "application/pdf"
    }
  ]
}
```

### `POST /api/documents/prepare`

Request:

```json
{
  "fileId": 84,
  "title": "Service agreement"
}
```

The file ID must resolve to one PDF in the current user's tree and its size must be 1 byte–25 MB. The server uploads it as multipart field `file` to `POST /v1/accounts/{accountId}/documents` and changes its name to `Service agreement.pdf` when necessary.

Response is the Assinafy document object:

```json
{
  "resource": "document",
  "id": "66b2f0e1c3a4d5e6f7a8b9c0",
  "account_id": "d199996981dbd199996981db",
  "template_id": null,
  "name": "Service agreement.pdf",
  "status": "processing",
  "artifacts": {
    "original": "https://api.assinafy.com.br/v1/documents/66b2f0e1c3a4d5e6f7a8b9c0/download/original"
  },
  "assignment": null,
  "is_closed": false,
  "signing_url": null,
  "verification_code": null,
  "verification_url": null,
  "decline_reason": null,
  "declined_by": null,
  "has_contract": false,
  "created_at": "2026-10-31T12:00:00Z",
  "updated_at": "2026-10-31T12:00:00Z"
}
```

### Recipient payload

Single-document estimate and send routes accept 1–50 recipients with unique email addresses; envelopes accept 1–20:

```json
[
  {
    "name": "Example Signer",
    "email": "signer@example.invalid",
    "method": "email",
    "notification": "email",
    "phone": "",
    "taxId": ""
  },
  {
    "name": "WhatsApp Signer",
    "email": "whatsapp@example.invalid",
    "method": "whatsapp",
    "notification": "whatsapp",
    "phone": "+5511999999999",
    "taxId": ""
  },
  {
    "name": "Biometric Signer",
    "email": "biometric@example.invalid",
    "method": "biometric",
    "notification": "whatsapp",
    "phone": "+5511988888888",
    "taxId": "11144477735"
  },
  {
    "name": "Certificate Signer",
    "email": "certificate@example.invalid",
    "method": "certificate",
    "notification": "email",
    "phone": "",
    "taxId": "12.ABC.345/01DE-35"
  }
]
```

Mappings sent to Assinafy:

| Local method | `verification_method` | Allowed local `notification` | Additional requirement |
| --- | --- | --- | --- |
| `email` | `Email` | `email` | — |
| `whatsapp` | `Whatsapp` | `whatsapp` | Phone, 10 to 15 digits. A Brazilian area code and number typed without `+` is sent with `+55`; any other value is sent as `+` and its digits, so another country's number needs its country code |
| `biometric` | `IdDocumentFaceMatchBasic` | `email` or `whatsapp` | Valid CPF |
| `certificate` | `DigitalCertificate` | `email` or `whatsapp` | Valid CPF or CNPJ; ordered signing with co-signers |

The local notification values map to the single-entry Assinafy arrays `["Email"]` and `["Whatsapp"]`. When omitted, `notification` defaults to `whatsapp` for WhatsApp verification and `email` for every other method. A WhatsApp notification requires `phone`, regardless of verification method. CPF values are normalized to digits; CNPJ values are normalized to uppercase digits and letters. The final two CNPJ check digits must be numeric.

### `POST /api/documents/{documentId}/estimate`

Request:

```json
{
  "recipients": [
    {
      "name": "Example Signer",
      "email": "signer@example.invalid",
      "method": "email",
      "notification": "email",
      "phone": "",
      "taxId": ""
    }
  ],
  "ordered": false
}
```

The upstream request is:

```json
{
  "method": "virtual",
  "signers": [
    {
      "verification_method": "Email",
      "notification_methods": ["Email"]
    }
  ]
}
```

Response:

```json
{
  "estimate": {
    "documents": 1,
    "credits": 0.9,
    "needs_extra_document": true,
    "extra_document_cost": 1,
    "total_credits": 1.9,
    "breakdown": [
      {
        "code": "NotificationWhatsapp",
        "name": "WhatsApp Notification",
        "quantity": 2,
        "cost": 0.9,
        "unit_cost": 0.45
      }
    ],
    "document_balance": 0,
    "credit_balance": 5,
    "has_sufficient_resources": true,
    "blocking_reason": null,
    "message": null
  },
  "quoteId": "sha256-hex-value"
}
```

`quoteId` is a server-generated SHA-256 identity over document consumption, all aggregate charges, extra-document use and charge, and sorted breakdown code/name/quantity/cost/unit cost. It is not an Assinafy credential or accepted-price token.

### `POST /api/documents/{documentId}/send`

Request:

```json
{
  "recipients": [
    {
      "name": "Example Signer",
      "email": "signer@example.invalid",
      "method": "email",
      "notification": "email",
      "phone": "",
      "taxId": ""
    }
  ],
  "ordered": true,
  "quoteId": "sha256-hex-value",
  "message": "Please review and sign.",
  "expiresInDays": 30,
  "contract": {
    "counterparty_name": "Example Company",
    "counterparty_taxpayer_id": "11222333000181",
    "start_date": "2026-11-01",
    "end_date": "2027-10-31",
    "value": 120000.5,
    "currency": "BRL",
    "auto_renew": true,
    "renewal_term_months": 12,
    "notice_period_days": 30
  }
}
```

Immediately before creation, the extension repeats the estimate and checks `quoteId` plus `has_sufficient_resources`. It sends the accepted `total_credits` as `max_total_credits`, so Assinafy atomically refuses a higher charge. `CreditCeilingExceeded` becomes local `409 quote_changed`. Contacts are found by exact normalized email or created under `/accounts/{accountId}/signers`. Existing contacts must match the requested name and any supplied phone/CPF/CNPJ.

When `ordered` is false, all signers share an implicit signing step. Because Assinafy requires a `DigitalCertificate` signer to be alone in a step, a certificate signer may use parallel signing only when they are the sole recipient. With co-signers, `ordered` must be true.

Upstream assignment payload:

```json
{
  "method": "virtual",
  "signers": [
    {
      "id": "signer-resource-id",
      "verification_method": "Email",
      "notification_methods": ["Email"],
      "step": 1
    }
  ],
  "message": "Please review and sign.",
  "expires_at": "2026-11-30T12:00:00+00:00",
  "max_total_credits": 1.9
}
```

Response:

```json
{
  "assignment": {
    "resource": "assignment",
    "id": "assignment-resource-id",
    "method": "virtual",
    "status": "pending",
    "expires_at": "2026-11-30T12:00:00Z",
    "created_at": "2026-10-31T12:00:00Z",
    "updated_at": "2026-10-31T12:00:00Z"
  },
  "contract": {
    "resource": "document_contract",
    "document_id": "66b2f0e1c3a4d5e6f7a8b9c0",
    "status": "draft",
    "counterparty_name": "Example Company",
    "counterparty_taxpayer_id": "11222333000181",
    "value": 120000.5,
    "currency": "BRL",
    "start_date": "2026-11-01",
    "end_date": "2027-10-31",
    "auto_renew": true,
    "renewal_term_months": 12,
    "notice_period_days": 30,
    "term": 1,
    "owner_id": null,
    "metadata": null,
    "external_references": [],
    "created_at": "2026-10-31T12:00:00Z",
    "updated_at": "2026-10-31T12:00:00Z"
  },
  "contractError": null
}
```

When assignment creation succeeds and the separate contract write fails, `assignment` remains populated, `contract` is `null`, and `contractError` contains the CLM failure message.

### `GET /api/documents?search=agreement&status=certificated&page=1`

Response:

```json
{
  "items": [
    {
      "resource": "document",
      "id": "66b2f0e1c3a4d5e6f7a8b9c0",
      "account_id": "d199996981dbd199996981db",
      "template_id": null,
      "name": "Service agreement.pdf",
      "status": "certificated",
      "artifacts": {
        "original": "https://api.assinafy.com.br/v1/documents/66b2f0e1c3a4d5e6f7a8b9c0/download/original",
        "certificated": "https://api.assinafy.com.br/v1/documents/66b2f0e1c3a4d5e6f7a8b9c0/download/certificated"
      },
      "is_closed": true,
      "has_contract": true,
      "created_at": "2026-10-31T12:00:00Z",
      "updated_at": "2026-10-31T13:00:00Z"
    }
  ]
}
```

The proxy fixes `sort=-updated_at` and `per-page=50`.

## Envelopes

The app sends 1–20 uploaded PDFs to the same 1–20 recipients in parallel, using virtual assignments. A certificate signer must be the sole recipient. Envelope estimation resolves or creates contacts because Assinafy requires their IDs. Upload each PDF through `POST /api/documents/prepare` first; all documents must be `metadata_ready` and unassigned. Duplicate document IDs and recipient emails are rejected before HTTP requests. The workspace must have the Envelopes feature.

### `POST /api/envelopes/estimate`

Request:

```json
{
  "name": "Agreement and annex",
  "documentIds": ["document-one", "document-two"],
  "recipients": [{"name": "Example Signer", "email": "signer@example.invalid", "method": "email", "notification": "email"}],
  "expiresInDays": 30
}
```

Names contain 1–255 characters without `<`. The deadline defaults to 30 days and accepts 1–365. No envelope is created by this operation. Response:

```json
{
  "estimate": {
    "documents": 1,
    "credits": 0,
    "needs_extra_document": true,
    "extra_document_cost": 1,
    "total_credits": 1,
    "breakdown": [],
    "document_balance": 0,
    "credit_balance": 5,
    "has_sufficient_resources": true,
    "blocking_reason": null,
    "message": null
  },
  "quoteId": "sha256-hex-value"
}
```

Upstream request to `POST /v1/accounts/{accountId}/envelopes/estimate-cost`:

```json
{
  "name": "Agreement and annex",
  "message": "",
  "signers": [{"id": "signer-id", "verification_method": "Email", "notification_methods": ["Email"]}],
  "documents": [
    {"id": "document-one", "method": "virtual", "signer_ids": ["signer-id"], "expires_at": "2026-12-24T12:00:00+00:00"},
    {"id": "document-two", "method": "virtual", "signer_ids": ["signer-id"], "expires_at": "2026-12-24T12:00:00+00:00"}
  ]
}
```

### `POST /api/envelopes/send`

Request uses the estimate fields plus the required `quoteId` and optional message (at most 2,000 characters):

```json
{
  "name": "Agreement and annex",
  "documentIds": ["document-one", "document-two"],
  "recipients": [{"name": "Example Signer", "email": "signer@example.invalid", "method": "email", "notification": "email"}],
  "expiresInDays": 30,
  "quoteId": "sha256-hex-value",
  "message": "Please review both PDFs."
}
```

The server re-estimates, checks the quote and resources, then posts the same upstream body with the message and `max_total_credits` to `/v1/accounts/{accountId}/envelopes`. It never retries an ambiguous creation. Success means creation succeeded; it does not guarantee notification delivery. Contract terms are not part of an envelope send.

Response is the Assinafy envelope object. Each entry has its own document status; an envelope has no aggregate status:

```json
{
  "resource": "envelope",
  "id": "envelope-id",
  "name": "Agreement and annex",
  "message": "Please review both PDFs.",
  "documents": [
    {"id": "document-one", "position": 1, "name": "agreement.pdf", "status": "pending_signature", "artifacts": {}, "signers": [{"id": "signer-id", "full_name": "Example Signer", "email": "signer@example.invalid", "whatsapp_phone_number": null, "completed": false, "signed_at": null}]},
    {"id": "document-two", "position": 2, "name": "annex.pdf", "status": "pending_signature", "artifacts": {}, "signers": [{"id": "signer-id", "full_name": "Example Signer", "email": "signer@example.invalid", "whatsapp_phone_number": null, "completed": false, "signed_at": null}]}
  ],
  "counts": {"pending_signature": 2},
  "signing_urls": [{"signer_id": "signer-id", "url": "https://app.assinafy.com.br/sign/example"}],
  "created_at": "2026-11-24T12:00:00Z"
}
```

### `GET /api/envelopes?page=1`

Returns `{ "items": [<envelope object above>] }`, most recent first, with 20 envelopes per page. Listing artifacts contain only thumbnails. `counts` may be `null` when the envelope has no remaining documents.

### `PUT /api/envelopes/{envelopeId}/cancel`

No request body. Cancels documents that can still be cancelled; completed documents remain unchanged. Safe to repeat. Response:

```json
[
  {"id": "document-one", "status": "rejected_by_user", "cancelled": true},
  {"id": "document-two", "status": "certificated", "cancelled": false}
]
```

### Embedded signing

There are no Embed routes in this extension. Assinafy does not permit OAuth application tokens to manage embed origins or mint embed sessions. Signers use the invitations sent by Assinafy.

## Contract lifecycle

Supported contract terms are `status`, `counterparty_name`, `counterparty_taxpayer_id`, `value`, `currency`, `start_date`, `end_date`, `auto_renew`, `renewal_term_months`, `notice_period_days`, `owner_id`, and `updated_at`. Empty editable values become `null`; `auto_renew` is always boolean. The bundled interface uses the common terms and optimistic `updated_at` precondition.

### `GET /api/contracts`

Accepted query parameters are `status`, `search`, `counterparty`, `end_date_from`, `end_date_to`, `ending_within_days`, `external_system`, `external_id`, `sort`, `page`, and `per-page`. The proxy adds `expand=document` and clamps `per-page` to 1–50.

Response:

```json
{
  "items": [
    {
      "resource": "document_contract",
      "document_id": "66b2f0e1c3a4d5e6f7a8b9c0",
      "status": "active",
      "counterparty_name": "Example Company",
      "counterparty_taxpayer_id": "11222333000181",
      "value": 120000.5,
      "currency": "BRL",
      "start_date": "2026-11-01",
      "end_date": "2027-10-31",
      "auto_renew": true,
      "renewal_term_months": 12,
      "notice_period_days": 30,
      "term": 1,
      "owner_id": null,
      "metadata": null,
      "external_references": [],
      "document": {
        "id": "66b2f0e1c3a4d5e6f7a8b9c0",
        "name": "Service agreement.pdf",
        "status": "certificated"
      },
      "created_at": "2026-10-31T12:00:00Z",
      "updated_at": "2026-10-31T13:00:00Z"
    }
  ]
}
```

### `PUT /api/documents/{documentId}/contract`

Request wraps the partial Assinafy terms object:

```json
{
  "terms": {
    "counterparty_name": "Example Company",
    "counterparty_taxpayer_id": "11222333000181",
    "value": 125000,
    "currency": "BRL",
    "start_date": "2026-11-01",
    "end_date": "2027-10-31",
    "auto_renew": true,
    "renewal_term_months": 12,
    "notice_period_days": 0,
    "updated_at": "2026-10-31T13:00:00Z"
  }
}
```

`updated_at` is required for this standalone edit route; omission returns `400` with `error: "updated_at_required"` before any upstream write. Initial contract creation as part of the send flow does not require a timestamp. Response is the complete saved `document_contract` object shown above. A stale `updated_at` returns `409` without changing terms.

### `POST /api/documents/{documentId}/contract/renew`

Request:

```json
{
  "term": 1,
  "updatedAt": "2026-10-31T13:00:00Z"
}
```

Upstream payload:

```json
{
  "term": 1,
  "updated_at": "2026-10-31T13:00:00Z"
}
```

Response is the complete renewed contract with `term: 2`, the advanced `end_date`, `status: "active"`, and a new `updated_at`.

### `POST /api/documents/{documentId}/contract/terminate`

Request:

```json
{
  "reason": "Replaced by a new agreement",
  "updatedAt": "2026-10-31T13:00:00Z"
}
```

Upstream payload:

```json
{
  "reason": "Replaced by a new agreement",
  "updated_at": "2026-10-31T13:00:00Z"
}
```

Response is the complete contract with `status: "terminated"` and a new `updated_at`.

## Webhooks

### `GET /api/webhooks/event-types`

Response:

```json
{
  "items": [
    {
      "id": "document_ready",
      "description": "Triggered when the last signer completes the document."
    },
    {
      "id": "signer_signed_document",
      "description": "Triggered when a signer signs a document."
    }
  ]
}
```

The exact catalog is controlled by Assinafy and should be loaded before endpoint creation.

### `GET /api/webhooks/endpoints`

Response:

```json
{
  "items": [
    {
      "resource": "activity_event_subscription",
      "id": "65f1c2a9b3e4d5f60718293a4b5c6d7e",
      "name": "Contract system",
      "url": "https://hooks.example.invalid/assinafy",
      "email": "webhooks@example.invalid",
      "events": ["document_ready", "signer_signed_document"],
      "is_active": true,
      "is_held": false,
      "held_since": null,
      "signing_enabled": true,
      "created_at": "2026-10-31T12:00:00Z",
      "updated_at": "2026-10-31T12:00:00Z"
    }
  ]
}
```

### `POST /api/webhooks/endpoints`

Request:

```json
{
  "name": "Contract system",
  "url": "https://hooks.example.invalid/assinafy",
  "email": "webhooks@example.invalid",
  "events": ["document_ready", "signer_signed_document"],
  "isActive": true,
  "signingEnabled": true
}
```

Response is a complete endpoint object plus a one-time secret when signing is enabled:

```json
{
  "resource": "activity_event_subscription",
  "id": "65f1c2a9b3e4d5f60718293a4b5c6d7e",
  "name": "Contract system",
  "url": "https://hooks.example.invalid/assinafy",
  "email": "webhooks@example.invalid",
  "events": ["document_ready", "signer_signed_document"],
  "is_active": true,
  "is_held": false,
  "held_since": null,
  "signing_enabled": true,
  "secret": "whsec_base64example",
  "created_at": "2026-10-31T12:00:00Z",
  "updated_at": "2026-10-31T12:00:00Z"
}
```

The URL must be public HTTPS, contain no embedded credentials, and be at most 255 bytes. The local check rejects private/reserved IP literals; Assinafy also resolves hostnames and rejects non-public destinations.

### `PUT /api/webhooks/endpoints/{endpointId}`

Request wraps any non-empty partial change:

```json
{
  "changes": {
    "name": "Contract system v2",
    "url": "https://hooks.example.invalid/assinafy-v2",
    "email": "webhooks@example.invalid",
    "events": ["document_ready"],
    "is_active": true,
    "signing_enabled": true
  }
}
```

Response is the complete updated endpoint. If the request turns signing on and Assinafy creates a key, the response also includes the one-time `secret`.

### `DELETE /api/webhooks/endpoints/{endpointId}`

Response:

```json
{
  "deleted": true
}
```

### `POST /api/webhooks/endpoints/{endpointId}/secret/rotate`

Request:

```json
{}
```

Response:

```json
{
  "secret": "whsec_newbase64example"
}
```

The old secret stops working immediately.

### `GET /api/webhooks/deliveries`

Accepted query parameters are `endpoint_id`, `event`, `delivered` (`true` or `false`), `from` and `to` Unix timestamps, `page`, and `per-page` (clamped to 1–50).

Response:

```json
{
  "items": [
    {
      "resource": "activity_dispatching_history",
      "id": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
      "event": "document_ready",
      "activity_id": 456,
      "endpoint_id": "65f1c2a9b3e4d5f60718293a4b5c6d7e",
      "endpoint": "https://hooks.example.invalid/assinafy",
      "payload": {},
      "delivered": false,
      "webhook_id": "9f86d081884c7d659a2feaa0c55ad015",
      "attempt": 2,
      "kind": "scheduled",
      "will_retry": true,
      "http_status": 503,
      "response_body": "unavailable",
      "error": null,
      "created_at": "2026-10-31T12:00:00Z",
      "updated_at": "2026-10-31T12:00:01Z"
    }
  ]
}
```

### `POST /api/webhooks/deliveries/{deliveryId}/retry`

Request:

```json
{}
```

Response is the new dispatch entry:

```json
{
  "resource": "activity_dispatching_history",
  "id": "new-dispatch-id",
  "event": "document_ready",
  "endpoint_id": "65f1c2a9b3e4d5f60718293a4b5c6d7e",
  "delivered": true,
  "attempt": 1,
  "kind": "manual",
  "will_retry": false,
  "http_status": 204,
  "response_body": "",
  "error": null,
  "created_at": "2026-10-31T12:05:00Z",
  "updated_at": "2026-10-31T12:05:00Z"
}
```

## Administration

### `POST /settings`

This route requires a Nextcloud administrator.

Request:

```json
{
  "clientId": "public-client-id"
}
```

Response:

```json
{
  "saved": true
}
```

Client IDs accept 1–255 ASCII letters, digits, `.`, `_`, `~`, and `-`. Client secrets are not accepted or stored. An invalid ID returns `400` with `error: "invalid_client_id"`.
