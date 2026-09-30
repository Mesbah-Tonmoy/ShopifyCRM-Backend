<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Connect, negotiate TLS and authenticate against an SMTP server, then hang up.
 * No message is sent.
 */
trait TestsSmtpConnections
{
    /**
     * 465 (and SES's alternate 2465) are implicit TLS; other ports upgrade with STARTTLS.
     */
    protected static function isImplicitTls(int $port): bool
    {
        return in_array($port, [465, 2465], true);
    }

    protected function testSmtpConnection(string $host, int $port, string $username, string $password): ConnectionTestResult
    {
        $transport = new EsmtpTransport($host, $port, self::isImplicitTls($port) ? true : null);
        $transport->setUsername($username);
        $transport->setPassword($password);

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(10);
        }

        try {
            $transport->start();
            $transport->stop();
        } catch (TransportExceptionInterface $e) {
            return ConnectionTestResult::failed("Could not authenticate with {$host}:{$port}: " . $e->getMessage());
        }

        return ConnectionTestResult::passed("Connected and authenticated with {$host}:{$port}. No email was sent.");
    }
}
