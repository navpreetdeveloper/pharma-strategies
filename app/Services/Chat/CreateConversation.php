<?php

namespace App\Services\Chat;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateConversation
{
    public function execute(User $actor, int $companyId, array $data, ?string $ipAddress): Conversation
    {
        return DB::transaction(function () use ($actor, $companyId, $data, $ipAddress) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()->activeInCompany($companyId), 403);
            abort_if((int) $data['user_id'] === $actor->id, 422, 'Choose another employee.');
            $target = User::whereKey($data['user_id'])->where('status', 'active')->when(config('security.require_email_verification'), fn ($q) => $q->whereNotNull('email_verified_at'))
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId)->where('company_user.status', 'active'))
                ->firstOrFail();

            if ($data['type'] === 'direct') {
                $directKey = $this->directConversationKey($actor->id, $target->id);
                $conversation = Conversation::where('company_id', $companyId)
                    ->where('type', 'direct')
                    ->where('direct_key', $directKey)
                    ->first();

                // Backward-compatible guard for older conversations created before direct_key existed.
                if (! $conversation) {
                    $conversation = Conversation::where('company_id', $companyId)
                        ->where('type', 'direct')
                        ->whereHas('participants', fn ($q) => $q->where('users.id', $actor->id))->whereHas('participants', fn ($q) => $q->where('users.id', $target->id))->has('participants', '=', 2)->first();
                }

                if ($conversation) {
                    if (! $conversation->direct_key) {
                        $conversation->forceFill(['direct_key' => $directKey])->saveQuietly();
                    }

                    return $conversation;
                }

                try {
                    $conversation = Conversation::create([
                        'company_id' => $companyId,
                        'type' => 'direct',
                        'name' => null,
                        'direct_key' => $directKey,
                        'created_by' => $actor->id,
                    ]);
                } catch (QueryException $exception) {
                    if ((string) $exception->getCode() !== '23000') {
                        throw $exception;
                    }
                    $conversation = Conversation::where('company_id', $companyId)
                        ->where('type', 'direct')
                        ->where('direct_key', $directKey)
                        ->firstOrFail();
                }
            } else {
                $conversation = Conversation::create([
                    'company_id' => $companyId,
                    'type' => $data['type'],
                    'name' => $data['name'],
                    'direct_key' => null,
                    'created_by' => $actor->id,
                ]);
            }

            $conversation->participants()->syncWithoutDetaching([$actor->id, $target->id]);

            AuditLog::create([
                'company_id' => $companyId,
                'user_id' => $actor->id,
                'action' => 'conversation_created',
                'target_type' => 'Conversation',
                'target_id' => $conversation->id,
                'ip_address' => $ipAddress,
            ]);

            return $conversation;
        });
    }

    private function directConversationKey(int $firstUserId, int $secondUserId): string
    {
        $ids = [$firstUserId, $secondUserId];
        sort($ids, SORT_NUMERIC);

        return $ids[0].':'.$ids[1];
    }
}
