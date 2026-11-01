<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndAdminSeeder extends Seeder
{
    /**
     * Permission matrix per the CTI Portal spec (Section 2).
     * NOTE: the superadmin password is generated randomly at seed time and
     * printed ONCE to the operator. It is never hardcoded in the repo and
     * the seeded account is flagged must_change_password = true.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // Dashboard & Analytics
            'dashboard.view', 'dashboard.export',
            // Manual & Scheduled Query Execution
            'query.execute', 'query.cancel-own', 'query.cancel-all', 'query.history.view',
            // CTI Repository Management
            'cti.view', 'cti.create', 'cti.edit', 'cti.approve', 'cti.delete', 'cti.bulk', 'cti.comment',
            // Report Generation
            'report.generate', 'report.template.manage',
            // Configuration Center
            'config.feeds.manage', 'config.ai.manage', 'config.ioc.manage', 'config.keywords.manage', 'config.apikeys.manage',
            // Users & Audit
            'users.manage-all', 'users.manage-monitoring', 'audit.view',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $superadmin = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $monitoring = Role::firstOrCreate(['name' => 'monitoring', 'guard_name' => 'web']);

        // Admin: everything except API key management and managing all users.
        $admin->syncPermissions(collect($permissions)->reject(
            fn (string $p) => in_array($p, ['config.apikeys.manage', 'users.manage-all'])
        )->values()->all());

        // Monitoring: read-only dashboards/repository, comments, reports; query execution left to policy.
        $monitoring->syncPermissions([
            'dashboard.view', 'dashboard.export', 'query.history.view',
            'cti.view', 'cti.comment', 'report.generate',
        ]);

        $superadmin->syncPermissions($permissions);

        $password = Str::password(24, symbols: false);

        $user = User::firstOrCreate(
            ['email' => 'manage@superadmin.admin'],
            [
                'name' => 'Super Administrator',
                'password' => $password, // hashed automatically via 'hashed' cast
                'email_verified_at' => now(),
                'must_change_password' => true,
                'mfa_enabled' => false,
            ]
        );
        $user->assignRole('superadmin');

        $this->command?->line('');
        $this->command?->warn('=================================================================');
        $this->command?->warn('SUPERADMIN INITIAL PASSWORD (shown ONCE — store it in a vault):');
        $this->command?->line('');
        $this->command?->line('    Email:    manage@superadmin.admin');
        $this->command?->line('    Password: '.$password);
        $this->command?->line('');
        $this->command?->warn('The account must change this password and set up MFA at first login.');
        $this->command?->warn('=================================================================');
    }
}
