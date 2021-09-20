<?php

namespace {namespace}\application\controller;

use kuiper\di\annotation\Controller;
use kuiper\web\AbstractController;
use kuiper\web\annotation\GetMapping;

/**
 * @Controller
 */
class IndexController extends AbstractController
{
    /**
     * @GetMapping("/")
     */
    public function index(): void
    {
        $this->getResponse()->getBody()->write("<h1>It works!</h1>\n");
    }
}
