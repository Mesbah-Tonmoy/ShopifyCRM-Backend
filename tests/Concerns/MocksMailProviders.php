<?php

namespace Tests\Concerns;

use App\Models\Integration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Mail\Ses\SesClientFactory;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;

/**
 * Users with integration permissions, stored provider rows, and a fake AWS.
 *
 * Every AWS call in a test goes through {@see $aws}; nothing reaches the network.
 */
trait MocksMailProviders
{
    protected MockHandler $aws;

    /** @var array<int, array{name: string, args: array<string, mixed>}> */
    protected array $awsCalls = [];

    protected function fakeAws(): void
    {
        $this->aws = new MockHandler();
        $this->awsCalls = [];

        $handler = function (Command $command, $request) {
            $this->awsCalls[] = ['name' => $command->getName(), 'args' => $command->toArray()];

            return ($this->aws)($command, $request);
        };

        $this->app->instance(SesClientFactory::class, new SesClientFactory($handler));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function awsResult(array $data = []): Result
    {
        return new Result($data);
    }

    protected function awsError(string $code, string $operation = 'Op', string $message = 'error'): AwsException
    {
        return new AwsException($message, new Command($operation), ['code' => $code, 'message' => $message]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function userWith(array $permissions = ['integrations.view', 'integrations.edit']): User
    {
        $role = Role::create(['name' => 'Tester', 'slug' => 'tester-' . uniqid()]);

        foreach ($permissions as $slug) {
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function storeProvider(string $key, array $config, bool $active = false): Integration
    {
        return Integration::updateOrCreate(
            ['key' => $key],
            ['name' => ucfirst($key), 'is_enabled' => $active, 'config' => $config]
        );
    }

    /**
     * SES sending over SMTP, with the access key kept for the tenant.
     *
     * @return array<string, mixed>
     */
    protected function sesSmtpConfig(array $overrides = []): array
    {
        return $this->sesConfig(array_merge([
            'send_via' => 'smtp',
            'smtp_username' => 'AKIASMTPUSERNAME1234',
            'smtp_password' => 'smtp-secret-never-returned',
        ], $overrides));
    }

    /**
     * SES sending over the API.
     *
     * @return array<string, mixed>
     */
    protected function sesConfig(array $overrides = []): array
    {
        return array_merge([
            'send_via' => 'api',
            'region' => 'us-west-2',
            'access_key_id' => 'AKIAEXAMPLEKEY123456',
            'secret_access_key' => 'secret-value-never-returned',
            'configuration_set' => 'crm-events',
            'from_email' => 'no-reply@mail.example.com',
            'from_name' => 'Shopify CRM',
        ], $overrides);
    }
}
