<?php

namespace App\Shared\Application;

use App\Shared\Application\Event\IntegrationEvent;

/**
 * Port through which use cases announce what happened, without knowing about the message broker.
 */
interface EventPublisherInterface
{
    /**
     * Publishes the event. Never throws: a delivery failure must not undo what the caller did, so
     * the implementation reports it instead (at-most-once delivery).
     */
    public function publish(IntegrationEvent $event): void;
}
