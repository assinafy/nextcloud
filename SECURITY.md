# Security policy

Report a security issue privately through GitHub private vulnerability reporting: https://github.com/assinafy/nextcloud/security/advisories/new. Assinafy acknowledges reports and coordinates a fix and disclosure through that advisory. Do not open a public issue containing tokens, webhook secrets, signed documents, CPF/CNPJ values, phone numbers, email addresses, callback codes, or reproduction data from a production workspace.

Include the app version, Nextcloud version, PHP version, affected route, expected behavior, and a minimal reproduction using synthetic data. Revoke exposed OAuth grants and rotate exposed webhook secrets before sharing diagnostic material.

Supported security updates target the current release line and the Nextcloud/PHP versions declared in `appinfo/info.xml`.
