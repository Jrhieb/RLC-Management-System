@php
    $caseworkerErrors = $errors->getBag('createCaseworker');
@endphp
<div x-data="{
    saving: false,
    clearPasswords() { this.$refs.password.value = ''; this.$refs.confirmation.value = ''; },
    openDialog() {
        if (this.saving) return;
        this.$refs.dialog.showModal();
        (this.$refs.dialog.querySelector('[aria-invalid=true]') || this.$refs.name).focus();
    },
    closeDialog() { if (!this.saving) this.$refs.dialog.close(); }
}"
     x-init="if ({{ \Illuminate\Support\Js::from($caseworkerErrors->any()) }}) $nextTick(() => openDialog())"
     x-on:pageshow.window="if ($event.persisted) { saving = false; clearPasswords(); }">
    <button type="button" data-caseworker-open class="rlcr-button rlcr-button-secondary"
            aria-haspopup="dialog" aria-controls="add-caseworker-dialog" x-on:click="openDialog()">
        <x-rlcp-icon name="user-plus" />Add Caseworker
    </button>

    <dialog id="add-caseworker-dialog" data-caseworker-dialog x-ref="dialog" class="rlcr-caseworker-dialog"
            aria-labelledby="caseworker-title" aria-describedby="caseworker-description caseworker-setup"
            :aria-busy="saving" x-on:cancel="if (saving) $event.preventDefault()" x-on:close="clearPasswords()">
        <div class="rlcr-caseworker-heading">
            <div>
                <h2 id="caseworker-title">Add Caseworker</h2>
                <p id="caseworker-description">Create a caseworker account for this system.</p>
            </div>
            <button type="button" class="rlcr-caseworker-close" aria-label="Close Add Caseworker"
                    :disabled="saving" x-on:click="closeDialog()">&times;</button>
        </div>

        <form method="POST" action="{{ route('caseworkers.store') }}" data-caseworker-form
              x-on:submit="if (saving) { $event.preventDefault(); } else { saving = true; }">
            @csrf
            <div class="rlcr-caseworker-fields">
                @if ($caseworkerErrors->has('account'))
                    <p role="alert" class="rlcr-caseworker-error-summary">{{ $caseworkerErrors->first('account') }}</p>
                @elseif ($caseworkerErrors->any())
                    <p role="alert" class="rlcr-caseworker-error-summary">Please check the highlighted fields.</p>
                @endif
                <div class="rlcr-caseworker-field">
                    <label for="caseworker-name">Full name</label>
                    <input id="caseworker-name" name="name" type="text" x-ref="name" required maxlength="255" autocomplete="name"
                           value="{{ is_string(old('name')) ? old('name') : '' }}" :readonly="saving"
                           aria-invalid="{{ $caseworkerErrors->has('name') ? 'true' : 'false' }}"
                           @if ($caseworkerErrors->has('name')) aria-describedby="caseworker-name-error" @endif>
                    @error('name', 'createCaseworker')<p id="caseworker-name-error" class="rlcr-caseworker-field-error">{{ $message }}</p>@enderror
                </div>
                <div class="rlcr-caseworker-field">
                    <label for="caseworker-email">Email</label>
                    <input id="caseworker-email" name="email" type="email" required maxlength="255" autocomplete="email"
                           value="{{ is_string(old('email')) ? old('email') : '' }}" :readonly="saving"
                           aria-invalid="{{ $caseworkerErrors->has('email') ? 'true' : 'false' }}"
                           @if ($caseworkerErrors->has('email')) aria-describedby="caseworker-email-error" @endif>
                    @error('email', 'createCaseworker')<p id="caseworker-email-error" class="rlcr-caseworker-field-error">{{ $message }}</p>@enderror
                </div>
                <div class="rlcr-caseworker-field">
                    <label for="caseworker-password">Password</label>
                    <input id="caseworker-password" name="password" type="password" x-ref="password" required autocomplete="new-password" :readonly="saving"
                           aria-invalid="{{ $caseworkerErrors->has('password') ? 'true' : 'false' }}"
                           @if ($caseworkerErrors->has('password')) aria-describedby="caseworker-password-error" @endif>
                    @error('password', 'createCaseworker')<p id="caseworker-password-error" class="rlcr-caseworker-field-error">{{ $message }}</p>@enderror
                </div>
                <div class="rlcr-caseworker-field">
                    <label for="caseworker-password-confirmation">Confirm password</label>
                    <input id="caseworker-password-confirmation" name="password_confirmation" type="password" x-ref="confirmation"
                           required autocomplete="new-password" :readonly="saving">
                </div>
                <p id="caseworker-setup" class="rlcr-caseworker-setup">
                    No email is sent when you create the account. Share the login details privately.
                    The caseworker must sign in, request a verification email from the verification screen, and follow its link before accessing the system.
                </p>
            </div>
            <div class="rlcr-caseworker-footer">
                <p role="status" x-cloak x-show="saving">Saving account&hellip;</p>
                <div class="rlcr-actions">
                    <button type="button" class="rlcr-button rlcr-button-secondary" :disabled="saving" x-on:click="closeDialog()">Cancel</button>
                    <button type="submit" data-caseworker-submit class="rlcr-button rlcr-button-primary" :disabled="saving" :aria-busy="saving">
                        <span x-cloak x-show="saving" class="rlcr-saving-spinner" aria-hidden="true"></span>
                        <span x-text="saving ? 'Saving...' : 'Create Caseworker'">Create Caseworker</span>
                    </button>
                </div>
            </div>
        </form>
    </dialog>
</div>
