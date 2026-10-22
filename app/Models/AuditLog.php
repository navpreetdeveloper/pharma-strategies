<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public static function categories(): array
    {
        return [
            'access' => ['employee_created', 'employee_updated', 'employee_access_removed', 'employee_access_restored', 'platform_account_deleted', 'platform_company_admin_created', 'platform_super_admin_created', 'platform_super_admin_granted', 'platform_super_admin_revoked'],
            'security' => ['login', 'logout', 'password_changed', 'platform_password_reset', 'mfa_enabled', 'mfa_disabled', 'privileged_conversation_read'],
            'workspace' => ['platform_company_created', 'platform_company_updated', 'platform_company_archived', 'platform_company_restored', 'platform_company_workspace_opened', 'privacy_policy_updated', 'company_registered'],
            'messaging' => ['conversation_created', 'message_edited', 'message_unsent', 'message_forwarded'],
        ];
    }

    public static function recentActions(): array
    {
        return array_merge(self::categories()['access'], array_diff(self::categories()['security'], ['login', 'logout', 'privileged_conversation_read']), array_diff(self::categories()['workspace'], ['platform_company_workspace_opened']));
    }

    public static function importantActions(): array
    {
        return array_merge(self::recentActions(), ['privileged_conversation_read', 'message_unsent']);
    }

    public function activityLabel(): string
    {
        return [
            'employee_created' => 'Employee created', 'employee_updated' => 'Employee access updated',
            'employee_access_removed' => 'Company access removed', 'platform_company_admin_created' => 'Company administrator created',
            'employee_access_restored' => 'Company access restored', 'platform_account_deleted' => 'Account permanently deleted',
            'platform_company_created' => 'Company created', 'platform_company_updated' => 'Company details updated',
            'platform_company_archived' => 'Company archived', 'platform_company_restored' => 'Company restored',
            'platform_company_workspace_opened' => 'Workspace opened', 'privacy_policy_updated' => 'Privacy policy updated',
            'company_registered' => 'Company registered', 'login' => 'Signed in', 'logout' => 'Signed out',
            'password_changed' => 'Password changed', 'platform_password_reset' => 'Temporary password issued',
            'mfa_enabled' => 'Authenticator enabled', 'mfa_disabled' => 'Authenticator disabled',
            'privileged_conversation_read' => 'Conversation reviewed', 'conversation_created' => 'Conversation started',
            'message_edited' => 'Message edited', 'message_unsent' => 'Message unsent', 'message_forwarded' => 'Message forwarded',
            'platform_super_admin_created' => 'Platform administrator created',
            'platform_super_admin_granted' => 'Platform access granted', 'platform_super_admin_revoked' => 'Platform access removed',
        ][$this->action] ?? 'Other activity';
    }

    public function activityTarget(): string
    {
        $type = class_basename($this->target_type ?? '');

        return match ($type) {
            'User' => $this->targetUser?->name ?? 'Former account #'.$this->target_id,
            'Company' => $this->company?->name ?? 'Company #'.$this->target_id,
            'Conversation' => 'Conversation #'.$this->target_id,
            'Message' => 'Message #'.$this->target_id,
            '' => in_array($this->action, ['login', 'logout', 'password_changed', 'mfa_enabled', 'mfa_disabled']) ? 'Account' : 'Workspace',
            default => 'Archived record',
        };
    }

    protected $fillable = ['company_id', 'user_id', 'action', 'target_type', 'target_id', 'ip_address', 'metadata'];

    protected $casts = ['metadata' => 'array'];
}
