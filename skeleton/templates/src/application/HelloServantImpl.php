<?php

declare(strict_types=1);


namespace {namespace}\application;

use kuiper\di\annotation\Service;
use {namespace}\servant\HelloServant;

/**
 * @Service
 */
class HelloServantImpl implements HelloServant
{
    /**
     * {@inheritdoc}
     */
    public function hello(string $message): string
    {
        return "hello $message";
    }
}
