<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     */
    protected $middleware = [
    // Otros middlewares
    \Illuminate\Http\Middleware\HandleCors::class,
];


    /**
     * The application's route middleware groups.
     */
    protected $middlewareGroups = [
        'web' => [
            // middlewares web
        ],

        'api' => [
            // middlewares api
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];
}
