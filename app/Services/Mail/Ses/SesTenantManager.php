<?php

namespace App\Services\Mail\Ses;

use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\Facades\Log;

/**
 * The SES tenant the CRM's own mail is sent under.
 *
 * A tenant gives this CRM its own reputation, metrics and sending status inside
 * the SES account, so a bounce spike here cannot pause the account's other
 * senders, and vice versa. There is one tenant per environment — the name comes
 * from SES_TENANT_NAME in that environment's .env, so local and production can
 * never share one, even when a database is copied between them.
 *
 * Tenants are region-scoped, and cannot send until the sending identity and the
 * configuration set are associated with them. Provisioning does all three and is
 * idempotent, so it doubles as "repair".
 *
 * What was provisioned is recorded on the SES provider's settings (under
 * `_tenant`), and mail carries the tenant only while that record still matches
 * the current name, region, identity and configuration set. Changing any of
 * them drops the tenant from sends until it is provisioned again, rather than
 * naming a tenant SES would reject. Untenanted mail still goes out.
 */
class SesTenantManager
{
    public const RECORD_KEY = '_tenant';

    /** SES: at most 64 characters, alphanumerics, hyphens and underscores. */
    public const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(private SesClientFactory $clients)
    {
    }

    /** The readable part of the name, straight from configuration. */
    public function tenantStem(): string
    {
        return (string) config('services.ses_tenant.name');
    }

    /**
     * The tenant name: the configured stem plus an unguessable suffix.
     *
     * The suffix exists so the name is not derivable from the environment --
     * under a TENANT suppression scope SES rejects mail that names no valid
     * tenant, so an unguessable name is one more thing an attacker holding only
     * SMTP credentials would still need.
     *
     * The stem is truncated rather than the suffix, so a long stem can never
     * push the name past the 64 characters SES allows and silently make every
     * name invalid.
     */
    public function tenantName(): string
    {
        $stem = $this->tenantStem();
        $suffix = trim((string) config('services.ses_tenant.suffix'));

        if ($suffix === '') {
            return $stem;
        }

        $tail = '-' . $suffix;

        return substr($stem, 0, max(0, 64 - strlen($tail))) . $tail;
    }

