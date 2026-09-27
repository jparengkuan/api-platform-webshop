<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

final class LoginController extends AbstractController
{
    /**
     * Never executed: the "login" firewall's json_login intercepts this request
     * and answers with a JWT (success) or a 401 (failure).
     */
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function __invoke(): never
    {
        throw new \LogicException('This route is handled by the "login" firewall.');
    }
}
