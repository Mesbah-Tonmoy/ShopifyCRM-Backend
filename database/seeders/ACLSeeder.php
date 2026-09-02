<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Str;

class ACLSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'Installations View' => 'installations.view',
            'Installations Export' => 'installations.export',
            'Email Templates View' => 'email_templates.view',
            'Email Templates Edit' => 'email_templates.edit',
            'Users View' => 'users.view',
            'Users Add' => 'users.add',
            'Users Edit' => 'users.edit',
            'Permissions View' => 'permissions.view',
            'Permissions Add' => 'permissions.add',
            'Permissions Edit' => 'permissions.edit',
            'Roles View' => 'roles.view',
            'Roles Add' => 'roles.add',
            'Roles Edit' => 'roles.edit',
            'Apps View' => 'apps.view',
            'Apps Add' => 'apps.add',
            'Apps Delete' => 'apps.delete',
            'Pricing Plans View' => 'pricing_plans.view',
            'Pricing Plans Edit' => 'pricing_plans.edit',
            'Integrations View' => 'integrations.view',
            'Integrations Edit' => 'integrations.edit',
            'Feature Requests View' => 'feature_requests.view',
            'Feature Requests Add' => 'feature_requests.add',
            'Feature Requests Edit' => 'feature_requests.edit',
            'Feature Requests Delete' => 'feature_requests.delete',
            'Board Settings Edit' => 'board_settings.edit',
            'Features View' => 'features.view',
            'Features Add' => 'features.add',
            'Features Edit' => 'features.edit',
            'Features Delete' => 'features.delete',
        ];

        $permissionIds = [];
        foreach ($permissions as $name => $slug) {
            $permission = Permission::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name]
            );
            $permissionIds[] = $permission->id;
        }
        
        // Remove permissions not in the list
        Permission::whereNotIn('slug', $permissions)->delete();

        /*
         * Roles that are meant to hold every permission.
         *
         * Both are synced, not just "admin": production carries a super-admin
         * created through the roles screen, and syncing only one meant every
         * new permission added here had to be granted to the other by hand,
         * which was missed twice.
         */
        $fullAccessRoles = [
            'admin' => ['name' => 'Administrator', 'description' => 'System administrator with full access'],
            'super-admin' => ['name' => 'Super Administrator', 'description' => 'Unrestricted access to every feature'],
        ];

        foreach ($fullAccessRoles as $slug => $attributes) {
            Role::updateOrCreate(['slug' => $slug], $attributes)
                ->permissions()
                ->sync($permissionIds);
        }

    }
}
