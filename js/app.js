(function () {
	'use strict';

	const formatDate = (input, locale) => {
		if (!input) return '—';
		const value = typeof input === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(input)
			? `${input}T00:00:00`
			: input;
		const date = new Date(value);
		const language = locale || (typeof document === 'undefined' ? 'en' : document.documentElement.lang || 'en');
		return Number.isNaN(date.valueOf()) ? String(input) : new Intl.DateTimeFormat(language, {dateStyle: 'medium'}).format(date);
	};
	const endsSession = (status, code) => status === 401 && (code === 'session_expired' || code === 'not_connected');
	if (typeof module !== 'undefined' && module.exports) module.exports = {formatDate, endsSession};

	const root = typeof document === 'undefined' ? null : document.getElementById('assinafy-app');
	if (!root) return;

	const $ = (selector, scope = document) => scope.querySelector(selector);
	const $$ = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));
	const tr = (message, vars = {}) => typeof t === 'function' ? t('assinafy', message, vars) : message;
	const state = {
		connected: false,
		folder: '',
		fileId: null,
		documentId: null,
		preparedKey: '',
		quoteId: '',
		quote: null,
		contracts: [],
		documentPage: 1,
		contractPage: 1,
		webhookEndpoints: [],
		editingEndpointId: null,
	};

	class ApiError extends Error {
		constructor(message, status, code) {
			super(message);
			this.status = status;
			this.code = code;
		}
	}

	async function api(path, options = {}) {
		const method = options.method || 'GET';
		const query = new URLSearchParams();
		Object.entries(options.query || {}).forEach(([key, value]) => {
			if (value !== '' && value !== null && value !== undefined) query.set(key, String(value));
		});
		const suffix = query.size ? `?${query.toString()}` : '';
		const response = await fetch(OC.generateUrl(`/apps/assinafy${path}`) + suffix, {
			method,
			headers: {
				Accept: 'application/json',
				requesttoken: OC.requestToken,
				...(options.body === undefined ? {} : {'Content-Type': 'application/json'}),
			},
			body: options.body === undefined ? undefined : JSON.stringify(options.body),
		});
		let data = {};
		try {
			data = await response.json();
		} catch (_) {
			if (response.status === 429) throw new ApiError(tr('Too many requests. Try again later.'), 429, 'rate_limited');
			throw new ApiError(tr('The server returned an unreadable response.'), response.status, 'invalid_response');
		}
		if (!response.ok) {
			const code = data.error || 'request_failed';
			// Ask the server for the real state; the administrator may also have removed the OAuth client.
			if (endsSession(response.status, code)) bootstrap().catch(report);
			throw new ApiError(data.message || tr('The operation could not be completed.'), response.status, code);
		}
		return data;
	}

	function announce(message, type = 'info') {
		$('#assinafy-live').textContent = message;
		if (OC.Notification && typeof OC.Notification.showTemporary === 'function') {
			OC.Notification.showTemporary(message, {type});
		}
	}

	function report(error) {
		announce(error instanceof Error ? error.message : tr('The operation could not be completed.'), 'error');
	}

	async function withBusy(button, label, operation) {
		const original = button.textContent;
		button.disabled = true;
		button.textContent = label;
		try {
			return await operation();
		} finally {
			button.disabled = false;
			button.textContent = original;
		}
	}

	const value = (selector) => $(selector).value.trim();
	const display = (input) => input === null || input === undefined || input === '' ? '—' : String(input);
	const formatNumber = (input) => typeof input === 'number' && Number.isFinite(input)
		? new Intl.NumberFormat(document.documentElement.lang || 'en', {maximumFractionDigits: 4}).format(input)
		: '—';
	const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

	function cell(text) {
		const td = document.createElement('td');
		td.textContent = display(text);
		return td;
	}

	function table(headers, rows, emptyMessage) {
		if (!rows.length) {
			const empty = document.createElement('p');
			empty.className = 'assinafy-empty';
			empty.textContent = emptyMessage;
			return empty;
		}
		const element = document.createElement('table');
		const head = document.createElement('thead');
		const headRow = document.createElement('tr');
		headers.forEach((header) => {
			const th = document.createElement('th');
			th.scope = 'col';
			th.textContent = header;
			headRow.append(th);
		});
		head.append(headRow);
		const body = document.createElement('tbody');
		rows.forEach((row) => body.append(row));
		element.append(head, body);
		return element;
	}

	function setConnected(bootstrap) {
		state.connected = bootstrap.connected === true;
		const configured = bootstrap.configured === true;
		const account = bootstrap.account || {};
		$('#assinafy-connect').hidden = state.connected || !configured;
		$('#assinafy-disconnect').hidden = !state.connected;
		$('#assinafy-locked').hidden = state.connected;
		$$('.assinafy-tabs, .assinafy-panel').forEach((node) => {
			if (!state.connected) node.hidden = true;
		});
		if (state.connected) {
			$('.assinafy-tabs').hidden = false;
			$('[data-panel-content="send"]').hidden = false;
			$('#assinafy-connection-state').textContent = tr('Connected');
			$('#assinafy-connection-state').className = 'assinafy-status is-connected';
			$('#assinafy-connection-copy').textContent = tr('{workspace} · {user}', {
				workspace: account.name || tr('Assinafy workspace'),
				user: account.user || account.email || tr('Connected user'),
			});
			loadFiles('').catch(report);
		} else {
			$('#assinafy-connection-state').textContent = configured ? tr('Not connected') : tr('Setup required');
			$('#assinafy-connection-state').className = 'assinafy-status';
			$('#assinafy-connection-copy').textContent = configured
				? tr('Connect your account to send and track documents.')
				: tr('An administrator must add the Assinafy OAuth client ID.');
			$('#assinafy-locked p').textContent = configured
				? tr('Connect your account to send and track documents.')
				: tr('Your administrator must configure the OAuth client before you can connect.');
		}
	}

	async function bootstrap() {
		setConnected(await api('/api/bootstrap'));
	}

	async function loadFiles(path) {
		const data = await api('/api/files', {query: {path}});
		state.folder = path;
		$('#assinafy-folder-path').textContent = `/${path}`;
		$('#assinafy-folder-up').hidden = path === '';
		const list = $('#assinafy-file-list');
		list.replaceChildren();
		if (!data.items.length) {
			const empty = document.createElement('p');
			empty.className = 'assinafy-empty';
			empty.textContent = tr('This folder contains no folders or PDF files.');
			list.append(empty);
			return;
		}
		data.items.forEach((item) => {
			if (item.type === 'folder') {
				const folder = document.createElement('button');
				folder.type = 'button';
				folder.className = 'assinafy-file-row is-folder';
				folder.textContent = item.name;
				folder.addEventListener('click', () => loadFiles(item.path).catch(report));
				list.append(folder);
				return;
			}
			const label = document.createElement('label');
			label.className = 'assinafy-file-row';
			const radio = document.createElement('input');
			radio.type = 'radio';
			radio.name = 'assinafy-file';
			radio.value = item.id;
			radio.checked = Number(item.id) === state.fileId;
			radio.addEventListener('change', () => selectFile(item));
			const name = document.createElement('span');
			name.textContent = item.name;
			const size = document.createElement('small');
			size.textContent = formatBytes(item.size);
			label.append(radio, name, size);
			list.append(label);
		});
	}

	function formatBytes(bytes) {
		if (!Number.isFinite(Number(bytes))) return '';
		return new Intl.NumberFormat(document.documentElement.lang || 'en', {style: 'unit', unit: 'megabyte', maximumFractionDigits: 1}).format(Number(bytes) / 1048576);
	}

	function selectFile(item) {
		state.fileId = Number(item.id);
		state.documentId = null;
		state.preparedKey = '';
		$('#assinafy-title').value = String(item.name).replace(/\.pdf$/i, '');
		clearQuote();
	}

	function clearQuote() {
		state.quoteId = '';
		state.quote = null;
		$('#assinafy-quote').hidden = true;
		$('#assinafy-quote').replaceChildren();
		$('#assinafy-send').disabled = true;
	}

	function addRecipient(initial = {}) {
		const container = document.createElement('fieldset');
		container.className = 'assinafy-recipient';
		const legend = document.createElement('legend');
		legend.textContent = tr('Recipient {number}', {number: $$('.assinafy-recipient').length + 1});
		container.append(legend);

		const fields = document.createElement('div');
		fields.className = 'assinafy-recipient-grid';
		fields.append(
			labeledInput(tr('Full name'), 'name', 'text', initial.name || '', true),
			labeledInput(tr('Email'), 'email', 'email', initial.email || '', true),
		);

		const methodLabel = labeledSelect(tr('Signature method'), 'method', [
			['email', tr('Email')],
			['whatsapp', tr('WhatsApp')],
			['biometric', tr('Biometric identity')],
			['certificate', tr('A1 / A3 digital certificate')],
		], initial.method || 'email');
		const notificationLabel = labeledSelect(tr('Notification'), 'notification', [
			['email', tr('Email')],
			['whatsapp', tr('WhatsApp')],
		], initial.notification || (initial.method === 'whatsapp' ? 'whatsapp' : 'email'));
		const method = $('select', methodLabel);
		const notification = $('select', notificationLabel);
		fields.append(methodLabel, notificationLabel);

		const phone = labeledInput(tr('WhatsApp phone'), 'phone', 'tel', initial.phone || '', false);
		phone.classList.add('assinafy-method-field', 'is-phone');
		const taxId = labeledInput(tr('CPF or CNPJ'), 'taxId', 'text', initial.taxId || '', false);
		taxId.classList.add('assinafy-method-field', 'is-tax-id');
		fields.append(phone, taxId);
		container.append(fields);

		const remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'assinafy-remove-recipient';
		remove.textContent = tr('Remove recipient');
		remove.addEventListener('click', () => {
			if ($$('.assinafy-recipient').length === 1) return;
			container.remove();
			renumberRecipients();
			clearQuote();
		});
		container.append(remove);
		method.addEventListener('change', () => {
			toggleRecipientFields(container);
			clearQuote();
		});
		notification.addEventListener('change', () => {
			toggleRecipientFields(container);
			clearQuote();
		});
		$('#assinafy-recipients').append(container);
		toggleRecipientFields(container);
	}

	function labeledInput(labelText, className, type, initial, required) {
		const label = document.createElement('label');
		label.textContent = labelText;
		const input = document.createElement('input');
		input.type = type;
		input.className = className;
		input.value = initial;
		input.required = required;
		if (type === 'email') input.maxLength = 255;
		if (className === 'name') input.maxLength = 255;
		label.append(input);
		return label;
	}

	function labeledSelect(labelText, className, options, initial) {
		const label = document.createElement('label');
		label.textContent = labelText;
		const select = document.createElement('select');
		select.className = className;
		options.forEach(([key, text]) => {
			const option = document.createElement('option');
			option.value = key;
			option.textContent = text;
			option.selected = key === initial;
			select.append(option);
		});
		label.append(select);
		return label;
	}

	function toggleRecipientFields(container) {
		const method = $('.method', container).value;
		const notification = $('.notification', container);
		const phone = $('.is-phone', container);
		const taxId = $('.is-tax-id', container);
		if (method === 'email' || method === 'whatsapp') {
			notification.value = method;
			notification.disabled = true;
		} else {
			notification.disabled = false;
		}
		phone.hidden = notification.value !== 'whatsapp';
		taxId.hidden = !['biometric', 'certificate'].includes(method);
		taxId.firstChild.nodeValue = method === 'biometric' ? tr('CPF') : tr('CPF or CNPJ');
		$('input', phone).required = notification.value === 'whatsapp';
		$('input', taxId).required = ['biometric', 'certificate'].includes(method);
	}

	function renumberRecipients() {
		$$('.assinafy-recipient').forEach((recipient, index) => {
			$('legend', recipient).textContent = tr('Recipient {number}', {number: index + 1});
		});
	}

	function recipients() {
		return $$('.assinafy-recipient').map((recipient) => ({
			name: $('.name', recipient).value.trim(),
			email: $('.email', recipient).value.trim(),
			method: $('.method', recipient).value,
			notification: $('.notification', recipient).value,
			phone: $('.phone', recipient).value.trim(),
			taxId: $('.taxId', recipient).value.trim(),
		}));
	}

	function contractInput(prefix) {
		return {
			counterparty_name: value(`#${prefix}-counterparty`),
			counterparty_taxpayer_id: value(`#${prefix}-tax-id`),
			start_date: value(`#${prefix}-start`),
			end_date: value(`#${prefix}-end`),
			value: value(`#${prefix}-value`),
			currency: value(`#${prefix}-currency`),
			renewal_term_months: value(`#${prefix}-renewal`),
			notice_period_days: value(`#${prefix}-notice`),
			auto_renew: $(`#${prefix}-auto-renew`).checked,
		};
	}

	async function estimateWithRetry(documentId, body) {
		for (let attempt = 0; attempt < 20; attempt += 1) {
			try {
				return await api(`/api/documents/${encodeURIComponent(documentId)}/estimate`, {method: 'POST', body});
			} catch (error) {
				if (!(error instanceof ApiError) || error.code !== 'document_not_ready' || attempt === 19) throw error;
				$('#assinafy-live').textContent = tr('Assinafy is preparing the PDF…');
				await wait(2000);
			}
		}
		throw new ApiError(tr('The document is still processing. Try again.'), 409, 'document_not_ready');
	}

	async function reviewPrice(event) {
		event.preventDefault();
		const form = $('#assinafy-send-form');
		if (!form.reportValidity()) return;
		if (!state.fileId) {
			announce(tr('Choose a PDF file first.'), 'error');
			return;
		}
		const button = $('#assinafy-estimate');
		await withBusy(button, tr('Preparing…'), async () => {
			const title = value('#assinafy-title');
			const key = `${state.fileId}:${title}`;
			if (!state.documentId || state.preparedKey !== key) {
				const document = await api('/api/documents/prepare', {method: 'POST', body: {fileId: state.fileId, title}});
				state.documentId = document.id;
				state.preparedKey = key;
			}
			const quote = await estimateWithRetry(state.documentId, {
				recipients: recipients(),
				ordered: $('#assinafy-ordered').checked,
			});
			state.quoteId = quote.quoteId;
			state.quote = quote.estimate;
			renderQuote();
		});
	}

	function quoteTotal(quote) {
		const lines = Array.isArray(quote.breakdown) ? quote.breakdown : [];
		const lineTotal = lines.reduce((sum, line) => sum + (typeof line.cost === 'number' && line.cost >= 0 ? line.cost : 0), 0);
		const extra = quote.needs_extra_document === true && typeof quote.extra_document_cost === 'number' ? quote.extra_document_cost : 0;
		return Math.max(
			typeof quote.total_credits === 'number' ? quote.total_credits : 0,
			(typeof quote.credits === 'number' ? quote.credits : 0) + extra,
			lineTotal + extra,
		);
	}

	function renderQuote() {
		const quote = state.quote;
		const section = $('#assinafy-quote');
		section.replaceChildren();
		section.hidden = false;
		const heading = document.createElement('h2');
		heading.textContent = tr('Review the Assinafy price');
		const summary = document.createElement('div');
		summary.className = 'assinafy-quote-summary';
		[
			[tr('Estimated credits'), formatNumber(quoteTotal(quote))],
			[tr('Credit balance'), quote.credit_balance === null ? tr('Unlimited') : formatNumber(quote.credit_balance)],
			[tr('Document balance'), quote.document_balance === null ? tr('Unlimited') : formatNumber(quote.document_balance)],
		].forEach(([label, amount]) => {
			const item = document.createElement('div');
			const strong = document.createElement('strong');
			strong.textContent = amount;
			const span = document.createElement('span');
			span.textContent = label;
			item.append(strong, span);
			summary.append(item);
		});
		section.append(heading, summary);

		if (Array.isArray(quote.breakdown) && quote.breakdown.length) {
			const list = document.createElement('ul');
			quote.breakdown.forEach((line) => {
				const item = document.createElement('li');
				item.textContent = `${line.name || line.code || tr('Charge')}: ${formatNumber(line.cost)}`;
				list.append(item);
			});
			section.append(list);
		}
		if (quote.needs_extra_document === true) {
			const note = document.createElement('p');
			note.textContent = tr('This request also uses an additional document allowance at {credits} credits.', {credits: formatNumber(quote.extra_document_cost)});
			section.append(note);
		}
		const sufficient = quote.has_sufficient_resources === true;
		const status = document.createElement('p');
		status.className = sufficient ? 'assinafy-good' : 'assinafy-warning';
		status.textContent = sufficient
			? tr('Resources are available. Review the recipients, then send.')
			: display(quote.blocking_reason || quote.message || tr('The Assinafy workspace does not have enough resources.'));
		section.append(status);
		$('#assinafy-send').disabled = !sufficient;
	}

	async function sendDocument() {
		if (!state.documentId || !state.quoteId) return;
		const button = $('#assinafy-send');
		try {
			const result = await withBusy(button, tr('Sending…'), async () => {
				const details = $('.assinafy-contract-details');
				return api(`/api/documents/${encodeURIComponent(state.documentId)}/send`, {
					method: 'POST',
					body: {
						recipients: recipients(),
						ordered: $('#assinafy-ordered').checked,
						quoteId: state.quoteId,
						message: value('#assinafy-message'),
						expiresInDays: Number($('#assinafy-expiry').value),
						contract: details.open ? contractInput('assinafy-contract') : [],
					},
				});
			});
			if (result.contractError) {
				announce(tr('The document was sent, but its contract terms were not saved: {message}', {message: result.contractError}), 'error');
			} else {
				announce(tr('The document was sent for signature.'), 'success');
			}
			resetSendForm();
		} catch (error) {
			if (error instanceof ApiError && error.code === 'quote_changed') clearQuote();
			throw error;
		}
	}

	function resetSendForm() {
		state.fileId = null;
		state.documentId = null;
		state.preparedKey = '';
		$('#assinafy-send-form').reset();
		$('#assinafy-recipients').replaceChildren();
		addRecipient();
		clearQuote();
		loadFiles(state.folder).catch(report);
	}

	async function loadDocuments() {
		const data = await api('/api/documents', {query: {search: value('#assinafy-document-search'), status: value('#assinafy-document-status'), page: state.documentPage}});
		const rows = data.items.map((item) => {
			const row = document.createElement('tr');
			row.append(
				cell(item.name || item.title || item.id),
				cell(item.status),
				cell(formatDate(item.updated_at || item.created_at)),
				cell(item.id),
			);
			return row;
		});
		$('#assinafy-documents-list').replaceChildren(table(
			[tr('Document'), tr('Status'), tr('Updated'), tr('ID')],
			rows,
			tr('No documents match these filters.'),
		));
		$('#assinafy-documents-prev').disabled = state.documentPage === 1;
		$('#assinafy-documents-next').disabled = data.items.length < 50;
		$('#assinafy-documents-page').textContent = tr('Page {number}', {number: state.documentPage});
	}

	function documentIdFor(contract) {
		return contract.document_id || (contract.document && contract.document.id) || '';
	}

	async function loadContracts() {
		const data = await api('/api/contracts', {query: {search: value('#assinafy-contract-search'), status: value('#assinafy-contract-status'), page: state.contractPage}});
		state.contracts = data.items;
		const rows = data.items.map((contract, index) => {
			const row = document.createElement('tr');
			row.append(
				cell(contract.counterparty_name || (contract.document && contract.document.name) || documentIdFor(contract)),
				cell(contract.status),
				cell(formatDate(contract.end_date)),
			);
			const actions = document.createElement('td');
			[['edit', tr('Edit')], ['renew', tr('Renew')], ['terminate', tr('Terminate')]].forEach(([action, label]) => {
				const button = document.createElement('button');
				button.type = 'button';
				button.dataset.contractAction = action;
				button.dataset.contractIndex = String(index);
				button.textContent = label;
				button.disabled = !documentIdFor(contract);
				actions.append(button);
			});
			row.append(actions);
			return row;
		});
		$('#assinafy-contracts-list').replaceChildren(table(
			[tr('Contract'), tr('Status'), tr('End date'), tr('Actions')],
			rows,
			tr('No contracts match these filters.'),
		));
		$('#assinafy-contracts-prev').disabled = state.contractPage === 1;
		$('#assinafy-contracts-next').disabled = data.items.length < 25;
		$('#assinafy-contracts-page').textContent = tr('Page {number}', {number: state.contractPage});
	}

	function editContract(contract) {
		const editor = $('#assinafy-contract-editor');
		$('#assinafy-editor-document-id').value = documentIdFor(contract);
		$('#assinafy-editor-updated-at').value = contract.updated_at || '';
		$('#assinafy-editor-counterparty').value = contract.counterparty_name || '';
		$('#assinafy-editor-tax-id').value = contract.counterparty_taxpayer_id || '';
		$('#assinafy-editor-start').value = contract.start_date || '';
		$('#assinafy-editor-end').value = contract.end_date || '';
		$('#assinafy-editor-value').value = contract.value ?? '';
		$('#assinafy-editor-currency').value = contract.currency || '';
		$('#assinafy-editor-renewal').value = contract.renewal_term_months ?? '';
		$('#assinafy-editor-notice').value = contract.notice_period_days ?? '';
		$('#assinafy-editor-auto-renew').checked = contract.auto_renew === true;
		editor.hidden = false;
		editor.scrollIntoView({behavior: 'smooth', block: 'start'});
	}

	async function contractAction(event) {
		const button = event.target.closest('[data-contract-action]');
		if (!button) return;
		const contract = state.contracts[Number(button.dataset.contractIndex)];
		const documentId = documentIdFor(contract);
		if (button.dataset.contractAction === 'edit') {
			editContract(contract);
			return;
		}
		if (button.dataset.contractAction === 'renew') {
			await api(`/api/documents/${encodeURIComponent(documentId)}/contract/renew`, {method: 'POST', body: {term: Number(contract.term || 1), updatedAt: contract.updated_at || ''}});
			announce(tr('The contract was renewed.'), 'success');
			await loadContracts();
			return;
		}
		$('#assinafy-termination-document-id').value = documentId;
		$('#assinafy-termination-updated-at').value = contract.updated_at || '';
		$('#assinafy-termination-reason').value = '';
		$('#assinafy-termination-editor').hidden = false;
		$('#assinafy-termination-editor').scrollIntoView({behavior: 'smooth', block: 'start'});
	}

	async function saveContract(event) {
		event.preventDefault();
		const documentId = value('#assinafy-editor-document-id');
		const terms = contractInput('assinafy-editor');
		terms.updated_at = value('#assinafy-editor-updated-at');
		await api(`/api/documents/${encodeURIComponent(documentId)}/contract`, {method: 'PUT', body: {terms}});
		$('#assinafy-contract-editor').hidden = true;
		announce(tr('The contract terms were saved.'), 'success');
		await loadContracts();
	}

	async function terminateContract(event) {
		event.preventDefault();
		const documentId = value('#assinafy-termination-document-id');
		await api(`/api/documents/${encodeURIComponent(documentId)}/contract/terminate`, {method: 'POST', body: {
			reason: value('#assinafy-termination-reason'),
			updatedAt: value('#assinafy-termination-updated-at'),
		}});
		$('#assinafy-termination-editor').hidden = true;
		announce(tr('The contract termination was recorded.'), 'success');
		await loadContracts();
	}

	async function loadWebhooks() {
		const [types, endpoints, deliveries] = await Promise.all([
			api('/api/webhooks/event-types'),
			api('/api/webhooks/endpoints'),
			api('/api/webhooks/deliveries'),
		]);
		renderEventTypes(types.items);
		renderEndpoints(endpoints.items);
		renderDeliveries(deliveries.items);
	}

	function renderEventTypes(items) {
		const target = $('#assinafy-webhook-events');
		const checked = new Set($$('input:checked', target).map((input) => input.value));
		target.replaceChildren();
		items.forEach((item) => {
			const code = typeof item === 'string' ? item : item.code || item.id || item.name;
			if (!code) return;
			const label = document.createElement('label');
			label.className = 'assinafy-check';
			const input = document.createElement('input');
			input.type = 'checkbox';
			input.value = code;
			input.checked = checked.has(code);
			const text = document.createElement('span');
			text.textContent = typeof item === 'string' ? item : item.name || item.description || code;
			label.append(input, text);
			target.append(label);
		});
	}

	function renderEndpoints(items) {
		state.webhookEndpoints = items;
		const rows = items.map((endpoint) => {
			const row = document.createElement('tr');
			row.append(cell(endpoint.name || endpoint.url), cell(endpoint.url), cell(endpoint.is_active === false ? tr('Paused') : tr('Active')));
			const actions = document.createElement('td');
			[
				['edit', tr('Edit')],
				['toggle', endpoint.is_active === false ? tr('Resume') : tr('Pause')],
				['signing', endpoint.signing_enabled === true ? tr('Disable signing') : tr('Enable signing')],
				['rotate', tr('Rotate secret')],
				['delete', tr('Delete')],
			].forEach(([action, label]) => {
				const button = document.createElement('button');
				button.type = 'button';
				button.textContent = label;
				button.dataset.webhookAction = action;
				button.dataset.endpointId = endpoint.id;
				button.dataset.endpointIndex = String(state.webhookEndpoints.indexOf(endpoint));
				button.dataset.endpointActive = endpoint.is_active === false ? '0' : '1';
				button.disabled = action === 'rotate' && endpoint.signing_enabled !== true;
				actions.append(button);
			});
			row.append(actions);
			return row;
		});
		$('#assinafy-webhook-endpoints').replaceChildren(table(
			[tr('Endpoint'), tr('URL'), tr('Status'), tr('Actions')], rows, tr('No webhook endpoints are configured.'),
		));
	}

	function renderDeliveries(items) {
		const rows = items.map((delivery) => {
			const row = document.createElement('tr');
			row.append(
				cell(delivery.event || delivery.event_type),
				cell(delivery.delivered === true ? tr('Delivered') : delivery.status || tr('Failed')),
				cell(formatDate(delivery.created_at || delivery.updated_at)),
			);
			const actions = document.createElement('td');
			if (delivery.delivered !== true && delivery.id) {
				const retry = document.createElement('button');
				retry.type = 'button';
				retry.textContent = tr('Retry');
				retry.dataset.deliveryId = delivery.id;
				actions.append(retry);
			}
			row.append(actions);
			return row;
		});
		$('#assinafy-webhook-deliveries').replaceChildren(table(
			[tr('Event'), tr('Result'), tr('Created'), tr('Actions')], rows, tr('No webhook deliveries were returned.'),
		));
	}

	function showSecret(secret) {
		if (typeof secret !== 'string' || !secret) return;
		const box = $('#assinafy-webhook-secret');
		box.replaceChildren();
		const text = document.createElement('p');
		text.textContent = tr('Store this signing secret now. Assinafy will not show it again.');
		const code = document.createElement('code');
		code.textContent = secret;
		const copy = document.createElement('button');
		copy.type = 'button';
		copy.textContent = tr('Copy secret');
		copy.addEventListener('click', async () => {
			await navigator.clipboard.writeText(secret);
			announce(tr('The webhook secret was copied.'), 'success');
		});
		box.append(text, code, copy);
		box.hidden = false;
	}

	async function createWebhook(event) {
		event.preventDefault();
		const events = $$('#assinafy-webhook-events input:checked').map((input) => input.value);
		if (!events.length) {
			announce(tr('Choose at least one webhook event.'), 'error');
			return;
		}
		const fields = {
			name: value('#assinafy-webhook-name'),
			url: value('#assinafy-webhook-url'),
			email: value('#assinafy-webhook-email'),
			events,
		};
		const data = state.editingEndpointId
			? await api(`/api/webhooks/endpoints/${encodeURIComponent(state.editingEndpointId)}`, {method: 'PUT', body: {changes: {
				...fields,
				is_active: state.webhookEndpoints.find(({id}) => id === state.editingEndpointId)?.is_active !== false,
				signing_enabled: $('#assinafy-webhook-signing').checked,
			}}})
			: await api('/api/webhooks/endpoints', {method: 'POST', body: {
				...fields,
				isActive: true,
				signingEnabled: $('#assinafy-webhook-signing').checked,
			}});
		showSecret(data.secret);
		announce(state.editingEndpointId ? tr('The webhook endpoint was updated.') : tr('The webhook endpoint was created.'), 'success');
		resetWebhookEditor();
		await loadWebhooks();
	}

	function editWebhook(endpoint) {
		state.editingEndpointId = endpoint.id;
		$('#assinafy-webhook-name').value = endpoint.name || '';
		$('#assinafy-webhook-url').value = endpoint.url || '';
		$('#assinafy-webhook-email').value = endpoint.email || '';
		$('#assinafy-webhook-signing').checked = endpoint.signing_enabled === true;
		const events = new Set(Array.isArray(endpoint.events) ? endpoint.events : []);
		$$('#assinafy-webhook-events input').forEach((input) => { input.checked = events.has(input.value); });
		$('#assinafy-webhook-submit').textContent = tr('Save endpoint');
		$('#assinafy-webhook-cancel').hidden = false;
		$('#assinafy-webhook-form').scrollIntoView({behavior: 'smooth', block: 'start'});
	}

	function resetWebhookEditor() {
		state.editingEndpointId = null;
		$('#assinafy-webhook-form').reset();
		$$('#assinafy-webhook-events input').forEach((input) => { input.checked = false; });
		$('#assinafy-webhook-submit').textContent = tr('Create endpoint');
		$('#assinafy-webhook-cancel').hidden = true;
	}

	async function endpointAction(event) {
		const button = event.target.closest('[data-webhook-action]');
		if (!button) return;
		const id = encodeURIComponent(button.dataset.endpointId);
		const endpoint = state.webhookEndpoints[Number(button.dataset.endpointIndex)];
		if (button.dataset.webhookAction === 'edit') {
			editWebhook(endpoint);
			return;
		}
		if (button.dataset.webhookAction === 'toggle') {
			await api(`/api/webhooks/endpoints/${id}`, {method: 'PUT', body: {changes: {is_active: button.dataset.endpointActive !== '1'}}});
			await loadWebhooks();
			return;
		}
		if (button.dataset.webhookAction === 'signing') {
			const data = await api(`/api/webhooks/endpoints/${id}`, {method: 'PUT', body: {changes: {signing_enabled: endpoint.signing_enabled !== true}}});
			showSecret(data.secret);
			announce(data.signing_enabled === true ? tr('Webhook signing was enabled.') : tr('Webhook signing was disabled.'), 'success');
			await loadWebhooks();
			return;
		}
		if (button.dataset.webhookAction === 'rotate') {
			if (!window.confirm(tr('Rotate this webhook secret? Existing verifiers must be updated.'))) return;
			const data = await api(`/api/webhooks/endpoints/${id}/secret/rotate`, {method: 'POST', body: {}});
			showSecret(data.secret);
			return;
		}
		if (!window.confirm(tr('Delete this webhook endpoint?'))) return;
		await api(`/api/webhooks/endpoints/${id}`, {method: 'DELETE'});
		announce(tr('The webhook endpoint was deleted.'), 'success');
		await loadWebhooks();
	}

	async function retryDelivery(event) {
		const button = event.target.closest('[data-delivery-id]');
		if (!button) return;
		await api(`/api/webhooks/deliveries/${encodeURIComponent(button.dataset.deliveryId)}/retry`, {method: 'POST', body: {}});
		announce(tr('The webhook delivery was queued again.'), 'success');
		await loadWebhooks();
	}

	async function changePanel(panel) {
		$$('.assinafy-tab').forEach((tab) => {
			const active = tab.dataset.panel === panel;
			tab.classList.toggle('is-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		$$('[data-panel-content]').forEach((content) => { content.hidden = content.dataset.panelContent !== panel; });
		if (panel === 'documents') await loadDocuments();
		if (panel === 'contracts') await loadContracts();
		if (panel === 'webhooks') await loadWebhooks();
	}

	$('#assinafy-connect').addEventListener('click', async (event) => {
		try {
			const data = await withBusy(event.currentTarget, tr('Connecting…'), () => api('/oauth/connect', {method: 'POST', body: {}}));
			window.location.assign(data.url);
		} catch (error) { report(error); }
	});
	$('#assinafy-disconnect').addEventListener('click', async () => {
		if (!window.confirm(tr('Disconnect this Nextcloud user from Assinafy?'))) return;
		try {
			await api('/oauth/disconnect', {method: 'POST', body: {}});
			await bootstrap();
		} catch (error) { report(error); }
	});
	$('.assinafy-tabs').addEventListener('click', (event) => {
		const tab = event.target.closest('[data-panel]');
		if (tab) changePanel(tab.dataset.panel).catch(report);
	});
	$('#assinafy-folder-up').addEventListener('click', () => loadFiles(state.folder.split('/').slice(0, -1).join('/')).catch(report));
	$('#assinafy-add-recipient').addEventListener('click', () => {
		addRecipient();
		clearQuote();
	});
	$('#assinafy-send-form').addEventListener('submit', (event) => reviewPrice(event).catch(report));
	$('#assinafy-send').addEventListener('click', () => sendDocument().catch(report));
	$('#assinafy-title').addEventListener('input', () => {
		state.documentId = null;
		state.preparedKey = '';
		clearQuote();
	});
	$('#assinafy-recipients').addEventListener('input', clearQuote);
	$('#assinafy-ordered').addEventListener('change', clearQuote);
	$('#assinafy-refresh-documents').addEventListener('click', () => loadDocuments().catch(report));
	$('#assinafy-document-search').addEventListener('change', () => { state.documentPage = 1; loadDocuments().catch(report); });
	$('#assinafy-document-status').addEventListener('change', () => { state.documentPage = 1; loadDocuments().catch(report); });
	$('#assinafy-documents-prev').addEventListener('click', () => { state.documentPage = Math.max(1, state.documentPage - 1); loadDocuments().catch(report); });
	$('#assinafy-documents-next').addEventListener('click', () => { state.documentPage += 1; loadDocuments().catch(report); });
	$('#assinafy-refresh-contracts').addEventListener('click', () => loadContracts().catch(report));
	$('#assinafy-contract-search').addEventListener('change', () => { state.contractPage = 1; loadContracts().catch(report); });
	$('#assinafy-contract-status').addEventListener('change', () => { state.contractPage = 1; loadContracts().catch(report); });
	$('#assinafy-contracts-prev').addEventListener('click', () => { state.contractPage = Math.max(1, state.contractPage - 1); loadContracts().catch(report); });
	$('#assinafy-contracts-next').addEventListener('click', () => { state.contractPage += 1; loadContracts().catch(report); });
	$('#assinafy-contracts-list').addEventListener('click', (event) => contractAction(event).catch(report));
	$('#assinafy-contract-editor').addEventListener('submit', (event) => saveContract(event).catch(report));
	$('#assinafy-editor-cancel').addEventListener('click', () => { $('#assinafy-contract-editor').hidden = true; });
	$('#assinafy-termination-editor').addEventListener('submit', (event) => terminateContract(event).catch(report));
	$('#assinafy-termination-cancel').addEventListener('click', () => { $('#assinafy-termination-editor').hidden = true; });
	$('#assinafy-webhook-form').addEventListener('submit', (event) => createWebhook(event).catch(report));
	$('#assinafy-webhook-cancel').addEventListener('click', resetWebhookEditor);
	$('#assinafy-refresh-webhooks').addEventListener('click', () => loadWebhooks().catch(report));
	$('#assinafy-webhook-endpoints').addEventListener('click', (event) => endpointAction(event).catch(report));
	$('#assinafy-webhook-deliveries').addEventListener('click', (event) => retryDelivery(event).catch(report));

	addRecipient();
	const flashError = root.dataset.flashError;
	if (flashError) announce(flashError, 'error');
	bootstrap().catch(report);
}());
