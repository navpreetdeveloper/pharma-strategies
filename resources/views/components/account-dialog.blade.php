@props(['id', 'title', 'user', 'reopen' => false])
<dialog id="{{ $id }}" class="nc-employee-dialog nc-recovery-dialog" aria-labelledby="{{ $id }}-title" @if($reopen) data-employee-editor-reopen @endif>
    <div class="nc-employee-dialog-header">
        <div><h2 id="{{ $id }}-title">{{ $title }}</h2><p>{{ $user->name }} &middot; {{ $user->email }}</p></div>
        <button type="button" class="nc-employee-dialog-close" data-employee-editor-close aria-label="Close dialog"><x-icon name="close" size="18" /></button>
    </div>
    <div class="nc-employee-dialog-body">{{ $slot }}</div>
</dialog>
