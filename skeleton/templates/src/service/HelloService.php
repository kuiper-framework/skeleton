<?php

declare(strict_types=1);

namespace {namespace}\service;

use kuiper\jsonrpc\attribute\JsonRpcService;

#[JsonRpcService]
interface HelloService
{
    /**
     * @param string $message
     *
     * @return string
     */
    public function hello(string $message): string;
}
