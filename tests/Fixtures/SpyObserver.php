<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Fixtures;

use Superscript\Axiom\Execution\Annotated;
use Superscript\Axiom\Execution\Event;
use Superscript\Axiom\Execution\Observer;

final class SpyObserver implements Observer
{
    /** @var list<Event> */
    public array $events = [];

    /** @var array<string, mixed> */
    public array $annotations = [];

    /** @var list<Annotated> */
    public array $annotated = [];

    public function observe(Event $event): void
    {
        $this->events[] = $event;

        if (!$event instanceof Annotated) {
            return;
        }

        $this->annotations[$event->key] = $event->value;
        $this->annotated[] = $event;
    }
}
