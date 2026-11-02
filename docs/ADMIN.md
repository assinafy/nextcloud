# Administration guide

## Compatibility

The app supports Nextcloud 31–35 on 64-bit PHP 8.3–8.5 with JSON and OpenSSL. Serve Nextcloud over HTTPS and keep its background jobs, mail, database, and system clock healthy.

## Register the OAuth client

1. Install and enable the `assinafy` app.
2. Open **Administration settings → Assinafy**.
3. Copy the callback URL exactly, including the Nextcloud web root when installed in a subdirectory.
4. In Assinafy, create a public OAuth client using Authorization Code with S256 PKCE and no client secret.
5. Add the callback URL.
6. Allow these scopes:

   ```text
   documents:read documents:write templates:read templates:write
   account:read webhooks:write openid profile email offline_access
   ```

7. Paste the client ID in Nextcloud and save.

The issuer must be `https://auth.assinafy.com.br`. The protected API resource must be under `https://api.assinafy.com.br`. The app discovers endpoint URLs and rejects unexpected schemes, origins, or embedded credentials.

## Stored configuration

- The OAuth client ID is global application configuration.
- Each user's OAuth attempt and session are encrypted with authenticated `ICrypto` encryption on every supported version. Nextcloud 32–35 stores the ciphertext as sensitive `IUserConfig`; Nextcloud 31 stores it through `IConfig`. Values written by earlier app versions stay readable and are re-encrypted by the app's post-migration repair step during the app upgrade; `occ maintenance:repair` runs the same step. After a Nextcloud 31 to 32 upgrade, existing sessions stay connected and are rewritten as sensitive configuration at the next token refresh.
- Tokens never enter template parameters, JavaScript state, logs, URLs, or user-visible API responses.
- Disconnect deletes local session data before a best-effort refresh-token revocation.

Back up the Nextcloud instance secret and configuration with the database. Changing the instance secret can make existing encrypted application configuration unreadable and require users to reconnect.

## Permissions

Nextcloud authentication and CSRF protection apply to every application write. The OAuth callback is the only state-changing route exempt from CSRF because the signed OAuth state and authenticated Nextcloud session bind the response.

Assinafy still enforces workspace role, plan, feature, balance, and scope permissions. A Nextcloud administrator configuring the client does not grant an Assinafy user owner-only access.

## Webhook receiver requirements

The app manages Assinafy outbound endpoints; it is not itself a webhook receiver. A receiver should:

- accept only HTTPS;
- preserve the raw body;
- calculate the Standard Webhooks HMAC over `webhook-id.webhook-timestamp.body`;
- accept any matching `v1,<signature>` entry with a constant-time equality check;
- reject timestamps outside the receiver's allowed window;
- deduplicate by `webhook-id`;
- return a `2xx` response only after durable acceptance.

Never put a webhook signing secret into Nextcloud app configuration unless a separate receiver implementation is designed to consume it.

## Upgrade

1. Put Nextcloud into the maintenance procedure appropriate for the installation.
2. Replace the complete `assinafy` directory with the signed release directory.
3. Run the standard Nextcloud upgrade command if the server requests it.
4. Enable the app and confirm that the administration page retains the client ID.
5. Connect a non-production Assinafy workspace and exercise connect, estimate, send, contracts, and webhook management before broad rollout.

Version `0.x` releases may add validated fields or adjust local route payloads while preserving the documented Assinafy resource behavior.
