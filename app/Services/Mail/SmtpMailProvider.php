<?php

namespace App\Services\Mail;

/**
 * A provider reached over plain SMTP with a username and password.
 */
abstract class SmtpMailProvider extends MailProvider
{
    use TestsSmtpConnections;

    /**
     * @param  array<string, mixed>  $config
     * @return array{host: string, port: int, username: string, password: string}
     */
    abstract protected function smtpSettings(array $config): array;

    public function mailerConfig(array $config): array
    {
        $smtp = $this->smtpSettings($config);

        return [
            'transport' => 'smtp',
            'host' => $smtp['host'],
            'port' => $smtp['port'],
            // 465 is implicit TLS; anything else upgrades with STARTTLS.
            'encryption' => $smtp['port'] === 465 ? 'ssl' : 'tls',
            'username' => $smtp['username'],
            'password' => $smtp['password'],
            'timeout' => 30,
        ];
    }

    public function testConnection(array $config): ConnectionTestResult
    {
        if ($missing = array_diff($this->missingFields($config), ['from_email'])) {
            return ConnectionTestResult::failed('Missing required field(s): ' . implode(', ', $missing));
        }

        $smtp = $this->smtpSettings($config);

        return $this->testSmtpConnection($smtp['host'], $smtp['port'], $smtp['username'], $smtp['password']);
    }
}
