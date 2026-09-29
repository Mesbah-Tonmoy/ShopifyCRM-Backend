<?php

namespace App\Services\Mail\Providers;

use App\Services\Mail\SmtpMailProvider;

class MailtrapProvider extends SmtpMailProvider
{
    public const DEFAULT_HOST = 'live.smtp.mailtrap.io';

    public const DEFAULT_PORT = 587;

    public function key(): string
    {
        return 'mailtrap';
    }

    public function name(): string
    {
        return 'Mailtrap';
    }

    public function description(): string
    {
        return 'Send transactional email through Mailtrap SMTP.';
    }

    protected function connectionFields(): array
    {
        return [
            ['key' => 'username', 'label' => 'SMTP Username', 'type' => 'text', 'required' => true, 'rules' => ['max:191']],
            ['key' => 'password', 'label' => 'SMTP Password', 'type' => 'password', 'required' => true, 'secret' => true],
            ['key' => 'host', 'label' => 'SMTP Host', 'type' => 'text', 'placeholder' => self::DEFAULT_HOST, 'help' => 'Use sandbox.smtp.mailtrap.io to capture mail instead of delivering it.', 'rules' => ['max:191']],
            ['key' => 'port', 'label' => 'SMTP Port', 'type' => 'number', 'placeholder' => (string) self::DEFAULT_PORT, 'rules' => ['integer', 'between:1,65535']],
        ];
    }

    protected function smtpSettings(array $config): array
    {
        return [
            'host' => filled($config['host'] ?? null) ? (string) $config['host'] : self::DEFAULT_HOST,
            'port' => filled($config['port'] ?? null) ? (int) $config['port'] : self::DEFAULT_PORT,
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
        ];
    }
}
