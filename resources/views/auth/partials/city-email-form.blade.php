<form method="POST" action="{{ route('city-email.store') }}">
    @csrf
    <input type="hidden" name="username" autocomplete="username" value="{{ $user->employee_id }}">
    <label for="city-email">Your city email</label>
    <input id="city-email" name="email" type="email" autocomplete="email" maxlength="255" value="{{ old('email', $emailValue) }}" required aria-describedby="city-email-help" @error('email') aria-invalid="true" @enderror>
    <p id="city-email-help" class="small">Use your own @miamibeachfl.gov mailbox. We will send a verification link; confirming this form alone does not verify the address.</p>
    <label for="current-password">Current Hub password</label>
    <input id="current-password" name="current_password" type="password" autocomplete="current-password" maxlength="4096" required @error('current_password') aria-invalid="true" @enderror>
    <label class="checkbox"><input type="checkbox" name="ownership_confirmed" value="1" required><span>I confirm this is my city-assigned email address and I can access this mailbox.</span></label>
    <button type="submit">Confirm address & send verification</button>
</form>
