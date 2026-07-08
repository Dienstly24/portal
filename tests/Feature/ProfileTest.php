<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Breeze self-service profile (/profile, incl. account deletion) was
 * removed when the customer portal was built; profile changes go through
 * an approval workflow instead. These tests cover the actual flow.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_profile_page_is_displayed(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)
            ->get(route('portal.profile'))
            ->assertOk();
    }

    public function test_profile_update_creates_pending_approval_request(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)
            ->post(route('portal.profile.update'), ['phone' => '+49 40 123456'])
            ->assertSessionHas('success');

        $approval = ApprovalRequest::first();
        $this->assertNotNull($approval);
        $this->assertSame('phone', $approval->field_name);
        $this->assertSame('+49 40 123456', $approval->new_value);
        $this->assertSame('pending', $approval->status);
    }

    public function test_staff_cannot_access_the_customer_portal_profile(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);

        $this->actingAs($employee)
            ->get(route('portal.profile'))
            ->assertRedirect(route('admin.dashboard'));
    }
}
