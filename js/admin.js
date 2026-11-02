(function () {
	'use strict';

	const form = document.getElementById('assinafy-admin-form');
	if (!form) return;
	const tr = (message) => typeof t === 'function' ? t('assinafy', message) : message;

	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		const button = form.querySelector('button[type="submit"]');
		const status = document.getElementById('assinafy-admin-status');
		button.disabled = true;
		status.textContent = tr('Saving…');
		try {
			const response = await fetch(OC.generateUrl('/apps/assinafy/settings'), {
				method: 'POST',
				headers: {'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken},
				body: JSON.stringify({clientId: document.getElementById('assinafy-client-id').value.trim()}),
			});
			if (response.status === 429) throw new Error(tr('Too many requests. Try again later.'));
			const data = await response.json();
			if (!response.ok) throw new Error(data.message || tr('The setting could not be saved.'));
			status.textContent = tr('Saved');
		} catch (error) {
			status.textContent = error instanceof Error ? error.message : tr('The setting could not be saved.');
		} finally {
			button.disabled = false;
		}
	});
}());