    public function nameIsValid(?string $name = null): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $name ?? $this->tenantName());
    }

    /**
     * The SES identity mail is sent from: the explicit setting, else the From domain.
     *
     * @param  array<string, mixed>  $config
     */
    public function identityFor(array $config): ?string
    {
        if (filled($config['identity'] ?? null)) {
            return trim((string) $config['identity']);
        }

        $from = (string) ($config['from_email'] ?? '');

        return str_contains($from, '@') ? strtolower(substr($from, strrpos($from, '@') + 1)) : null;
    }

    /**
     * The tenant to name on a send, or null to send without one.
     *
     * @param  array<string, mixed>  $config
     */
    public function tenantForSend(array $config): ?string
    {
        return $this->issue($config) === null ? $this->tenantName() : null;
    }

    /**
     * What the page shows without calling AWS.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function state(array $config): array
    {
        $record = $config[self::RECORD_KEY] ?? null;
        $issue = $this->issue($config);

        return [
            'name' => $this->tenantName(),
            // Shown separately so the page can say which part came from
            // SES_TENANT_NAME and which part is the derived suffix.
            'stem' => $this->tenantStem(),
            'name_valid' => $this->nameIsValid(),
            'name_from_env' => (bool) config('services.ses_tenant.explicit'),
            'environment' => app()->environment(),
            'identity' => $this->identityFor($config),
            'in_use' => $issue === null,
            'issue' => $issue,
            'provisioned' => $record ? [
                'name' => $record['name'] ?? null,
                'region' => $record['region'] ?? null,
                'identity' => $record['identity'] ?? null,
                'configuration_set' => $record['configuration_set'] ?? null,
                'sending_status' => $record['sending_status'] ?? null,
                'provisioned_at' => $record['provisioned_at'] ?? null,
            ] : null,
        ];
    }

    /**
     * Why sends would not carry the tenant right now, or null when they would.
     *
     * @param  array<string, mixed>  $config
     */
    public function issue(array $config): ?string
    {
        if (! $this->nameIsValid()) {
            return 'SES_TENANT_NAME must be 1-64 characters: letters, numbers, hyphens and underscores.';
        }

        if (blank($config['configuration_set'] ?? null)) {
            return 'A configuration set is required before a tenant can be used.';
        }

        $record = $config[self::RECORD_KEY] ?? null;

        if (! $record) {
            return 'The tenant has not been provisioned in AWS yet.';
        }

        $provisionedName = $record['name'] ?? null;

        if ($provisionedName !== $this->tenantName()) {
            return "The provisioned tenant is \"{$provisionedName}\", but this environment is configured for \"{$this->tenantName()}\". Provision again.";
        }

        if (($record['region'] ?? null) !== ($config['region'] ?? null)
            || ($record['identity'] ?? null) !== $this->identityFor($config)
            || ($record['configuration_set'] ?? null) !== ($config['configuration_set'] ?? null)) {
            return 'Region, identity or configuration set changed since the tenant was provisioned. Provision again.';
        }

        return null;
    }

    /**
     * Create the tenant and associate the identity and configuration set with it.
     *
     * Safe to re-run: anything that already exists counts as done.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>  The record to store under {@see RECORD_KEY}.
     */
    public function provision(array $config): array
    {
        $name = $this->tenantName();

        if (! $this->nameIsValid($name)) {
            throw new SesTenantException('SES_TENANT_NAME must be 1-64 characters: letters, numbers, hyphens and underscores.');
        }

        $this->assertProvisionable($config);

        $region = (string) $config['region'];
        $identity = (string) $this->identityFor($config);
        $configurationSet = (string) $config['configuration_set'];

        try {
            $caller = $this->clients->sts($config)->getCallerIdentity();
        } catch (AwsException $e) {
            throw new SesTenantException('Could not identify the AWS account for these credentials: ' . $this->reason($e), 0, $e);
        }

        $account = (string) $caller['Account'];
        $partition = explode(':', (string) $caller['Arn'])[1] ?? 'aws';
        $arnPrefix = "arn:{$partition}:ses:{$region}:{$account}";

        $ses = $this->clients->ses($config);

        $this->createTenant($ses, $name);

        $this->idempotent(fn () => $ses->createTenantResourceAssociation([
            'TenantName' => $name,
            'ResourceArn' => "{$arnPrefix}:identity/{$identity}",
        ]), "associate identity \"{$identity}\"", "The identity \"{$identity}\" is not in SES in {$region}. Verify it in that region first.");

        $this->idempotent(fn () => $ses->createTenantResourceAssociation([
            'TenantName' => $name,
            'ResourceArn' => "{$arnPrefix}:configuration-set/{$configurationSet}",
        ]), "associate configuration set \"{$configurationSet}\"", "The configuration set \"{$configurationSet}\" does not exist in SES in {$region}. Create it there first.");

        try {
            $tenant = $ses->getTenant(['TenantName' => $name])['Tenant'] ?? [];
        } catch (AwsException $e) {
            throw new SesTenantException('The tenant was created but could not be read back: ' . $this->reason($e), 0, $e);
        }

        return [
            'name' => $name,
            'arn' => $tenant['TenantArn'] ?? null,
            'region' => $region,
            'identity' => $identity,
            'configuration_set' => $configurationSet,
            'sending_status' => $tenant['SendingStatus'] ?? null,
            'provisioned_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The tenant as AWS sees it right now.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function liveStatus(array $config): array
    {
        $name = $this->tenantName();

        if (! $this->nameIsValid($name)) {
            throw new SesTenantException('SES_TENANT_NAME must be 1-64 characters: letters, numbers, hyphens and underscores.');
        }

        $this->assertCredentials($config);
        $ses = $this->clients->ses($config);

        try {
            $tenant = $ses->getTenant(['TenantName' => $name])['Tenant'] ?? [];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return ['exists' => false, 'sending_status' => null, 'identity_associated' => false, 'configuration_set_associated' => false, 'associations_unknown_reason' => null];
            }

            throw new SesTenantException('Could not read the tenant from AWS: ' . $this->reason($e), 0, $e);
        }

        $arns = [];
        $token = null;

        try {
            do {
                $page = $ses->listTenantResources(array_filter(['TenantName' => $name, 'NextToken' => $token]));
                $arns = array_merge($arns, array_column($page['TenantResources'] ?? [], 'ResourceArn'));
                $token = $page['NextToken'] ?? null;
            } while ($token);
        } catch (AwsException $e) {
            // Reading associations is a nice-to-have; a key without the
            // permission still gets the tenant's status, with them unknown.
            if (! $this->isAccessDenied($e)) {
                throw new SesTenantException('Could not list the tenant\'s resources: ' . $this->reason($e), 0, $e);
            }

            return [
                'exists' => true,
                'sending_status' => $tenant['SendingStatus'] ?? null,
                'identity_associated' => null,
                'configuration_set_associated' => null,
                'associations_unknown_reason' => 'The IAM key lacks ses:ListTenantResources, so associations could not be checked.',
            ];
        }

        $identity = $this->identityFor($config);
        $configurationSet = $config['configuration_set'] ?? null;
        $associated = fn (string $suffix) => collect($arns)->contains(fn (string $arn) => str_ends_with($arn, $suffix));

        return [
            'exists' => true,
            'sending_status' => $tenant['SendingStatus'] ?? null,
            'identity_associated' => $identity !== null && $associated(":identity/{$identity}"),
            'configuration_set_associated' => filled($configurationSet) && $associated(":configuration-set/{$configurationSet}"),
            'associations_unknown_reason' => null,
        ];
    }

    /**
     * Create the tenant, tagged when the key may tag.
     *
     * Tagging on create needs ses:TagResource on top of ses:CreateTenant, and
     * AWS checks it before creating anything, so a key without it would fail
     * the whole create. The tags only label the tenant in the console; they are
     * not worth failing provisioning over, so retry untagged.
     */
    protected function createTenant(SesV2Client $ses, string $name): void
    {
        $step = "create tenant \"{$name}\"";

        try {
            $this->idempotent(fn () => $ses->createTenant([
                'TenantName' => $name,
                'Tags' => [
                    ['Key' => 'app', 'Value' => 'shopify-crm'],
                    ['Key' => 'environment', 'Value' => (string) app()->environment()],
                ],
            ]), $step);
        } catch (SesTenantException $e) {
            $aws = $e->getPrevious();

            if (! $aws instanceof AwsException || ! $this->isAccessDenied($aws) || ! str_contains($this->reason($aws), 'ses:TagResource')) {
                throw $e;
            }

            Log::info('IAM key lacks ses:TagResource; creating the SES tenant untagged', ['tenant' => $name]);

            $this->idempotent(fn () => $ses->createTenant(['TenantName' => $name]), $step);
        }
    }

    protected function isAccessDenied(AwsException $e): bool
    {
        return in_array($e->getAwsErrorCode(), ['AccessDeniedException', 'AccessDenied'], true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function assertProvisionable(array $config): void
    {
        $this->assertCredentials($config);

        if ($this->identityFor($config) === null) {
            throw new SesTenantException('Set a From email or an identity before provisioning the tenant.');
        }

        if (blank($config['configuration_set'] ?? null)) {
            throw new SesTenantException('Set a configuration set before provisioning the tenant: SES only lets a tenant send through one associated with it.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function assertCredentials(array $config): void
    {
        foreach (['region', 'access_key_id', 'secret_access_key'] as $field) {
            if (blank($config[$field] ?? null)) {
                throw new SesTenantException('Save the SES region, access key id and secret access key first.');
            }
        }
    }

    protected function idempotent(callable $call, string $step, ?string $notFound = null): void
    {
        try {
            $call();
        } catch (AwsException $e) {
            $code = $e->getAwsErrorCode();

            if ($code === 'AlreadyExistsException') {
                return;
            }

            if ($code === 'NotFoundException' && $notFound) {
                throw new SesTenantException($notFound, 0, $e);
            }

            throw new SesTenantException("Could not {$step}: " . $this->reason($e), 0, $e);
        }
    }

    protected function reason(AwsException $e): string
    {
        return $e->getAwsErrorMessage() ?: ($e->getAwsErrorCode() ?: $e->getMessage());
    }
}
