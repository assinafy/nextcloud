<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'auth#connect', 'url' => '/oauth/connect', 'verb' => 'POST'],
        ['name' => 'auth#callback', 'url' => '/oauth/callback', 'verb' => 'GET'],
        ['name' => 'auth#disconnect', 'url' => '/oauth/disconnect', 'verb' => 'POST'],
        ['name' => 'settings#save', 'url' => '/settings', 'verb' => 'POST'],
        ['name' => 'api#bootstrap', 'url' => '/api/bootstrap', 'verb' => 'GET'],
        ['name' => 'api#files', 'url' => '/api/files', 'verb' => 'GET'],
        ['name' => 'api#prepareDocument', 'url' => '/api/documents/prepare', 'verb' => 'POST'],
        ['name' => 'api#estimateDocument', 'url' => '/api/documents/{documentId}/estimate', 'verb' => 'POST'],
        ['name' => 'api#sendDocument', 'url' => '/api/documents/{documentId}/send', 'verb' => 'POST'],
        ['name' => 'api#documents', 'url' => '/api/documents', 'verb' => 'GET'],
        ['name' => 'api#contracts', 'url' => '/api/contracts', 'verb' => 'GET'],
        ['name' => 'api#saveContract', 'url' => '/api/documents/{documentId}/contract', 'verb' => 'PUT'],
        ['name' => 'api#renewContract', 'url' => '/api/documents/{documentId}/contract/renew', 'verb' => 'POST'],
        ['name' => 'api#terminateContract', 'url' => '/api/documents/{documentId}/contract/terminate', 'verb' => 'POST'],
        ['name' => 'api#webhookEventTypes', 'url' => '/api/webhooks/event-types', 'verb' => 'GET'],
        ['name' => 'api#webhookEndpoints', 'url' => '/api/webhooks/endpoints', 'verb' => 'GET'],
        ['name' => 'api#createWebhookEndpoint', 'url' => '/api/webhooks/endpoints', 'verb' => 'POST'],
        ['name' => 'api#updateWebhookEndpoint', 'url' => '/api/webhooks/endpoints/{endpointId}', 'verb' => 'PUT'],
        ['name' => 'api#deleteWebhookEndpoint', 'url' => '/api/webhooks/endpoints/{endpointId}', 'verb' => 'DELETE'],
        ['name' => 'api#rotateWebhookSecret', 'url' => '/api/webhooks/endpoints/{endpointId}/secret/rotate', 'verb' => 'POST'],
        ['name' => 'api#webhookDeliveries', 'url' => '/api/webhooks/deliveries', 'verb' => 'GET'],
        ['name' => 'api#retryWebhookDelivery', 'url' => '/api/webhooks/deliveries/{deliveryId}/retry', 'verb' => 'POST'],
    ],
];
