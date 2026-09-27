<?php

namespace App\Message;

/**
 * An event exchanged with other systems through RabbitMQ (the webshop.events exchange).
 *
 * Each event names its own routing key, so publishing a new kind of event needs no change to the
 * publisher.
 */
interface IntegrationEvent
{
    /**
     * Returns the routing key on the webshop.events exchange, e.g. "order.placed".
     */
    public function routingKey(): string;
}
