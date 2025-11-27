<?php

return [
    
    'paths' => ['api/*', 'login', 'sanctum/csrf-cookie', 'reuniones/*', 'asistencia/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['http://localhost:4321', 'https://asistenciagob.netlify.app','*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];