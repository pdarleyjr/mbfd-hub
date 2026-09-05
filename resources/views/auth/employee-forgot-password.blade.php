<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset MBFD Hub Password</title><style>
body{margin:0;min-height:100vh;padding:1rem;display:grid;place-items:center;background:#f1f5f9;color:#172033;font-family:Arial,sans-serif}main{box-sizing:border-box;width:min(100%,26rem);padding:2rem;border-radius:.75rem;background:#fff;box-shadow:0 1rem 3rem rgba(15,23,42,.12)}label{display:block;margin-top:1rem;font-weight:700}input,button{box-sizing:border-box;width:100%;min-height:44px;margin-top:.4rem;padding:.75rem;border-radius:.4rem;font:inherit}input{border:1px solid #94a3b8}button{margin-top:1.5rem;border:0;background:#b91c1c;color:#fff;font-weight:700}.status{padding:.75rem;background:#dcfce7}.error{color:#b91c1c}
</style></head><body><main><h1>Reset password</h1><p>Enter your Employee ID. For privacy, every request receives the same response.</p>
@if(session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
<form method="POST" action="{{ route('password.email') }}">@csrf<label for="employee_id">Employee ID</label><input id="employee_id" name="employee_id" maxlength="64" required autofocus value="{{ old('employee_id') }}">@error('employee_id')<p class="error">{{ $message }}</p>@enderror<button type="submit">Request reset link</button></form>
</main></body></html>
