<?php

namespace App\Services\Mail;

/**
 * Outcome of checking a provider's settings. Nothing is sent to produce one.
 */
final class ConnectionTestResult
{
    /**
     * @param  array<int, string>  $warnings  Usable, but worth knowing (e.g. SES sandbox).
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $warnings = [],
        public readonly array $details = [],
    ) {
    }

    public static function passed(string $message, array $warnings = [], array $details = []): self
    {
        return new self(true, $message, $warnings, $details);
    }

    public static function failed(string $message, array $details = []): self
    {
        return new self(false, $message, [], $details);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'warnings' => $this->warnings,
            'details' => $this->details,
        ];
    }
}
