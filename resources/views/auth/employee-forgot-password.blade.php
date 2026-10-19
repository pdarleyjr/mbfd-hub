<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><x-hub.head />
<title>Reset your password - MBFD Hub</title>@include('auth.partials.city-email-style')</head><body><main style="max-width: 26rem"><div class="identity-strip">Miami Beach Fire Department</div><h1>Reset your password</h1><p>Enter your Employee ID. We will email a private link to the address on file so you can choose a new password.</p>
@if(session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
<form method="POST" action="{{ route('password.email') }}">@csrf<label for="employee_id">Employee ID</label><input id="employee_id" name="employee_id" maxlength="64" required autofocus value="{{ old('employee_id') }}">@error('employee_id')<p class="error">{{ $message }}</p>@enderror<button type="submit">Email my link</button></form>
</main></body></html>
