<?php

declare(strict_types=1);

/** @var array{clientId: string, callbackUrl: string} $_ */
/** @var \OCP\IL10N $l */
?>
<section id="assinafy-admin" class="section">
	<h2><?php p($l->t('Assinafy')); ?></h2>
	<p><?php p($l->t('Configure the public OAuth client used by all Assinafy users on this Nextcloud server.')); ?></p>
	<form id="assinafy-admin-form">
		<label for="assinafy-client-id"><?php p($l->t('OAuth client ID')); ?></label>
		<input id="assinafy-client-id" type="text" maxlength="255" value="<?php p($_['clientId']); ?>" required autocomplete="off">
		<p class="settings-hint"><?php p($l->t('Register this exact callback URL in Assinafy:')); ?></p>
		<code id="assinafy-callback-url"><?php p($_['callbackUrl']); ?></code>
		<p><button class="primary" type="submit"><?php p($l->t('Save')); ?></button> <span id="assinafy-admin-status" role="status"></span></p>
	</form>
</section>
