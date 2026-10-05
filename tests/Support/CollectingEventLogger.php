<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Support;

use SelestRecaptcha\Log\EventLoggerInterface;
use SelestRecaptcha\Log\VerificationEvent;

/**
 * Keeps every event so tests can assert on what the merchant would see.
 */
final class CollectingEventLogger implements EventLoggerInterface
{
    /** @var array<int, VerificationEvent> */
    public array $events = [];

    public function log(VerificationEvent $event): void
    {
        $this->events[] = $event;
    }

    public function last(): ?VerificationEvent
    {
        return $this->events === [] ? null : $this->events[count($this->events) - 1];
    }

    public function count(): int
    {
        return count($this->events);
    }
}