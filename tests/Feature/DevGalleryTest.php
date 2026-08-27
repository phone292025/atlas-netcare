<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The component gallery is a development tool, not part of the system.
 *
 * What matters is the direction that could embarrass us: it must not exist
 * anywhere that is not a developer's own machine. The test environment is not
 * `local`, so if the guard in routes/web.php were ever removed or loosened,
 * these fail.
 *
 * @see routes/web.php
 */
class DevGalleryTest extends TestCase
{
    public function test_the_environment_running_these_tests_is_not_local(): void
    {
        // If this ever becomes 'local', the assertions below stop meaning
        // anything and would silently pass while the gallery was public.
        $this->assertNotSame('local', app()->environment());
    }

    public function test_the_gallery_route_is_not_registered_outside_local(): void
    {
        $this->assertFalse(
            Route::has('dev.gallery'),
            'The component gallery must only be registered when APP_ENV=local.'
        );
    }

    public function test_the_gallery_url_is_not_reachable_outside_local(): void
    {
        $this->get('/dev/gallery')->assertNotFound();
    }
}
