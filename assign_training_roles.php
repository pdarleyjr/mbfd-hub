$trainingUsers = ['danielgato@miamibeachfl.gov', 'victorwhite@miamibeachfl.gov', 'ClaudioNavas@miamibeachfl.gov', 'michaelsica@miamibeachfl.gov'];
foreach ($trainingUsers as $email) {
    $user = \App\Models\User::where('email', $email)->first();
    if ($user) {
        if (!$user->hasRole('training_admin')) {
            $user->assignRole('training_admin');
            echo "Assigned training_admin to: $email\n";
        } else {
            echo "Already has training_admin: $email\n";
        }
    } else {
        echo "User not found: $email\n";
    }
}
echo 'Done assigning roles';
