<?php

declare(strict_types=1);

use kuiper\tars\TarsApplication;

define('APP_PATH', dirname(__DIR__));

require APP_PATH.'/vendor/autoload.php';

TarsApplication::run();
