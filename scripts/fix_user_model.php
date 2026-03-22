<?php
// Fix User.php canAccessPanel to allow training users through admin panel auth
// so RedirectTrainingUsers middleware can redirect them to /training

$file = '/var/www/html/app/Models/User.php';
$content = file_get_contents($file);

$old = <<<'PHP'
    /**
     * Determine if the user can access the Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'training') {
            return $this->hasRole('super_admin')
                || $this->hasRole('training_admin')
                || $this->hasRole('training_viewer')
                || $this->can('training.access');
        }

        // Admin panel: only allow super_admin and admin roles
        // Training-only users must NOT access admin panel
        if ($panel->getId() === 'admin') {
            return $this->hasRole('super_admin')
                || $this->hasRole('admin');
        }

        return false;
    }
PHP;

$new = <<<'PHP'
    /**
     * Determine if the user can access the Filament admin panel.
     * Training users are allowed through admin panel auth check so the
     * RedirectTrainingUsers middleware can redirect them to /training.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'training') {
            return $this->hasRole('super_admin')
                || $this->hasRole('training_admin')
                || $this->hasRole('training_viewer')
                || $this->can('training.access');
        }

        // Admin panel: allow any user with a valid role
        // Training-only users will be redirected by RedirectTrainingUsers middleware
        if ($panel->getId() === 'admin') {
            return $this->hasRole('super_admin')
                || $this->hasRole('admin')
                || $this->hasRole('training_admin')
                || $this->hasRole('training_viewer');
        }

        return false;
    }
PHP;

if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    file_put_contents($file, $content);
    echo "SUCCESS: User.php canAccessPanel() updated.\n";
} else {
    echo "ERROR: Could not find the old canAccessPanel method.\n";
    echo "Current content around canAccessPanel:\n";
    preg_match('/canAccessPanel.*?^    \}/ms', $content, $matches);
    echo $matches[0] ?? 'NOT FOUND';
}
