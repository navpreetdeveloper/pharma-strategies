<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\ConversationResource;
use App\Services\Chat\ConversationDirectory;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function me(Request $request): AccountResource
    {
        return new AccountResource($request->user());
    }

    public function legacyMe(Request $request)
    {
        return response()->json((new AccountResource($request->user()))->resolve($request));
    }

    public function conversations(Request $request, ConversationDirectory $directory)
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $companyId = (int) $request->session()->get('company_id');
        abort_unless($companyId > 0, 403, 'Select a company workspace first.');

        return ConversationResource::collection($directory->query($request->user(), $companyId)
            ->orderByDesc('messages_max_created_at')->orderByDesc('id')->paginate(50));
    }
}
