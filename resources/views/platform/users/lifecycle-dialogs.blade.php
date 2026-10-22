@foreach($users as $user)
@if($user->platform_role !== 'super_admin')
    @php($removed = $recoverableAccess[$user->id] ?? [])
    @if($removed && $user->status === 'active')
    <x-account-dialog :id="'restore-user-'.$user->id" title="Restore company access" :user="$user" :reopen="$errors->any() && old('manage_user_id') == $user->id && old('account_action') === 'restore'">
        <p class="nc-muted mb-4">Restore a removed membership. Existing sign-in details and chat history stay unchanged.</p>
        <form method="POST" action="{{ route('platform.users.restore-access', $user) }}" class="nc-recovery-form" data-confirm-title="Restore company access?" data-confirm-label="Restore access">
            @csrf
            <input type="hidden" name="manage_user_id" value="{{ $user->id }}"><input type="hidden" name="account_action" value="restore">
            <label class="nc-recovery-wide"><span class="nc-label">Company</span><select name="company_id" class="nc-input" required>@foreach($removed as $option)<option value="{{ $option['company']->id }}">{{ $option['company']->name }}{{ $option['role'] ? ' · '.ucwords(str_replace('_', ' ', $option['role'])) : ' · Choose role below' }}</option>@endforeach</select></label>
            <label class="nc-recovery-wide"><span class="nc-label">Role for older removals</span><select name="role" class="nc-input" required>@foreach(\App\Enums\CompanyRole::employeeValues() as $role)<option value="{{ $role }}">{{ ucwords(str_replace('_', ' ', $role)) }}</option>@endforeach</select><span class="nc-form-help">Saved roles are restored automatically. This selection applies only when the previous role is unavailable.</span></label>
            <label class="nc-recovery-wide"><span class="nc-label">Your administrator password</span><input name="current_password" type="password" class="nc-input" required autocomplete="current-password"></label>
            <label class="nc-recovery-wide"><span class="nc-label">Reason</span><textarea name="reason" class="nc-input" required minlength="5" maxlength="500" rows="2">{{ old('account_action') === 'restore' && old('manage_user_id') == $user->id ? old('reason') : '' }}</textarea></label>
            <div class="nc-employee-edit-footer"><button type="button" class="nc-btn-secondary" data-employee-editor-close>Cancel</button><button type="submit" class="nc-btn">Restore access</button></div>
        </form>
    </x-account-dialog>
    @endif
    <x-account-dialog :id="'delete-user-'.$user->id" title="Permanently delete account" :user="$user" :reopen="$errors->any() && old('manage_user_id') == $user->id && old('account_action') === 'delete'">
        <p class="nc-recovery-access-warning">This cannot be undone. Sign-in credentials and all company access will be deleted. Shared messages and files remain in their conversations under “Deleted account”.</p>
        <form method="POST" action="{{ route('platform.users.destroy', $user) }}" class="nc-recovery-form" data-confirm-title="Permanently delete this account?" data-confirm-label="Delete account" data-confirm-danger="true" data-confirm-text="This account cannot be recovered after permanent deletion.">
            @csrf @method('DELETE')
            <input type="hidden" name="manage_user_id" value="{{ $user->id }}"><input type="hidden" name="account_action" value="delete">
            <label class="nc-recovery-wide"><span class="nc-label">Your administrator password</span><input name="current_password" type="password" class="nc-input" required autocomplete="current-password"></label>
            <label class="nc-recovery-wide"><span class="nc-label">Type {{ $user->email }} to confirm</span><input name="confirmation_email" type="email" class="nc-input" required autocomplete="off"></label>
            <label class="nc-recovery-wide"><span class="nc-label">Deletion reason</span><textarea name="reason" class="nc-input" required minlength="5" maxlength="500" rows="2">{{ old('account_action') === 'delete' && old('manage_user_id') == $user->id ? old('reason') : '' }}</textarea></label>
            <div class="nc-employee-edit-footer"><button type="button" class="nc-btn-secondary" data-employee-editor-close>Cancel</button><button type="submit" class="nc-btn-danger">Delete account</button></div>
        </form>
    </x-account-dialog>
@endif
@endforeach
