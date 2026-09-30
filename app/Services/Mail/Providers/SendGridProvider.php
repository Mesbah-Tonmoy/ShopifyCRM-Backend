<?php

namespace App\Services\Mail\Providers;

use App\Services\Mail\SmtpMailProvider;

class SendGridProvider extends SmtpMailProvider
{
    public function key(): string
    {
        return 'sendgrid';
    }

    public function name(): string
    {
        return 'SendGrid';
    }

    public function description(): string
    {
        return 'Send transactional email through SendGrid SMTP relay.';
    }

    protected function connectionFields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true, 'help' => 'Needs the "Mail Send" permission.'],
        ];
    }

    protected function smtpSettings(array $config): array
    {
        return [
            'host' => 'smtp.sendgrid.net',
            'port' => 587,
            'username' => 'apikey',
            'password' => (string) ($config['api_key'] ?? ''),
        ];
    }
}
