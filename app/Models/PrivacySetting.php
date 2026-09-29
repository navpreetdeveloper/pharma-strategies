<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class PrivacySetting extends Model { protected $fillable=['company_id','retention_days','data_residency','allow_admin_message_access','privacy_contact_email']; protected $casts=['allow_admin_message_access'=>'boolean']; }
