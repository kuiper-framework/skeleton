<?php

declare(strict_types=1);

use function kuiper\helper\env;

return [
    'application' => [
        'server' => [
            'ports' => [
                {port} => '{ServerType}'
            ],
        ],
    ],
];
