<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 3 — Form Request extraction regression guard: confirms validation
 * still rejects/accepts the same inputs after moving inline
 * $request->validate([...]) blocks into RouteDistanceRequest,
 * StoreUserRequest, UpdateUserRequest, and BulkUploadRequest.
 */
class FormRequestsValidationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'formreq-admin@osca.local'],
            ['name' => 'FormReq Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

    // ── RouteDistanceRequest ─────────────────────────────────────────────────

    #[Test]
    public function route_distance_rejects_out_of_bounds_latitude(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/gis/route-distance?'.http_build_query([
                'origin_lat' => 999,
                'origin_lng' => 121.0,
                'destination_lat' => 14.0,
                'destination_lng' => 121.0,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['origin_lat']);
    }

    #[Test]
    public function route_distance_rejects_missing_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/gis/route-distance')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['origin_lat', 'origin_lng', 'destination_lat', 'destination_lng']);
    }

    #[Test]
    public function route_distance_rejects_nonexistent_senior_id(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/gis/route-distance?'.http_build_query([
                'origin_lat' => 14.0,
                'origin_lng' => 121.0,
                'destination_lat' => 14.1,
                'destination_lng' => 121.1,
                'senior_id' => 999999,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['senior_id']);
    }

    // ── StoreUserRequest ─────────────────────────────────────────────────────

    #[Test]
    public function store_user_rejects_a_password_without_complexity(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Weak Password',
                'email' => 'weakpw@osca.local',
                'role' => 'encoder',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertSessionHasErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'weakpw@osca.local']);
    }

    #[Test]
    public function store_user_accepts_a_complex_password(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Strong Password',
                'email' => 'strongpw@osca.local',
                'role' => 'encoder',
                'password' => 'Str0ng!Pass',
                'password_confirmation' => 'Str0ng!Pass',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['email' => 'strongpw@osca.local']);
    }

    #[Test]
    public function store_user_rejects_invalid_role(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Bad Role',
                'email' => 'badrole@osca.local',
                'role' => 'superadmin',
                'password' => 'Str0ng!Pass',
                'password_confirmation' => 'Str0ng!Pass',
            ])
            ->assertSessionHasErrors(['role']);
    }

    // ── UpdateUserRequest ────────────────────────────────────────────────────

    #[Test]
    public function update_user_rejects_weak_password_under_its_own_error_bag(): void
    {
        $target = User::create([
            'name' => 'Row Target',
            'email' => 'rowtarget@osca.local',
            'password' => Hash::make('password'),
        ]);
        $target->syncRoles(['encoder']);

        $response = $this->actingAs($this->admin)
            ->put(route('users.update', $target), [
                'name' => 'Row Target',
                'email' => 'rowtarget@osca.local',
                'role' => 'encoder',
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ]);

        $response->assertSessionHasErrors(['password'], null, "editUser{$target->id}");
    }

    #[Test]
    public function update_user_error_bag_does_not_leak_into_another_users_bag(): void
    {
        $target = User::create([
            'name' => 'Row Target',
            'email' => 'rowtarget2@osca.local',
            'password' => Hash::make('password'),
        ]);
        $target->syncRoles(['encoder']);

        $otherUserId = $target->id + 1;

        $response = $this->actingAs($this->admin)
            ->put(route('users.update', $target), [
                'name' => 'Row Target',
                'email' => 'rowtarget2@osca.local',
                'role' => 'encoder',
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ]);

        $bags = session('errors');
        $this->assertTrue($bags->hasBag("editUser{$target->id}"));
        $this->assertFalse($bags->hasBag("editUser{$otherUserId}"));
    }

    #[Test]
    public function update_user_accepts_a_complex_password(): void
    {
        $target = User::create([
            'name' => 'Row Target',
            'email' => 'rowtarget3@osca.local',
            'password' => Hash::make('password'),
        ]);
        $target->syncRoles(['encoder']);

        $this->actingAs($this->admin)
            ->put(route('users.update', $target), [
                'name' => 'Row Target Updated',
                'email' => 'rowtarget3@osca.local',
                'role' => 'encoder',
                'password' => 'Str0ng!Pass',
                'password_confirmation' => 'Str0ng!Pass',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['email' => 'rowtarget3@osca.local', 'name' => 'Row Target Updated']);
    }

    #[Test]
    public function update_user_allows_blank_password_to_leave_it_unchanged(): void
    {
        $target = User::create([
            'name' => 'Row Target',
            'email' => 'rowtarget4@osca.local',
            'password' => Hash::make('password'),
        ]);
        $target->syncRoles(['encoder']);

        $this->actingAs($this->admin)
            ->put(route('users.update', $target), [
                'name' => 'Row Target',
                'email' => 'rowtarget4@osca.local',
                'role' => 'encoder',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionDoesntHaveErrors();
    }

    // ── BulkUploadRequest ────────────────────────────────────────────────────

    #[Test]
    public function bulk_upload_rejects_missing_file(): void
    {
        $this->actingAs($this->admin)
            ->post(route('seniors.bulk-upload'), [])
            ->assertSessionHasErrors(['file']);
    }

    #[Test]
    public function bulk_upload_rejects_a_disallowed_mime_type(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'test_').'.pdf';
        file_put_contents($tmpPath, '%PDF-1.4 not a real pdf');
        $file = new UploadedFile($tmpPath, 'test.pdf', 'application/pdf', null, true);

        $this->actingAs($this->admin)
            ->post(route('seniors.bulk-upload'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }
}
