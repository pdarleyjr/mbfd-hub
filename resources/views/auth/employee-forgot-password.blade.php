<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recover MBFD Identity</title>@include('auth.partials.city-email-style')</head><body><main style="max-width: 26rem"><div class="identity-strip">Miami Beach Fire Department</div><h1>Recover your account</h1><p>Enter your Employee ID. If an eligible account has an authoritative recovery address, private instructions will be sent. For privacy, every request receives the same response.</p>
@if(session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
<form method="POST" action="{{ route('password.email') }}">@csrf<label for="employee_id">Employee ID</label><input id="employee_id" name="employee_id" maxlength="64" required autofocus value="{{ old('employee_id') }}">@error('employee_id')<p class="error">{{ $message }}</p>@enderror<button type="submit">Send recovery instructions</button></form>
</main></body></html>
