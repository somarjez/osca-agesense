<?php

namespace Tests\Feature;

use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Unit-level coverage for SeniorCitizenPolicy — mirrors the role gates
 * already applied in routes/seniors.php (and the surveys/ml/recommendations
 * routes operating on a SeniorCitizen). The policy is not yet wired into
 * the controllers (see Task 1 brief: "does not change who can do what"),
 * so this test exercises the policy directly via Gate::forUser()->allows().
 */
class SeniorCitizenPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $encoder;

    private User $viewer;

    private SeniorCitizen $senior;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'policy-admin@osca.local'],
            ['name' => 'Policy Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->encoder = User::firstOrCreate(
            ['email' => 'policy-encoder@osca.local'],
            ['name' => 'Policy Encoder', 'password' => Hash::make('password')]
        );
        $this->encoder->syncRoles(['encoder']);

        $this->viewer = User::firstOrCreate(
            ['email' => 'policy-viewer@osca.local'],
            ['name' => 'Policy Viewer', 'password' => Hash::make('password')]
        );
        $this->viewer->syncRoles(['viewer']);

        $this->senior = SeniorCitizen::create([
            'osca_id' => 'POL-'.uniqid(),
            'first_name' => 'Policy',
            'last_name' => 'TestSenior',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1950-06-15',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);
    }

    #[Test]
    public function view_and_view_any_allowed_for_all_three_roles(): void
    {
        foreach ([$this->admin, $this->encoder, $this->viewer] as $user) {
            $this->assertTrue($user->can('viewAny', SeniorCitizen::class));
            $this->assertTrue($user->can('view', $this->senior));
        }
    }

    #[Test]
    public function create_and_update_allowed_for_admin_and_encoder_only(): void
    {
        $this->assertTrue($this->admin->can('create', SeniorCitizen::class));
        $this->assertTrue($this->encoder->can('create', SeniorCitizen::class));
        $this->assertFalse($this->viewer->can('create', SeniorCitizen::class));

        $this->assertTrue($this->admin->can('update', $this->senior));
        $this->assertTrue($this->encoder->can('update', $this->senior));
        $this->assertFalse($this->viewer->can('update', $this->senior));
    }

    #[Test]
    public function delete_restore_and_force_delete_are_admin_only(): void
    {
        foreach (['delete', 'restore', 'forceDelete'] as $ability) {
            $this->assertTrue($this->admin->can($ability, $this->senior));
            $this->assertFalse($this->encoder->can($ability, $this->senior));
            $this->assertFalse($this->viewer->can($ability, $this->senior));
        }
    }

    #[Test]
    public function export_is_allowed_for_all_three_roles(): void
    {
        foreach ([$this->admin, $this->encoder, $this->viewer] as $user) {
            $this->assertTrue($user->can('export', $this->senior));
        }
    }
}
