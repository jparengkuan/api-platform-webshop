<?php

namespace App\Identity\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The login page of the OAuth2 authorization flow: /authorize sends users here when they aren't
 * logged in yet, and returns them to the client application afterwards.
 */
final class SecurityController extends AbstractController
{
    /**
     * Shows the login form; the "main" firewall's form_login handles the submitted credentials.
     */
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Never executed: the "main" firewall's logout listener handles this route.
     */
    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('This route is handled by the "main" firewall.');
    }
}
