<?php

return [

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Habilite explicitamente apenas em desenvolvimento/testes.
        'mock_enabled' => env('STRIPE_MOCK_ENABLED', true),
    ],

    'osrm' => [
        'endpoint' => env('OSRM_ENDPOINT', 'https://router.project-osrm.org'),
    ],

];