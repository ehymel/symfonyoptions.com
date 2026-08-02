<?php

namespace App\EventListener;

use App\Service\DeferredMailer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Delivers anything queued on DeferredMailer once the response has been sent.
 *
 * Under PHP-FPM the client already has the full response by the time this runs, so the
 * SMTP round-trip costs the user nothing and reveals nothing.
 */
#[AsEventListener(event: TerminateEvent::class)]
final readonly class DeferredMailFlushListener
{
    public function __construct(
        private DeferredMailer $deferredMailer,
    ) {}

    public function __invoke(TerminateEvent $event): void
    {
        $this->deferredMailer->flush();
    }
}
