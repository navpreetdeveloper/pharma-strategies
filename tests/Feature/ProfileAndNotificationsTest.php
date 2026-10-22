<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\WorkplaceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function userContext(): array
    {
        $company = Company::create(['name' => 'Test Pharmacy', 'legal_name' => 'Test Pharmacy', 'province' => 'Ontario']);
        $user = User::create(['name' => 'Staff', 'email' => 'staff@test.example', 'password' => Hash::make('OldPassword123!'), 'status' => 'active', 'email_verified_at' => now()]);
        $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);

        return [$company, $user];
    }

    public function test_user_can_change_their_own_password(): void
    {
        [$company,$user] = $this->userContext();
        $this->actingAs($user)->withSession(['company_id' => $company->id])->patch(route('profile.password.update'), ['current_password' => 'OldPassword123!', 'password' => 'NewPass1', 'password_confirmation' => 'NewPass1'])->assertRedirect(route('profile.show'));
        $this->assertTrue(Hash::check('NewPass1', $user->fresh()->password));
        $this->assertFalse((bool) $user->fresh()->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'password_changed']);
    }

    public function test_wrong_current_password_does_not_change_password(): void
    {
        [$company,$user] = $this->userContext();
        $this->actingAs($user)->withSession(['company_id' => $company->id])->from(route('profile.show'))->patch(route('profile.password.update'), ['current_password' => 'wrong-password', 'password' => 'NewPass1', 'password_confirmation' => 'NewPass1'])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
        $this->assertNull(session('_old_input.current_password'));
        $this->assertNull(session('_old_input.password'));
        $this->assertNull(session('_old_input.password_confirmation'));
        $this->patchJson(route('profile.password.update'), ['current_password' => 'wrong-password', 'password' => 'NewPass1', 'password_confirmation' => 'NewPass1'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_clear_notifications_removes_only_the_current_users_notification_history(): void
    {
        [$company,$user] = $this->userContext();
        $other = User::create(['name' => 'Other', 'email' => 'other@test.example', 'password' => Hash::make('Password123!'), 'status' => 'active', 'email_verified_at' => now()]);
        $company->users()->attach($other->id, ['role' => 'staff', 'status' => 'active']);
        $user->notifyNow(new class extends Notification
        {
            public function via($n)
            {
                return ['database'];
            }

            public function toDatabase($n)
            {
                return ['title' => 'Test', 'body' => 'Mine', 'data' => []];
            }
        });
        $other->notifyNow(new class extends Notification
        {
            public function via($n)
            {
                return ['database'];
            }

            public function toDatabase($n)
            {
                return ['title' => 'Test', 'body' => 'Other', 'data' => []];
            }
        });
        $this->actingAs($user)->withSession(['company_id' => $company->id])->deleteJson(route('notifications.clear'))->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $user->id, 'notifiable_type' => User::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $other->id, 'notifiable_type' => User::class]);
    }

    public function test_notification_list_and_read_actions_are_scoped_to_the_current_user(): void
    {
        [$company, $user] = $this->userContext();
        $other = User::factory()->create();
        $user->notifyNow(new WorkplaceNotification('My update', 'Generic message'));
        $other->notifyNow(new WorkplaceNotification('Other update', 'Other message'));
        $mine = $user->notifications()->firstOrFail();
        $theirs = $other->notifications()->firstOrFail();

        $this->actingAs($user)->withSession(['company_id' => $company->id]);
        $this->getJson(route('notifications.index'))->assertOk()
            ->assertJsonCount(1, 'notifications')->assertJsonPath('notifications.0.id', $mine->id);
        $this->patchJson(route('notifications.read', $theirs->id))->assertNotFound();
        $this->patchJson(route('notifications.read', $mine->id))->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
        $this->get(route('profile.show'))->assertOk()->assertSee('Enable notifications')->assertDontSee('Enable device notifications');
    }
}
