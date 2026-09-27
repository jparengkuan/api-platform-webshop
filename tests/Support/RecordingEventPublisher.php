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
     * @param \Closure(IntegrationEvent): void|null $onPublish called for every event, e.g. to check state at that moment
     */
    public function __construct(private readonly ?\Closure $onPublish = null)
    {
    }

    /**
     * Records the event instead of sending it.
     */
    public function publish(IntegrationEvent $event): void
    {
        $this->published[] = $event;
        if (null !== $this->onPublish) {
            ($this->onPublish)($event);
        }
    }
}
