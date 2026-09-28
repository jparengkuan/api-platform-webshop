<?php

namespace App\Tests\Support;

use App\Message\IntegrationEvent;
use App\Messenger\EventPublisherInterface;

/**
 * Stands in for the event publisher in unit tests: records what would be published.
 */
final class RecordingEventPublisher implements EventPublisherInterface
{
    /** @var list<IntegrationEvent> */
    public array $published = [];

    /**
     * Records the event instead of storing it in the outbox.
     */
    public function publish(IntegrationEvent $event): void
    {
        $this->published[] = $event;
    }
}
