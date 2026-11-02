<?php

declare(strict_types=1);

/** @var array{configured: bool, error: string} $_ */
/** @var \OCP\IL10N $l */
?>
<main
	id="assinafy-app"
	class="assinafy-app"
	data-configured="<?php p($_['configured'] ? '1' : '0'); ?>"
	data-flash-error="<?php p($_['error']); ?>">
	<header class="assinafy-header">
		<div>
			<h1><?php p($l->t('Assinafy')); ?></h1>
			<p id="assinafy-connection-copy"><?php p($l->t('Checking your Assinafy connection…')); ?></p>
		</div>
		<div class="assinafy-connection-actions">
			<span id="assinafy-connection-state" class="assinafy-status" role="status"></span>
			<button id="assinafy-connect" class="primary" type="button"><?php p($l->t('Connect Assinafy')); ?></button>
			<button id="assinafy-disconnect" type="button" hidden><?php p($l->t('Disconnect')); ?></button>
		</div>
	</header>

	<div id="assinafy-live" class="assinafy-live" aria-live="polite"></div>

	<nav class="assinafy-tabs" aria-label="<?php p($l->t('Assinafy work areas')); ?>">
		<button type="button" class="assinafy-tab is-active" data-panel="send" aria-selected="true"><?php p($l->t('Send')); ?></button>
		<button type="button" class="assinafy-tab" data-panel="documents" aria-selected="false"><?php p($l->t('Documents')); ?></button>
		<button type="button" class="assinafy-tab" data-panel="contracts" aria-selected="false"><?php p($l->t('Contracts')); ?></button>
		<button type="button" class="assinafy-tab" data-panel="webhooks" aria-selected="false"><?php p($l->t('Webhooks')); ?></button>
	</nav>

	<section id="assinafy-panel-send" class="assinafy-panel" data-panel-content="send">
		<form id="assinafy-send-form">
			<div class="assinafy-send-grid">
				<section class="assinafy-work-block" aria-labelledby="assinafy-file-heading">
					<div class="assinafy-block-heading">
						<div>
							<h2 id="assinafy-file-heading"><?php p($l->t('Choose a PDF')); ?></h2>
							<p><?php p($l->t('Files are read from your Nextcloud account and must be no larger than 25 MB.')); ?></p>
						</div>
						<button id="assinafy-folder-up" type="button" hidden><?php p($l->t('Up one folder')); ?></button>
					</div>
					<p id="assinafy-folder-path" class="assinafy-path">/</p>
					<div id="assinafy-file-list" class="assinafy-file-list" aria-live="polite"></div>
					<label for="assinafy-title"><?php p($l->t('Document title')); ?></label>
					<input id="assinafy-title" name="title" type="text" maxlength="250" required autocomplete="off">
				</section>

				<section class="assinafy-work-block" aria-labelledby="assinafy-recipient-heading">
					<div class="assinafy-block-heading">
						<div>
							<h2 id="assinafy-recipient-heading"><?php p($l->t('Add recipients')); ?></h2>
							<p><?php p($l->t('Choose how each person verifies their identity and receives the request.')); ?></p>
						</div>
						<button id="assinafy-add-recipient" type="button"><?php p($l->t('Add recipient')); ?></button>
					</div>
					<div id="assinafy-recipients"></div>
					<label class="assinafy-check">
						<input id="assinafy-ordered" type="checkbox">
						<span><?php p($l->t('Recipients sign in the listed order')); ?></span>
					</label>
				</section>
			</div>

			<section class="assinafy-work-block assinafy-options" aria-labelledby="assinafy-options-heading">
				<div>
					<h2 id="assinafy-options-heading"><?php p($l->t('Message and deadline')); ?></h2>
					<label for="assinafy-message"><?php p($l->t('Message to recipients')); ?></label>
					<textarea id="assinafy-message" maxlength="2000" rows="3"></textarea>
				</div>
				<div>
					<label for="assinafy-expiry"><?php p($l->t('Signing deadline')); ?></label>
					<div class="assinafy-inline-field">
						<input id="assinafy-expiry" type="number" min="1" max="365" value="30" required>
						<span><?php p($l->t('days')); ?></span>
					</div>
				</div>
			</section>

			<details class="assinafy-work-block assinafy-contract-details">
				<summary><?php p($l->t('Track this document as a contract')); ?></summary>
				<div class="assinafy-form-grid">
					<label><?php p($l->t('Counterparty')); ?><input id="assinafy-contract-counterparty" type="text" maxlength="255"></label>
					<label><?php p($l->t('Taxpayer ID')); ?><input id="assinafy-contract-tax-id" type="text" maxlength="20"></label>
					<label><?php p($l->t('Start date')); ?><input id="assinafy-contract-start" type="date"></label>
					<label><?php p($l->t('End date')); ?><input id="assinafy-contract-end" type="date"></label>
					<label><?php p($l->t('Value')); ?><input id="assinafy-contract-value" type="number" min="0" step="0.01"></label>
					<label><?php p($l->t('Currency')); ?><input id="assinafy-contract-currency" type="text" maxlength="3" value="BRL"></label>
					<label><?php p($l->t('Renewal term')); ?><input id="assinafy-contract-renewal" type="number" min="1" max="600"><span><?php p($l->t('months')); ?></span></label>
					<label><?php p($l->t('Notice period')); ?><input id="assinafy-contract-notice" type="number" min="0" max="3650"><span><?php p($l->t('days')); ?></span></label>
					<label class="assinafy-check"><input id="assinafy-contract-auto-renew" type="checkbox"><span><?php p($l->t('Automatically renew')); ?></span></label>
				</div>
			</details>

			<section id="assinafy-quote" class="assinafy-quote" aria-live="polite" hidden></section>
			<div class="assinafy-primary-actions">
				<button id="assinafy-estimate" class="primary" type="submit"><?php p($l->t('Review price')); ?></button>
				<button id="assinafy-send" class="primary" type="button" disabled><?php p($l->t('Send for signature')); ?></button>
			</div>
		</form>
	</section>

	<section id="assinafy-panel-documents" class="assinafy-panel" data-panel-content="documents" hidden>
		<div class="assinafy-panel-heading">
			<div><h2><?php p($l->t('Documents')); ?></h2><p><?php p($l->t('Recent documents from the connected Assinafy workspace.')); ?></p></div>
			<button id="assinafy-refresh-documents" type="button"><?php p($l->t('Refresh')); ?></button>
		</div>
		<div class="assinafy-filter-row">
			<label><?php p($l->t('Search')); ?><input id="assinafy-document-search" type="search"></label>
			<label><?php p($l->t('Status')); ?><input id="assinafy-document-status" type="text"></label>
		</div>
		<div id="assinafy-documents-list" class="assinafy-table-wrap"></div>
		<div class="assinafy-pagination" aria-label="<?php p($l->t('Document pages')); ?>">
			<button id="assinafy-documents-prev" type="button"><?php p($l->t('Previous')); ?></button>
			<span id="assinafy-documents-page"></span>
			<button id="assinafy-documents-next" type="button"><?php p($l->t('Next')); ?></button>
		</div>
	</section>

	<section id="assinafy-panel-contracts" class="assinafy-panel" data-panel-content="contracts" hidden>
		<div class="assinafy-panel-heading">
			<div><h2><?php p($l->t('Contract lifecycle')); ?></h2><p><?php p($l->t('Review terms, renew active contracts, or record a termination.')); ?></p></div>
			<button id="assinafy-refresh-contracts" type="button"><?php p($l->t('Refresh')); ?></button>
		</div>
		<div class="assinafy-filter-row">
			<label><?php p($l->t('Search')); ?><input id="assinafy-contract-search" type="search"></label>
			<label><?php p($l->t('Status')); ?><input id="assinafy-contract-status" type="text"></label>
		</div>
		<div id="assinafy-contracts-list" class="assinafy-table-wrap"></div>
		<div class="assinafy-pagination" aria-label="<?php p($l->t('Contract pages')); ?>">
			<button id="assinafy-contracts-prev" type="button"><?php p($l->t('Previous')); ?></button>
			<span id="assinafy-contracts-page"></span>
			<button id="assinafy-contracts-next" type="button"><?php p($l->t('Next')); ?></button>
		</div>
		<form id="assinafy-contract-editor" class="assinafy-work-block" hidden>
			<h3><?php p($l->t('Edit contract terms')); ?></h3>
			<input id="assinafy-editor-document-id" type="hidden">
			<input id="assinafy-editor-updated-at" type="hidden">
			<div class="assinafy-form-grid">
				<label><?php p($l->t('Counterparty')); ?><input id="assinafy-editor-counterparty" type="text" maxlength="255"></label>
				<label><?php p($l->t('Taxpayer ID')); ?><input id="assinafy-editor-tax-id" type="text" maxlength="20"></label>
				<label><?php p($l->t('Start date')); ?><input id="assinafy-editor-start" type="date"></label>
				<label><?php p($l->t('End date')); ?><input id="assinafy-editor-end" type="date"></label>
				<label><?php p($l->t('Value')); ?><input id="assinafy-editor-value" type="number" min="0" step="0.01"></label>
				<label><?php p($l->t('Currency')); ?><input id="assinafy-editor-currency" type="text" maxlength="3"></label>
				<label><?php p($l->t('Renewal term')); ?><input id="assinafy-editor-renewal" type="number" min="1" max="600"></label>
				<label><?php p($l->t('Notice period')); ?><input id="assinafy-editor-notice" type="number" min="0" max="3650"></label>
				<label class="assinafy-check"><input id="assinafy-editor-auto-renew" type="checkbox"><span><?php p($l->t('Automatically renew')); ?></span></label>
			</div>
			<div class="assinafy-primary-actions">
				<button class="primary" type="submit"><?php p($l->t('Save terms')); ?></button>
				<button id="assinafy-editor-cancel" type="button"><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
		<form id="assinafy-termination-editor" class="assinafy-work-block" hidden>
			<h3><?php p($l->t('Terminate contract')); ?></h3>
			<input id="assinafy-termination-document-id" type="hidden">
			<input id="assinafy-termination-updated-at" type="hidden">
			<label for="assinafy-termination-reason"><?php p($l->t('Termination reason (optional)')); ?></label>
			<textarea id="assinafy-termination-reason" maxlength="500" rows="3"></textarea>
			<div class="assinafy-primary-actions">
				<button class="primary" type="submit"><?php p($l->t('Record termination')); ?></button>
				<button id="assinafy-termination-cancel" type="button"><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	</section>

	<section id="assinafy-panel-webhooks" class="assinafy-panel" data-panel-content="webhooks" hidden>
		<div class="assinafy-panel-heading">
			<div><h2><?php p($l->t('Webhooks')); ?></h2><p><?php p($l->t('Manage Assinafy event destinations and retry failed deliveries.')); ?></p></div>
			<button id="assinafy-refresh-webhooks" type="button"><?php p($l->t('Refresh')); ?></button>
		</div>
		<form id="assinafy-webhook-form" class="assinafy-work-block">
			<h3><?php p($l->t('Add an endpoint')); ?></h3>
			<div class="assinafy-form-grid">
				<label><?php p($l->t('Name')); ?><input id="assinafy-webhook-name" type="text" maxlength="255" required></label>
				<label><?php p($l->t('HTTPS URL')); ?><input id="assinafy-webhook-url" type="url" maxlength="255" placeholder="https://hooks.example.invalid/assinafy" required></label>
				<label><?php p($l->t('Contact email')); ?><input id="assinafy-webhook-email" type="email" maxlength="255" placeholder="webhooks@example.invalid" required></label>
			</div>
			<fieldset><legend><?php p($l->t('Events')); ?></legend><div id="assinafy-webhook-events" class="assinafy-event-list"></div></fieldset>
			<label class="assinafy-check"><input id="assinafy-webhook-signing" type="checkbox" checked><span><?php p($l->t('Sign webhook deliveries')); ?></span></label>
			<div class="assinafy-primary-actions">
				<button id="assinafy-webhook-submit" class="primary" type="submit"><?php p($l->t('Create endpoint')); ?></button>
				<button id="assinafy-webhook-cancel" type="button" hidden><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
		<div id="assinafy-webhook-secret" class="assinafy-secret" role="alert" hidden></div>
		<h3><?php p($l->t('Endpoints')); ?></h3>
		<div id="assinafy-webhook-endpoints" class="assinafy-table-wrap"></div>
		<h3><?php p($l->t('Recent deliveries')); ?></h3>
		<div id="assinafy-webhook-deliveries" class="assinafy-table-wrap"></div>
	</section>

	<section id="assinafy-locked" class="assinafy-locked" hidden>
		<h2><?php p($l->t('Connect Assinafy to continue')); ?></h2>
		<p><?php p($l->t('Your administrator must configure the OAuth client before you can connect.')); ?></p>
	</section>
</main>
