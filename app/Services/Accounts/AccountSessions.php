<?php

namespace App\Services\Accounts;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountSessions
{
    /** Call inside the account transaction; do not revoke the acting administrator. */
    public function revoke(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        PushSubscription::where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }
}
