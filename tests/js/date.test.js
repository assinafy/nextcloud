'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {formatDate} = require('../../js/app.js');

test('date-only values stay on their local calendar date', () => {
	assert.equal(Intl.DateTimeFormat().resolvedOptions().timeZone, 'America/Sao_Paulo');
	assert.equal(formatDate('2026-01-01', 'en'), 'Jan 1, 2026');
	assert.equal(formatDate('2026-01-01T00:00:00Z', 'en'), 'Dec 31, 2025');
});
