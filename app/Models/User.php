<?php

namespace App\Models;

use App\Notifications\QueuedPasswordReset;
use App\Notifications\QueuedVerification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, \Laravel\Fortify\TwoFactorAuthenticatable, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'employee_id', 'department', 'job_title', 'status', 'platform_role', 'last_seen_at', 'must_change_password', 'email_verified_at',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $casts = ['email_verified_at' => 'datetime', 'last_seen_at' => 'datetime', 'must_change_password' => 'boolean', 'two_factor_confirmed_at' => 'datetime'];

    public function companies()
    {
        return $this->belongsToMany(Company::class)
            ->withPivot('role', 'status', 'name', 'employee_id', 'department', 'job_title')
            ->withTimestamps();
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function importantNotifications()
    {
        return $this->notifications()->where(fn ($q) => $q->whereNull('data->data->message_id')->orWhere('data->data->important', true));
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedPasswordReset($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerification);
    }

    public function hasAnyRole($roles): bool
    {
        $roles = (array) $roles;
        $companyId = session('company_id');

        if (! $companyId) {
            return false;
        }

        return $this->companies()
            ->where('companies.id', $companyId)
            ->wherePivotIn('role', $roles)
            ->wherePivot('status', 'active')
            ->exists();
    }

    public function role(): ?string
    {
        $companyId = session('company_id');

        if (! $companyId) {
            return null;
        }

        return optional(
            $this->companies()->where('companies.id', $companyId)->first()
        )->pivot?->role;
    }

    public function isPlatformSuperAdmin(): bool
    {
        return $this->status === 'active' && $this->platform_role === 'super_admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->isPlatformSuperAdmin() || $this->role() === 'company_super_admin';
    }

    public function landingRoute(): string
    {
        if ($this->must_change_password) {
            return 'profile.show';
        }

        if ($this->isPlatformSuperAdmin()) {
            return 'platform.companies.index';
        }

        return $this->hasAnyRole(['company_super_admin', 'pharmacy_manager']) ? 'company.dashboard' : 'chat';
    }

    public function meetsEmailAccessPolicy(): bool
    {
        return ! config('security.require_email_verification', false) || $this->hasVerifiedEmail();
    }

    public function activeInCompany(int $companyId): bool
    {
        return $this->status === 'active' && $this->meetsEmailAccessPolicy() && ! $this->must_change_password
            && $this->companies()->where('companies.id', $companyId)->where('companies.status', 'active')->wherePivot('status', 'active')->exists();
    }

    public function canWriteConversation(Conversation $conversation): bool
    {
        return ! $this->isPlatformSuperAdmin() && $this->activeInCompany($conversation->company_id)
            && $conversation->participants()->whereKey($this->id)->exists();
    }

    public function canAccessConversation(Conversation $conversation): bool
    {
        if ($this->status !== 'active' || ! $this->meetsEmailAccessPolicy() || $this->must_change_password || $conversation->company?->status !== 'active') {
            return false;
        }
        if ((int) session('company_id') !== (int) $conversation->company_id) {
            return false;
        }
        if ($this->isPlatformSuperAdmin()) {
            return PrivacySetting::administratorReviewAllowed($conversation->company_id);
        }
        if (! $this->activeInCompany($conversation->company_id)) {
            return false;
        }
        if ($conversation->participants()->whereKey($this->id)->exists()) {
            return true;
        }

        return $this->companies()->where('companies.id', $conversation->company_id)->wherePivot('role', 'company_super_admin')->exists()
            && PrivacySetting::administratorReviewAllowed($conversation->company_id);
    }
}
