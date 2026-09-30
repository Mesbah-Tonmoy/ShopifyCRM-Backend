<?php

namespace App\Services\Mail\Ses;

use Aws\SesV2\SesV2Client;
use Aws\Sts\StsClient;

/**
 * Builds AWS clients from a provider's saved settings rather than from env.
 *
 * A seam as much as a factory: tests bind an instance carrying an
 * Aws\MockHandler, so no test ever reaches AWS.
 */
class SesClientFactory
{
    /**
     * @param  callable|null  $handler  Guzzle-style handler; tests pass an Aws\MockHandler.
     */
    public function __construct(private $handler = null)
    {
    }

    /**
     * @param  array<string, mixed>  $config  SES provider settings.
     */
    public function ses(array $config): SesV2Client
    {
        return new SesV2Client($this->clientArgs($config));
    }

    /**
     * @param  array<string, mixed>  $config  SES provider settings.
     */
    public function sts(array $config): StsClient
    {
        return new StsClient($this->clientArgs($config));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function clientArgs(array $config): array
    {
        $args = [
            'version' => 'latest',
            'region' => (string) ($config['region'] ?? ''),
            'credentials' => [
                'key' => (string) ($config['access_key_id'] ?? ''),
                'secret' => (string) ($config['secret_access_key'] ?? ''),
            ],
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
        ];

        if ($this->handler) {
            $args['handler'] = $this->handler;
        }

        return $args;
    }
}
