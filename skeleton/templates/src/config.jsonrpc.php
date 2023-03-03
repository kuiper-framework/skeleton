<?php

declare(strict_types=1);

use function kuiper\helper\env;

return [
    'application' => [
        'server' => [
            'ports' => [
                env('SERVER_PORT', '{port}') => [
                    'protocol' => '{ServerType}',
                    'listener' => '{JsonRpcListener}'
                ]
            ],
        ],
    ],
];
