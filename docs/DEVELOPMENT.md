# Development and release

## Architecture

`Application` registers the Nextcloud app and the version-aware session store. Controllers expose a same-origin JSON surface and keep authentication and CSRF enforcement enabled. Services own OAuth, file access, Assinafy HTTP calls, and encrypted session storage. Every supported version encrypts the OAuth attempt and session with authenticated `ICrypto` encryption. Nextcloud 32–35 stores that ciphertext as lazy sensitive `IUserConfig`; Nextcloud 31 stores it through `IConfig`. Both APIs address the same `oc_preferences` row. Reads never write: the `MigrateOAuthStorage` post-migration repair step re-encrypts values from earlier versions while Nextcloud is in maintenance mode. Nextcloud caches user configuration for the whole request, so every OAuth operation waits for the per-user lock and then reloads state another request may have rotated. Nextcloud 32–35 clears that cache with `IUserConfig::clearCache()`; Nextcloud 31 reads through `IConfig::getUserValueForUsers()`, which bypasses it. Use only public `OCP` APIs, and use `IConfig` user values only on Nextcloud 31, where `IUserConfig` is not public. Domain classes validate recipients, CPF/CNPJ, cost identity, contract terms, and webhook endpoint input. Templates contain semantic markup; JavaScript manages browser state and never receives bearer tokens.

Request flow:

```text
Nextcloud template and JavaScript
  → authenticated app controller
  → domain validation
  → user-scoped Nextcloud file access or OAuth token service
  → Assinafy API v1
  → unwrapped JSON data or stable local error envelope
```

## Local setup

Link or copy this repository to `<nextcloud>/apps/assinafy`, install development dependencies, then enable the app:

```sh
composer install
php /path/to/nextcloud/occ app:enable assinafy
```

Use an Assinafy OAuth client whose callback points to the development Nextcloud server. Do not commit client IDs, tokens, webhook secrets, private signing keys, generated app certificates, or real personal data.

## Checks

```sh
make check
```

The dependency-free runner covers domain validation and mocked service contracts when Composer dependencies are unavailable: recipient mappings, quote payloads, paid-send guards, contract create/edit rules, OAuth attempt handling, and both session-storage backends. When `vendor/` is installed, `make check` also runs PHPUnit 12.5. The JavaScript check includes date rendering under a negative UTC offset. `tests/info.xsd` is the App Store metadata schema snapshot used by `make check`; refresh it from the official URL when changing metadata. The CI matrix covers PHP 8.3, 8.4, and 8.5.

Live checks require a running Nextcloud instance and a controlled Assinafy workspace:

1. Register the exact callback URL.
2. Connect and confirm one workspace.
3. Upload a non-sensitive PDF smaller than 25 MB.
4. Estimate and send once with every enabled signature method, both notification channels for biometric and certificate methods, a biometric CPF, and certificate CPF/CNPJ identities.
5. Confirm delivery, signing status, and the final Assinafy document.
6. Create contract terms, edit with the current timestamp, renew from the current term, and terminate a separate contract.
7. Create a public HTTPS webhook receiver, verify a signed delivery, force a failed delivery, retry it, rotate the secret, and verify the new secret.
8. Disconnect and confirm the next protected operation requires reconnection.
9. On Nextcloud 31, verify encrypted session storage and reconnect. Upgrade that instance to Nextcloud 32 or later and verify that the session survives repeated reads and stays `ICrypto`-encrypted; run `occ maintenance:repair` and verify it becomes lazy sensitive user configuration. With an expired access token, open Webhooks and verify the parallel requests refresh once.

Paid sends should use a dedicated workspace and recorded spending limit. The public assignment API has no accepted-price ceiling or idempotency key, so a live test must check document state after any lost response.

## Add a route

1. Add the route to `appinfo/routes.php`.
2. Add a controller method with the narrowest typed parameters.
3. Keep normal Nextcloud authentication, authorization, and CSRF protection unless the protocol requires an exception.
4. Validate input in a domain class or the service boundary.
5. Add the Assinafy request in `AssinafyClient` and unwrap the standard response envelope.
6. Add a focused unit or assertion check for every non-trivial branch.
7. Document the full local request and response in `docs/API.md`.

## Version and package

Use semantic versions in `appinfo/info.xml`. Keep `CHANGELOG.md` focused on shipped behavior. The release tag must match the XML version with a `v` prefix.

After the release commit and `v<version>` tag:

```sh
make package
```

The command uses `git archive`, so only committed files enter `build/assinafy-<version>.tar.gz`; `.gitattributes` keeps tests, CI, and development tooling out. The archive has one `assinafy/` root. When `~/.nextcloud/certificates/assinafy.key` and `assinafy.crt` exist (or `ASSINAFY_SIGNING_KEY` and `ASSINAFY_SIGNING_CERTIFICATE` point to them), the script signs the code with `occ integrity:sign-app` in a throwaway `nextcloud:31-apache` container, which writes `appinfo/signature.json`, and writes the archive signature for the App Store upload to `build/assinafy-<version>.tar.gz.sig`. Without them it builds an unsigned archive that the App Store rejects.

The certificate comes from a pull request with `assinafy.csr` to `nextcloud/app-certificate-requests`. Register the app once at `apps.nextcloud.com/developer/apps/new` with the certificate and the signature printed by:

```sh
echo -n "assinafy" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/assinafy.key | openssl base64
```

Publishing a GitHub release runs `.github/workflows/release.yml` in the protected `release` environment. It checks that the tag matches `appinfo/info.xml`, builds and signs the archive with the `APP_PRIVATE_KEY` and `APP_PUBLIC_CRT` secrets, attaches it to the release, and submits it to the App Store with the `APPSTORE_TOKEN` secret. A manual release uploads the attached archive URL and the `.sig` contents at `apps.nextcloud.com/developer/apps/releases/new`.

`make check` also runs Psalm against the `nextcloud/ocp` API package from `vendor-bin/psalm`, so calls outside the public Nextcloud API fail the build.

The release repository intentionally ignores `AGENTS.md` and `CLAUDE.md`; they contain local contributor instructions, not runtime files.
