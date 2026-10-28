<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The mail actually handed to the `array` transport (phpunit.xml: MAIL_MAILER=array).
 *
 * `Mail::fake()` does not record `Mail::raw()`, so tests that need an invitation link
 * or must prove a password was never mailed read the transport instead.
 */
trait ReadsSentMail
{
    /**
     * @return list<array{to: list<string>, subject: string, body: string}>
     */
    protected function sentMail(): array
    {
        /** @var TransportInterface $transport */
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();

        if (! method_exists($transport, 'messages')) {
            return [];
        }

        $mail = [];

        foreach ($transport->messages() as $sent) {
            /** @var SentMessage $sent */
            $message = $sent->getOriginalMessage();

            if ($message instanceof Email) {
                $mail[] = [
                    'to' => array_map(static fn (Address $address): string => $address->getAddress(), $message->getTo()),
                    'subject' => (string) $message->getSubject(),
                    'body' => (string) $message->getTextBody(),
                ];
            }
        }

        return $mail;
    }

    protected function flushSentMail(): void
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();

        if (method_exists($transport, 'flush')) {
            $transport->flush();
        }
    }
}
