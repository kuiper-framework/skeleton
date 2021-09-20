<?php

declare(strict_types=1);

namespace {namespace}\service;

use kuiper\jsonrpc\annotation\JsonRpcService;

/**
 * @JsonRpcService
 */
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
