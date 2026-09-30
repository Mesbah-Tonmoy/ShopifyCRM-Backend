<?php

namespace App\Services\Mail;

/**
 * One email delivery service the CRM can send its own mail through.
 *
 * A provider is a description, not a connection: it says which settings it
 * needs, how to turn those settings into a Laravel mailer, and how to check them
 * without sending anything. The settings themselves live encrypted on the
 * matching `integrations` row, keyed by {@see key()}.
 *
 * The field list is served to the Integrations page as-is, so adding a provider
 * is one subclass registered in {@see MailProviderRegistry} — no frontend change.
 */
abstract class MailProvider
{
    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * Fields that say how to reach the service: host, credentials, region.
     *
     * Each entry: key, label, type (text|password|number|select), and
     * optionally required, secret, placeholder, help, options, option_labels,
     * default, rules, and two conditions over other fields' values:
     * `visible_when` (shown, and required if `required`, only when it holds)
     * and `required_when` (always shown, required only when it holds).
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function connectionFields(): array;

    /**
     * The Laravel mailer definition for these settings.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    abstract public function mailerConfig(array $config): array;

    /**
     * Check the settings reach the service and are accepted, without sending.
     *
     * @param  array<string, mixed>  $config
     */
    abstract public function testConnection(array $config): ConnectionTestResult;

    /**
     * Every field, connection first, then the sender fields all providers share.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        $connection = array_map(fn (array $f) => $f + ['group' => 'connection'], $this->connectionFields());

        return array_merge($connection, $this->senderFields());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function senderFields(): array
    {
        return array_map(fn (array $f) => $f + ['group' => 'sender'], [
            ['key' => 'from_email', 'label' => 'From Email', 'type' => 'text', 'required' => true, 'placeholder' => 'no-reply@yourapp.com', 'rules' => ['email']],
            ['key' => 'from_name', 'label' => 'From Name', 'type' => 'text', 'placeholder' => 'Shopify CRM', 'rules' => ['max:191']],
            ['key' => 'reply_to', 'label' => 'Reply-To', 'type' => 'text', 'placeholder' => 'support@yourapp.com', 'rules' => ['email']],
            ['key' => 'cc', 'label' => 'CC (comma separated)', 'type' => 'text', 'placeholder' => 'a@yourapp.com, b@yourapp.com', 'rules' => ['email_list']],
            ['key' => 'bcc', 'label' => 'BCC (comma separated)', 'type' => 'text', 'placeholder' => 'audit@yourapp.com', 'rules' => ['email_list']],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function fieldKeys(): array
    {
        return array_column($this->fields(), 'key');
    }

    /**
     * Fields never sent back to the browser. A blank submission keeps the stored value.
     *
     * @return array<int, string>
     */
    public function secretFields(): array
    {
        return array_column(array_filter($this->fields(), fn (array $f) => $f['secret'] ?? false), 'key');
    }

    /**
     * Required fields still empty in these settings.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public function missingFields(array $config): array
    {
        $required = array_filter($this->fields(), fn (array $f) => $this->isRequired($f, $config));

        return array_values(array_map(
            fn (array $f) => $f['key'],
            array_filter($required, fn (array $f) => blank($config[$f['key']] ?? null))
        ));
    }

    /**
     * Whether a field must be filled in, given the other settings.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $config
     */
    public function isRequired(array $field, array $config): bool
    {
        return ($field['required'] ?? false)
            && $this->conditionHolds($field['visible_when'] ?? null, $config)
            && $this->conditionHolds($field['required_when'] ?? null, $config);
    }

    /**
     * @param  array<string, string>|null  $condition  field key => value it must have
     * @param  array<string, mixed>  $config
     */
    protected function conditionHolds(?array $condition, array $config): bool
    {
        foreach ($condition ?? [] as $key => $value) {
            if ((string) ($config[$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Headers to add to every message sent through this provider.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function messageHeaders(array $config): array
    {
        return [];
    }

    /**
     * The SES tenant sends are made under, for logging. Null for non-SES providers.
     *
     * @param  array<string, mixed>  $config
     */
    public function tenantFor(array $config): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function isConfigured(array $config): bool
    {
        return $this->missingFields($config) === [];
    }

    /**
     * Name of the dynamic mailer registered for this provider.
     */
    public function mailerName(): string
    {
        return $this->key() . '_dynamic';
    }

    /**
     * Settings a caller may see: secrets removed, defaults filled in.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function publicConfig(array $config): array
    {
        $public = [];

        foreach ($this->fields() as $field) {
            if ($field['secret'] ?? false) {
                continue;
            }

            $public[$field['key']] = $config[$field['key']] ?? null;
        }

        return $public;
    }

    /**
     * Extra, provider-specific state for the page (e.g. SES tenant). Never secrets.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function meta(array $config): array
    {
        return [];
    }
}
