    @foreach($users as $user)
    @if($user->status === 'active' && $user->platform_role !== 'super_admin')
    @php($hasWorkspace = $user->companies->contains(fn ($company) => $company->status === 'active' && $company->pivot->status === 'active'))
    <x-account-dialog :id="'reset-user-'.$user->id" title="Reset password" :user="$user" :reopen="$errors->any() && old('reset_user_id') == $user->id">
            <div id="reset-help-{{ $user->id }}" class="nc-recovery-notice"><x-icon name="shield" size="20" /><div><strong>Set a temporary sign-in password</strong><p>Verify the person, then share the temporary password privately. Existing sessions and device notifications are disconnected; the user must choose a personal password at sign-in. Authenticator settings stay unchanged.</p></div></div>
            @unless($hasWorkspace)<p class="nc-recovery-access-warning"><strong>No active company access.</strong> This reset will not grant access or let the account enter a workspace. Company access is managed separately.</p>@endunless
            <form method="POST" action="{{ route('platform.users.reset-password', $user) }}" class="nc-recovery-form" data-confirm-title="Reset this user's password?" data-confirm-label="Reset password" data-confirm-danger="true" data-confirm-text="This ends their current sessions and requires a new personal password at sign-in.">
                @csrf<input type="hidden" name="reset_user_id" value="{{ $user->id }}">
                <label class="nc-recovery-wide"><span class="nc-label">Your administrator password</span><input type="password" name="current_password" class="nc-input" required autocomplete="current-password"><span class="nc-form-help">Enter your own Platform Super Admin password to authorize this reset.</span></label>
                <label><span class="nc-label">Temporary password</span><input type="password" name="password" class="nc-input" required minlength="8" autocomplete="new-password"><span class="nc-form-help">At least 8 characters, uppercase, lowercase and a number.</span></label>
                <label><span class="nc-label">Confirm temporary password</span><input type="password" name="password_confirmation" class="nc-input" required minlength="8" autocomplete="new-password"></label>
                <label class="nc-recovery-wide"><span class="nc-label">Recovery reason</span><textarea name="reason" class="nc-input" required minlength="5" maxlength="500" rows="2">{{ old('reset_user_id') == $user->id ? old('reason') : '' }}</textarea></label>
                <label class="nc-checkbox nc-recovery-wide"><input type="checkbox" name="identity_verified" value="1" required> I verified this person's identity before resetting their password.</label>
                <div class="nc-employee-edit-footer"><button type="button" class="nc-btn-secondary" data-employee-editor-close>Cancel</button><button type="submit" class="nc-btn">Reset password</button></div>
            </form>
    </x-account-dialog>
    @endif
    @endforeach
