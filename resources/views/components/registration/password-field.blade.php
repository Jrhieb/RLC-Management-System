@props(['name', 'label'])
<div class="registration-field">
    <label for="registration-{{ $name }}">{{ $label }}</label>
    <div class="registration-password">
        <input id="registration-{{ $name }}" name="{{ $name }}" type="password" required autocomplete="new-password"
            placeholder="{{ $label }}" passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            @error($name) aria-invalid="true" aria-describedby="registration-{{ $name }}-error" @enderror>
        <button type="button" data-password-toggle aria-controls="registration-{{ $name }}" aria-label="Show {{ strtolower($label) }}" aria-pressed="false" hidden>Show</button>
    </div>
    @error($name)<p class="registration-error" id="registration-{{ $name }}-error" role="alert">{{ $message }}</p>@enderror
</div>
