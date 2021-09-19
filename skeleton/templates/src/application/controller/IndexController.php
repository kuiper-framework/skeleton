<?php

namespace {namespace}\application\controller;

use kuiper\di\annotation\Controller;
use kuiper\web\AbstractController;
use kuiper\web\annotation\GetMapping;
use Slim\Exception\HttpUnauthorizedException;

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
        $this->getResponse()->getBody()->write("<h1>it works!</h1>\n");
    }
}
