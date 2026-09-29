<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function userContext(): array
    {
        $company = Company::create(['name'=>'Test Pharmacy','legal_name'=>'Test Pharmacy','province'=>'Ontario']);
        $user = User::create(['name'=>'Staff','email'=>'staff@test.example','password'=>Hash::make('OldPassword123!'),'status'=>'active']);
        $company->users()->attach($user->id, ['role'=>'staff','status'=>'active']);
        return [$company,$user];
    }

    public function test_user_can_change_their_own_password(): void
    {
        [$company,$user] = $this->userContext();
        $this->actingAs($user)->withSession(['company_id'=>$company->id])->patch(route('profile.password.update'), ['current_password'=>'OldPassword123!','password'=>'NewPassword123!','password_confirmation'=>'NewPassword123!'])->assertRedirect(route('profile.show'));
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertFalse((bool) $user->fresh()->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['user_id'=>$user->id,'action'=>'password_changed']);
    }

    public function test_wrong_current_password_does_not_change_password(): void
    {
        [$company,$user] = $this->userContext();
        $this->actingAs($user)->withSession(['company_id'=>$company->id])->from(route('profile.show'))->patch(route('profile.password.update'), ['current_password'=>'wrong-password','password'=>'NewPassword123!','password_confirmation'=>'NewPassword123!'])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
    }

    public function test_clear_notifications_removes_only_the_current_users_notification_history(): void
    {
        [$company,$user] = $this->userContext();
        $other = User::create(['name'=>'Other','email'=>'other@test.example','password'=>Hash::make('Password123!'),'status'=>'active']);
        $company->users()->attach($other->id, ['role'=>'staff','status'=>'active']);
        $user->notifyNow(new class extends \Illuminate\Notifications\Notification { public function via($n){return ['database'];} public function toDatabase($n){return ['title'=>'Test','body'=>'Mine','data'=>[]];} });
        $other->notifyNow(new class extends \Illuminate\Notifications\Notification { public function via($n){return ['database'];} public function toDatabase($n){return ['title'=>'Test','body'=>'Other','data'=>[]];} });
        $this->actingAs($user)->withSession(['company_id'=>$company->id])->deleteJson(route('notifications.clear'))->assertOk()->assertJson(['ok'=>true]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id'=>$user->id, 'notifiable_type'=>User::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id'=>$other->id, 'notifiable_type'=>User::class]);
    }
}
