<?php

namespace App\Messenger;

use App\Message\IntegrationEvent;

/**
 * Port through which use cases announce what happened, without knowing about the message broker.
 */
interface EventPublisherInterface
{
    /**
     * Records the event as part of the running transaction. It's delivered to other systems once
     * that transaction has committed, and never when it's rolled back (at-least-once delivery).
     */
    public function publish(IntegrationEvent $event): void;
}
