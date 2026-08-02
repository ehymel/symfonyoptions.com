<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Holds messages until the response has been handed to the client, then delivers them
 * inline on kernel.terminate (see App\EventListener\DeferredMailFlushListener).
 *
 * This keeps a slow SMTP round-trip out of the measurable response time without
 * requiring a messenger worker. Use it where send latency would leak something about
 * the request; ordinary mail should keep using MailerInterface directly.
 */
final class DeferredMailer
{
    /** @var list<array{RawMessage, ?Envelope}> */
    private array $pending = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function defer(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->pending[] = [$message, $envelope];
    }

    public function flush(): void
    {
        // Detach before sending so a throwing send cannot leave a message queued for a
        // later flush in the same process.
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as [$message, $envelope]) {
            try {
                $this->mailer->send($message, $envelope);
            } catch (TransportExceptionInterface $e) {
                // The response is already gone, so there is no way to tell the user.
                // The log is the only record that this send failed.
                $this->logger->error('Deferred email delivery failed: {reason}', [
                    'reason' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
