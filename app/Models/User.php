<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'employee_id', 'department', 'job_title', 'status', 'last_seen_at', 'must_change_password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['email_verified_at' => 'datetime', 'last_seen_at' => 'datetime', 'must_change_password' => 'boolean'];

    public function companies()
    {
        return $this->belongsToMany(Company::class)
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function hasAnyRole($roles): bool
    {
        $roles = (array) $roles;
        $companyId = session('company_id');

        if (!$companyId) {
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

        if (!$companyId) {
            return null;
        }

        return optional(
            $this->companies()->where('companies.id', $companyId)->first()
        )->pivot?->role;
    }

    public function isPlatformSuperAdmin(): bool
    {
        return $this->status === 'platform_super_admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->isPlatformSuperAdmin() || $this->role() === 'company_super_admin';
    }

    public function canAccessConversation(Conversation $conversation): bool
    {
        if ($this->isPlatformSuperAdmin()) {
            return true;
        }

        $companyId = session('company_id');

        if (!$companyId || (int) $conversation->company_id !== (int) $companyId) {
            return false;
        }

        if ($this->role() === 'company_super_admin') {
            return $this->companies()
                ->where('companies.id', $companyId)
                ->wherePivot('status', 'active')
                ->exists();
        }

        return $conversation->participants()->whereKey($this->id)->exists();
    }
}
