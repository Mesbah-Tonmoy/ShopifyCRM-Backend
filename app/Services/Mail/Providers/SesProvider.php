<?php

namespace App\Services\Mail\Providers;

use App\Services\Mail\ConnectionTestResult;
use App\Services\Mail\MailProvider;
use App\Services\Mail\TestsSmtpConnections;
use App\Services\Mail\Ses\SesClientFactory;
use App\Services\Mail\Ses\SesTenantManager;
use Aws\Exception\AwsException;

/**
 * Amazon SES, over either SES SMTP or the SES v2 API.
 *
 * SMTP (the default) matches how the connected apps send: SMTP credentials
 * deliver the mail, and a separate IAM access key is used only to manage the
 * tenant, since SMTP credentials cannot call the API. The configuration set and
 * tenant travel as the X-SES-CONFIGURATION-SET / X-SES-TENANT headers, which the
 * classic endpoint (email-smtp.<region>.amazonaws.com) honours.
 *
 * API sends with the access key alone, which then needs ses:SendEmail. Laravel's
 * `ses-v2` transport merges `options` into every SendEmail call, which is how
 * the configuration set and tenant ride along there.
 */
class SesProvider extends MailProvider
{
    use TestsSmtpConnections;

    public const VIA_SMTP = 'smtp';

    public const VIA_API = 'api';

    /** Ports the SES SMTP endpoint listens on. */
    public const SMTP_PORTS = [587, 2587, 25, 465, 2465];

    public const REGIONS = [
        'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2',
        'ca-central-1', 'sa-east-1',
        'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-central-1', 'eu-central-2', 'eu-north-1', 'eu-south-1',
        'ap-south-1', 'ap-northeast-1', 'ap-northeast-2', 'ap-northeast-3', 'ap-southeast-1', 'ap-southeast-2', 'ap-southeast-3',
        'me-south-1', 'me-central-1', 'il-central-1', 'af-south-1',
        'us-gov-west-1', 'us-gov-east-1',
    ];

    public function __construct(
        private SesClientFactory $clients,
        private SesTenantManager $tenants,
    ) {
    }

    public function key(): string
    {
        return 'ses';
    }

    public function name(): string
    {
        return 'Amazon SES';
    }

    public function description(): string
    {
        return 'Send transactional email through Amazon SES, isolated under this environment\'s SES tenant.';
    }

    protected function connectionFields(): array
    {
        $smtp = ['visible_when' => ['send_via' => self::VIA_SMTP]];

        return [
            ['key' => 'send_via', 'label' => 'Send Via', 'type' => 'select', 'required' => true, 'options' => [self::VIA_SMTP, self::VIA_API], 'option_labels' => [self::VIA_SMTP => 'SES SMTP', self::VIA_API => 'SES API'], 'default' => self::VIA_SMTP, 'help' => 'SMTP uses SES SMTP credentials to send, and the access key only for the tenant. API sends with the access key, which then needs ses:SendEmail.', 'rules' => ['in:' . self::VIA_SMTP . ',' . self::VIA_API]],
            ['key' => 'region', 'label' => 'Region', 'type' => 'select', 'required' => true, 'options' => self::REGIONS, 'default' => 'us-east-1', 'help' => 'SMTP credentials only work in the region they were issued for.', 'rules' => ['in:' . implode(',', self::REGIONS)]],
            ['key' => 'smtp_username', 'label' => 'SMTP Username', 'type' => 'text', 'required' => true, 'placeholder' => 'AKIA...', 'rules' => ['max:191']] + $smtp,
            ['key' => 'smtp_password', 'label' => 'SMTP Password', 'type' => 'password', 'required' => true, 'secret' => true] + $smtp,
            ['key' => 'smtp_port', 'label' => 'SMTP Port', 'type' => 'select', 'options' => array_map('strval', self::SMTP_PORTS), 'default' => '587', 'rules' => ['in:' . implode(',', self::SMTP_PORTS)]] + $smtp,
            ['key' => 'access_key_id', 'label' => 'Access Key ID', 'type' => 'text', 'required' => true, 'required_when' => ['send_via' => self::VIA_API], 'placeholder' => 'AKIA...', 'help' => 'An IAM key. With SMTP it is used only to manage the tenant.', 'rules' => ['regex:/^[A-Z0-9]{16,128}$/']],
            ['key' => 'secret_access_key', 'label' => 'Secret Access Key', 'type' => 'password', 'required' => true, 'required_when' => ['send_via' => self::VIA_API], 'secret' => true],
            ['key' => 'configuration_set', 'label' => 'Configuration Set', 'type' => 'text', 'placeholder' => 'shopify-crm', 'help' => 'Required for the tenant. Also turns on SES event publishing (bounces, complaints, deliveries).', 'rules' => ['regex:/^[A-Za-z0-9_-]{1,64}$/']],
            ['key' => 'identity', 'label' => 'Sending Identity', 'type' => 'text', 'placeholder' => 'Defaults to the From email\'s domain', 'help' => 'The verified domain or address in SES, in this region, that the tenant is allowed to send as.', 'rules' => ['max:320']],
        ];
    }

