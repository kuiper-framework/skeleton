<?php

namespace {namespace}\application\controller;

use kuiper\di\attribute\Controller;
use kuiper\web\AbstractController;
use kuiper\web\attribute\GetMapping;

#[Controller]
class IndexController extends AbstractController
{
    #[GetMapping("/")]
    public function index(): void
    {
        $this->getResponse()->getBody()->write("<h1>It works!</h1>\n");
    }
}
