<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * '/' is a role-based redirect by design (guests -> portal -> login).
     * The old Breeze test expected a 200 welcome page that no longer exists.
     */
    public function test_the_application_redirects_the_root_route(): void
    {
        $this->get('/')->assertRedirect(route('portal.dashboard'));
    }
}
