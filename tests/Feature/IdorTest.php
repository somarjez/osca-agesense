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
 * Guards the point of Task 1: SeniorCitizen's public route key is `uuid`,
 * not the sequential integer `id`.
 *
 *  - Unauthenticated requests never reach the model lookup — the `auth`
 *    middleware redirects to login first.
 *  - Authenticated requests using the *old* integer-id URL 404, because
 *    implicit route-model binding now resolves by uuid.
 */
class IdorTest extends TestCase
{
    use DatabaseTransactions;

    private User $viewer;

    private SeniorCitizen $senior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->viewer = User::firstOrCreate(
            ['email' => 'idor-viewer@osca.local'],
            ['name' => 'IDOR Viewer', 'password' => Hash::make('password')]
        );
        $this->viewer->syncRoles(['viewer']);

        $this->senior = SeniorCitizen::create([
            'osca_id' => 'IDR-'.uniqid(),
            'first_name' => 'Idor',
            'last_name' => 'TestSenior',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1950-06-15',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);
    }

    #[Test]
    public function unauthenticated_show_redirects_to_login(): void
    {
        $this->get(route('seniors.show', $this->senior))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function unauthenticated_export_redirects_to_login(): void
    {
        $this->get(route('seniors.export', $this->senior))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function old_integer_id_url_404s_for_authenticated_user(): void
    {
        $this->actingAs($this->viewer)
            ->get('/seniors/'.$this->senior->id)
            ->assertNotFound();
    }

    #[Test]
    public function uuid_url_resolves_for_authenticated_user(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('seniors.show', $this->senior))
            ->assertOk();
    }

    #[Test]
    public function senior_route_key_is_uuid(): void
    {
        $this->assertSame('uuid', $this->senior->getRouteKeyName());
        $this->assertSame($this->senior->uuid, $this->senior->getRouteKey());
        $this->assertNotEmpty($this->senior->uuid);
    }
}
