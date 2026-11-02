# User guide

## Connect

Open **Assinafy** in Nextcloud and select **Connect Assinafy**. Complete authorization in Assinafy and select exactly one workspace. If the connection button is unavailable, a Nextcloud administrator must configure the OAuth client ID.

The connection belongs to the current Nextcloud user. Disconnecting one user does not change another user's connection.

## Send a PDF

1. Browse folders in **Send** and select a PDF. The file must be readable by your Nextcloud account and no larger than 25 MB.
2. Enter the document title.
3. Add 1–50 recipients. Every recipient needs a name and email address.
4. Choose a method:

   - **Email** sends the notification by email.
   - **WhatsApp** requires an international number with country code and sends the notification through WhatsApp.
   - **Biometric identity** requires a valid CPF.
   - **A1 / A3 digital certificate** requires a valid CPF or CNPJ. Alphanumeric CNPJ values are supported.

   Email and WhatsApp verification always use their matching notification channel. For biometric and digital-certificate verification, choose email or WhatsApp notification. WhatsApp notification requires an international number with country code.

5. Turn on ordered signing when each recipient must wait for the previous person. Ordered signing is required when a digital-certificate signer has any co-signers.
6. Add an optional message and choose a deadline from 1 to 365 days.
7. Optionally open **Track this document as a contract** and enter the contract terms.
8. Select **Review price**. Wait while Assinafy processes a newly uploaded PDF.
9. Confirm the credits, document use, balances, and availability.
10. Select **Send for signature**.

Changing the file, title, recipient, method, or signing order clears the displayed estimate. A changed Assinafy price also returns the workflow to price review.

## Documents

Open **Documents** to search recent Assinafy documents or filter by status. The list displays the Assinafy document ID so support and API operations can refer to the same resource.

## Contract lifecycle

Open **Contracts** to search by document name or status.

- **Edit** changes tracked terms and sends the version timestamp that was loaded. Assinafy refuses the write if another process changed the contract first.
- **Renew** requires the current term number. Assinafy advances the end date using the stored renewal term and refuses a duplicate renewal.
- **Terminate** changes the status to terminated and may record a reason of up to 500 characters.

The tracked fields are counterparty name and CPF/CNPJ, value and ISO currency, start and end dates, automatic renewal, renewal term, notice period, and current term/state returned by Assinafy.

## Webhooks

Open **Webhooks** to manage outbound event delivery.

1. Enter a name, a public HTTPS URL, a contact email, and one or more event types.
2. Keep **Sign webhook deliveries** enabled unless the receiver cannot verify Standard Webhooks signatures.
3. Select **Create endpoint**.
4. Copy and store the returned secret before leaving the page.

Pausing stops new deliveries. Resuming also releases an endpoint that Assinafy held after repeated failures. Rotating a secret disables the old secret immediately. A manual retry creates another delivery attempt for the selected history entry.

## Recover from errors

- **Document still processing:** wait and select **Review price** again.
- **Insufficient resources:** add credits or document allowance in Assinafy, then request a new estimate.
- **Contact conflict:** update the saved Assinafy contact so its name, phone, and CPF/CNPJ match the intended recipient.
- **Price changed:** review the replacement estimate before sending.
- **Session expired:** connect Assinafy again. Rotating refresh tokens are never replayed after an uncertain refresh result.
- **Send result unknown after a connection loss:** check **Documents** before starting another send. Do not assume the assignment failed only because its response did not arrive.
