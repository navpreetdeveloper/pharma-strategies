<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function test_eight_character_passwords_are_accepted_while_short_or_incomplete_passwords_are_rejected(): void
    {
        foreach (['NewPass1' => true, 'LongerPassword123' => true, 'NewPas1' => false, 'newpass1' => false, 'NEWPASS1' => false, 'NewPasss' => false] as $password => $accepted) {
            $validator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);
            $this->assertSame($accepted, $validator->passes(), 'Unexpected policy result for fixture '.$password);
        }
    }
}
