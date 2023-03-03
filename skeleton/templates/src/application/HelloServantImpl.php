<?php

declare(strict_types=1);

namespace {namespace}\application;

use kuiper\di\attribute\Service;
use {namespace}\servant\HelloServant;

#[Service]
class HelloServantImpl implements HelloServant
{
    /**
     * {@inheritdoc}
     */
    public function say(string $message): string
    {
        return "hello $message";
    }
}
