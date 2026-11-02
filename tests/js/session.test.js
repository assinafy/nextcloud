'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {endsSession} = require('../../js/app.js');

test('only a lost Assinafy session ends the connected state', () => {
	assert.equal(endsSession(401, 'session_expired'), true);
	assert.equal(endsSession(401, 'not_connected'), true);
	assert.equal(endsSession(401, 'not_authenticated'), false);
	assert.equal(endsSession(403, 'session_expired'), false);
});
