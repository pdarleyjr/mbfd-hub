<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class CustomLogin extends BaseLogin
{
    /**
     * Override the login handler to redirect based on user's panel.
     */
    public function authenticate(): RedirectResponse
    {
        $result = parent::authenticate();
        
        // If authentication failed, return the response
        if ($result instanceof RedirectResponse && $result->getStatusCode() !== 302) {
            return $result;
        }
        
        // Get the authenticated user
        $user = Auth::user();
        
        if ($user && method_exists($user, 'getPanel')) {
            $panel = $user->getPanel();
            
            // Redirect to the appropriate panel based on user's panel assignment
            $redirectPath = match($panel) {
                'training' => '/training',
                default => '/admin',
            };
            
            // Only redirect if not already on the correct panel
            $currentPath = request()->path();
            $targetPath = ltrim($redirectPath, '/');
            
            if (!str_starts_with($currentPath, $targetPath)) {
                return redirect()->to($redirectPath);
            }
        }
        
        return $result;
    }
}