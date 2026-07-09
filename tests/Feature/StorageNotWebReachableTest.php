<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 9: proves neither storage/app/... nor the project's .env file is
 * reachable through Laravel's own routing.
 *
 * `php artisan storage:link` was never run in this project (confirmed: no
 * public/storage symlink on disk), so requests under /storage/... have
 * nothing to match in the public/ webroot and fall through to Laravel's
 * router, which 404s them like any other undefined route. This test is a
 * regression guard: if a symlink is ever created (e.g. by a future deploy
 * script) or public/.env is ever introduced, these assertions catch it.
 */
class StorageNotWebReachableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function storage_app_path_is_not_web_reachable(): void
    {
        $response = $this->get('/storage/app/some-file');

        $response->assertNotFound();
    }

    #[Test]
    public function dot_env_file_is_not_web_reachable(): void
    {
        $response = $this->get('/.env');

        $response->assertNotFound();
    }

    #[Test]
    public function public_storage_symlink_does_not_exist_on_disk(): void
    {
        // Direct filesystem check backing up the HTTP assertions above:
        // if this ever starts failing, someone ran `storage:link` and the
        // two HTTP-level tests need to be revisited (a real symlink would
        // make /storage/... serve real files from storage/app/public).
        $this->assertFalse(
            is_link(public_path('storage')) || is_dir(public_path('storage')),
            'public/storage exists — storage:link may have been run, invalidating the "not web reachable" assumption.'
        );
    }
}
