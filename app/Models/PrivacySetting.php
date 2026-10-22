<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivacySetting extends Model
{
    protected $attributes = ['allow_admin_message_access' => false];

    protected $fillable = ['company_id', 'retention_days', 'data_residency', 'allow_admin_message_access', 'privacy_contact_email'];

    protected $casts = ['allow_admin_message_access' => 'boolean'];

    public static function administratorReviewAllowed(int $companyId): bool
    {
        // Missing policies deny review. Never cache permission across requests.
        return static::where('company_id', $companyId)->where('allow_admin_message_access', true)->exists();
    }
}
