<?php

namespace App\Controller;

use App\Messenger\CommandBus;
use App\Payment\StripeWebhookParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Stripe's webhook calls. Public, but only calls signed with the endpoint's secret are accepted.
 */
final class StripeWebhookController extends AbstractController
{
    /**
     * Confirms paid orders; answers 2xx for every valid call, so Stripe stops retrying it.
     */
    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request, StripeWebhookParser $parser, CommandBus $commandBus): Response
    {
        try {
            $payment = $parser->parse($request->getContent(), (string) $request->headers->get('Stripe-Signature'));
        } catch (\UnexpectedValueException) {
            return new Response('Invalid webhook call.', Response::HTTP_BAD_REQUEST);
        }

        if (null !== $payment) {
            $commandBus->dispatch($payment);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
