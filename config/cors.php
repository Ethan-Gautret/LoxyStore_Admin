<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // FRONTEND_URL peut contenir plusieurs origines séparées par des virgules
    // (ex. apex + www en prod). On garde le localhost Vite pour le dev.
    'allowed_origins' => array_values(array_filter(array_map('trim', array_merge(
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')),
        ['http://127.0.0.1:5173'],
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
