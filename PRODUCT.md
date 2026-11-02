# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

Nextcloud app using server-rendered PHP templates, plain JavaScript, and CSS that inherits the Nextcloud application shell.

## Users

Authenticated Nextcloud users who manage PDF signature workflows and contracts from files already stored in Nextcloud. Nextcloud administrators configure the shared Assinafy OAuth client.

## Product Purpose

Assinafy connects a Nextcloud user to one Assinafy workspace with OAuth 2.0, sends a selected PDF for signature, tracks the resulting documents and contracts, and manages outbound Assinafy webhook endpoints and delivery retries.

## Positioning

The extension starts from a user-owned Nextcloud file and completes the Assinafy workflow without uploading the file through an unrelated browser form or storing Assinafy credentials in the browser.

## Operating Context

Users select a PDF of at most 25 MB, add up to 50 recipients, choose ordered or parallel signing, review the current Assinafy estimate, and send. Supported verification paths are email, WhatsApp, biometric identity verification, and A1/A3 digital certificate. Biometric and digital-certificate signers may receive notifications by email or WhatsApp. Contract terms may be recorded with the sent document. Separate work areas cover sent documents, contract lifecycle actions, webhook endpoints, and webhook delivery retries.

## Capabilities and Constraints

- OAuth 2.0 Authorization Code with PKCE uses the fixed Assinafy issuer, API resource, and scopes.
- One Assinafy workspace must be selected during authorization.
- OAuth sessions are encrypted with authenticated `ICrypto` encryption on every supported version and stored as sensitive user configuration on Nextcloud 32–35; they are never exposed to client-side code.
- The app targets Nextcloud 31 through 35 and PHP 8.3 through 8.5.
- PDF access is resolved through the authenticated user's Nextcloud file tree.
- A fresh estimate is required immediately before sending; changed pricing blocks the send until the user reviews it.
- Webhook secrets are visible only in responses where Assinafy returns a newly created or rotated secret.
- The interface uses English as its source language and includes Brazilian Portuguese localization.

## Brand Commitments

Use the Assinafy name and product terminology. The extension remains visually subordinate to the Nextcloud application shell.

## Evidence on Hand

The Assinafy API implementation and mobile application provide the endpoint paths, payloads, OAuth behavior, signing methods, contract lifecycle fields, and webhook behavior. No customer claims, production metrics, testimonials, or customer data are available and none may be fabricated.

## Product Principles

- Confirm cost and recipient verification requirements before a paid send.
- Keep tokens, secrets, and user files server-side.
- Preserve the user's Nextcloud mental model and permission boundaries.
- Make partial outcomes explicit when signing succeeds but contract metadata does not.
- Prefer recoverable actions and clear operational states.

## Accessibility & Inclusion

The interface must be keyboard-operable, responsive, compatible with Nextcloud theming, and expose status and validation messages to assistive technology.
