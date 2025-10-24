<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Cách 1: dùng đúng 1 origin từ ENV
    'allowed_origins' => [env('FRONTEND_ORIGIN', 'https://the-thao-fe.vercel.app')],

    // Cách 2 (tuỳ chọn, nếu bạn có nhiều subdomain vercel): dùng regex
    // 'allowed_origins_patterns' => ['#^https://.*\.vercel\.app$#i'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
