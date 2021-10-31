<?php

declare(strict_types=1);

use function kuiper\helper\env;
use kuiper\web\handler\IncludeStacktrace;
use kuiper\web\middleware\Session as SessionMiddleware;
use kuiper\web\middleware\HealthyStatus;
use Slim\Middleware\BodyParsingMiddleware;
use Slim\Middleware\ErrorMiddleware;

return [
    'application' => [
        'server' => [
            'ports' => [
                {port} => '{ServerType}'
            ],
        ],
        'web' => [
            'view' => [
                'path' => '{application.base_path}/resources/views',
                'cache' => false,
                'globals' => []
            ],
            'error' => [
                'include_stacktrace' => 'true' === env('APP_DEBUG_MODE')
                     ? IncludeStacktrace::ALWAYS
                     : IncludeStacktrace::ON_TRACE_PARAM,
                'display_error' => 'true' === env('APP_DEBUG_MODE')
            ],
            'middleware' => [
                HealthyStatus::class,
                SessionMiddleware::class,
                BodyParsingMiddleware::class,
                ErrorMiddleware::class,
            ],
        ],
        'cache' => [
            'namespace' => env('APP_CACHE_PREFIX'),
            'lifetime' => (int) env('APP_CACHE_LIFETIME'),
        ],
        'redis' => [
            'host' => env('REDIS_HOST', 'localhost'),
            'port' => (int) env('REDIS_PORT', '6379'),
            'password' => env('REDIS_PASSWORD'),
        ],
    ],
];
