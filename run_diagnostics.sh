#!/bin/bash
cd /root/mbfd-hub

echo "================================="
echo "LOGIN DIAGNOSTIC REPORT"
echo "================================="
echo ""

echo "STEP 1: Users in Database"
echo "--------------------------"
docker compose exec -T laravel.test php artisan tinker --execute='
use App\Models\User;
$users = User::all();
foreach ($users as $u) {
    echo "ID: {$u->id}\n";
    echo "Email: {$u->email}\n";
    echo "Name: {$u->name}\n";
    echo "Hash: " . substr($u->password, 0, 30) . "...\n";
    echo "Email Verified: " . ($u->email_verified_at ? "YES" : "NO") . "\n\n";
}
'

echo ""
echo "STEP 2: Testing PeterDarley@miamibeachfl.gov / Penco3"
echo "------------------------------------------------------"
docker compose exec -T laravel.test php artisan tinker --execute='
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

$email = "PeterDarley@miamibeachfl.gov";
$password = "Penco3";

$user = User::where("email", $email)->first();
if (!$user) {
    echo "Result: USER NOT FOUND\n";
} else {
    echo "User exists: YES\n";
    echo "Email verified: " . ($user->email_verified_at ? "YES" : "NO") . "\n";
    $hashCheck = Hash::check($password, $user->password);
    echo "Password hash check: " . ($hashCheck ? "PASS" : "FAIL") . "\n";
    try {
        $authAttempt = Auth::attempt(["email" => $email, "password" => $password]);
        echo "Auth::attempt: " . ($authAttempt ? "SUCCESS" : "FAILED") . "\n";
        if ($authAttempt) { Auth::logout(); }
    } catch (Exception $e) {
        echo "Auth::attempt ERROR: " . $e->getMessage() . "\n";
    }
}
'

echo ""
echo "STEP 3: Check if User Model requires email verification"
echo "--------------------------------------------------------"
docker compose exec -T laravel.test bash -c 'grep -n "MustVerifyEmail" app/Models/User.php || echo "MustVerifyEmail: NO"'

echo ""
echo "================================="
echo "END OF REPORT"
echo "================================="
