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

        // Create Admin Role
        $adminRole = Role::updateOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrator', 'description' => 'System administrator with full access']
        );

        // Sync all permissions to admin
        $adminRole->permissions()->sync($permissionIds);

        // Create a default Admin User if not exists
        // $adminUser = User::where('email', 'admin@admin.com')->first();
        // if (!$adminUser) {
        //     $adminUser = User::create([
        //         'name' => 'Admin User',
        //         'email' => 'admin@admin.com',
        //         'password' => bcrypt('password'),
        //     ]);
        // }
        
        // $adminUser->roles()->sync([$adminRole->id]);
    }
}
