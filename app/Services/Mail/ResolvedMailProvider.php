<?php

namespace App\Services\Mail;

/**
 * The active provider, with its mailer registered and ready for Mail::mailer().
 */
final class ResolvedMailProvider
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $headers  Added to every message (e.g. X-SES-TENANT).
     */
    public function __construct(
        public readonly MailProvider $provider,
        public readonly array $config,
        public readonly string $mailer,
        public readonly array $headers = [],
        public readonly ?string $tenant = null,
    ) {
    }
}
