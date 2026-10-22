<?php

namespace App\Http\Requests;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class PlatformAccountActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformSuperAdmin() === true;
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $common = ['current_password' => 'required|string|max:4096', 'reason' => 'required|string|min:5|max:500'];
        if ($this->routeIs('platform.users.reset-password')) {
            return $common + ['reset_user_id' => ['required', 'integer', Rule::in([$user->id])],
                'password' => ['required', 'string', 'max:4096', 'confirmed', Password::defaults()], 'identity_verified' => 'required|accepted'];
        }
        $common['manage_user_id'] = ['required', 'integer', Rule::in([$user->id])];
        if ($this->routeIs('platform.users.restore-access')) {
            return $common + ['company_id' => 'required|integer|exists:companies,id',
                'role' => ['required', Rule::in(CompanyRole::employeeValues())]];
        }

        return $common + ['confirmation_email' => ['required', 'string', Rule::in([$user->email])]];
    }
}