    public function mailerConfig(array $config): array
    {
        if ($this->viaSmtp($config)) {
            $port = $this->smtpPort($config);

            return [
                'transport' => 'smtp',
                // Set explicitly: Laravel only infers implicit TLS for 465, not SES's 2465.
                'scheme' => self::isImplicitTls($port) ? 'smtps' : 'smtp',
                'host' => $this->smtpHost($config),
                'port' => $port,
                'username' => (string) ($config['smtp_username'] ?? ''),
                'password' => (string) ($config['smtp_password'] ?? ''),
                'timeout' => 30,
            ];
        }

        return [
            'transport' => 'ses-v2',
            'key' => (string) ($config['access_key_id'] ?? ''),
            'secret' => (string) ($config['secret_access_key'] ?? ''),
            'region' => (string) ($config['region'] ?? ''),
            'options' => array_filter([
                'ConfigurationSetName' => $config['configuration_set'] ?? null,
                'TenantName' => $this->tenants->tenantForSend($config),
            ]),
        ];
    }

    /**
     * Over SMTP the configuration set and tenant can only travel as headers.
     */
    public function messageHeaders(array $config): array
    {
        if (! $this->viaSmtp($config)) {
            return [];
        }

        return array_filter([
            'X-SES-CONFIGURATION-SET' => $config['configuration_set'] ?? null,
            'X-SES-TENANT' => $this->tenants->tenantForSend($config),
        ]);
    }

    public function tenantFor(array $config): ?string
    {
        return $this->tenants->tenantForSend($config);
    }

    public function meta(array $config): array
    {
        return ['tenant' => $this->tenants->state($config)];
    }

    /**
     * SMTP: authenticate against the endpoint. Then, with an access key: the
     * account can send and the identity is verified. Nothing is sent.
     */
    public function testConnection(array $config): ConnectionTestResult
    {
        if ($missing = array_diff($this->missingFields($config), ['from_email'])) {
            return ConnectionTestResult::failed('Missing required field(s): ' . implode(', ', $missing));
        }

        $prefix = '';
        $warnings = [];

        if ($this->viaSmtp($config)) {
            $smtp = $this->testSmtpConnection(
                $this->smtpHost($config),
                $this->smtpPort($config),
                (string) $config['smtp_username'],
                (string) $config['smtp_password'],
            );

            if (! $smtp->ok) {
                return $smtp;
            }

            if (blank($config['access_key_id'] ?? null) || blank($config['secret_access_key'] ?? null)) {
                return ConnectionTestResult::passed($smtp->message, ['No access key set, so the account and identity were not checked, and the tenant cannot be provisioned.']);
            }

            $prefix = 'SMTP login accepted. ';
        }

        $region = (string) $config['region'];
        $ses = $this->clients->ses($config);
        $details = [];

        try {
            $account = $ses->getAccount();

            if (! ($account['SendingEnabled'] ?? false)) {
                return ConnectionTestResult::failed("Sending is disabled for this SES account in {$region}.");
            }

            if (! ($account['ProductionAccessEnabled'] ?? false)) {
                $warnings[] = "The account is in the SES sandbox in {$region}: it can only send to verified addresses.";
            }

            $quota = $account['SendQuota'] ?? [];
            $details = [
                'production_access' => (bool) ($account['ProductionAccessEnabled'] ?? false),
                'max_24_hour_send' => $quota['Max24HourSend'] ?? null,
                'sent_last_24_hours' => $quota['SentLast24Hours'] ?? null,
                'max_send_rate' => $quota['MaxSendRate'] ?? null,
            ];
        } catch (AwsException $e) {
            // A tenant-only key may not read the account. Over SMTP that is
            // fine; over the API the same key sends, so say what is missing.
            if ($this->isAccessDenied($e)) {
                $warnings[] = 'The access key cannot call ses:GetAccount, so sandbox status and quota were not checked.';
            } else {
                return ConnectionTestResult::failed("AWS rejected the request in {$region}: " . ($e->getAwsErrorMessage() ?: $e->getAwsErrorCode() ?: $e->getMessage()));
            }
        }

        $identity = $this->tenants->identityFor($config);

        if ($identity === null) {
            $warnings[] = 'No From email or identity set, so the sending identity was not checked.';
        } else {
            try {
                $found = $ses->getEmailIdentity(['EmailIdentity' => $identity]);

                if (! ($found['VerifiedForSendingStatus'] ?? false)) {
                    return ConnectionTestResult::failed("The identity \"{$identity}\" exists in {$region} but is not verified for sending.");
                }
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() === 'NotFoundException') {
                    return ConnectionTestResult::failed("The identity \"{$identity}\" is not in SES in {$region}. Verify it in that region.");
                }

                $warnings[] = "Could not check the identity \"{$identity}\": " . ($e->getAwsErrorMessage() ?: $e->getAwsErrorCode());
            }
        }

        return ConnectionTestResult::passed("{$prefix}Credentials accepted by SES in {$region}. No email was sent.", $warnings, $details);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function viaSmtp(array $config): bool
    {
        return ($config['send_via'] ?? self::VIA_SMTP) === self::VIA_SMTP;
    }

    /**
     * The classic SES endpoint: the one that honours X-SES-TENANT.
     *
     * @param  array<string, mixed>  $config
     */
    protected function smtpHost(array $config): string
    {
        return 'email-smtp.' . ($config['region'] ?? '') . '.amazonaws.com';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function smtpPort(array $config): int
    {
        return filled($config['smtp_port'] ?? null) ? (int) $config['smtp_port'] : 587;
    }

    protected function isAccessDenied(AwsException $e): bool
    {
        return in_array($e->getAwsErrorCode(), ['AccessDeniedException', 'AccessDenied'], true);
    }
}
