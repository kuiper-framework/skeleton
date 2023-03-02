<?php

declare(strict_types=1);

namespace {namespace}\service;

use kuiper\di\attribute\Service;

#[Service]
class HelloServiceImpl implements HelloService
{
    /**
     * {@inheritdoc}
     */
    public function hello(string $message): string
    {
        return "hello $message";
    }
}
