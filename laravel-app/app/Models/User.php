<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'panel',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Always store email as lowercase for case-insensitive lookups.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => $value,
            set: fn (string $value) => strtolower($value),
        );
    }

    /**
     * Find user by email (case-insensitive).
     */
    public static function findByEmail(string $email): ?self
    {
        return static::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    }

    /**
     * Get the user's assigned panel (admin or training).
     */
    public function getPanel(): string
    {
        return $this->panel ?? 'admin';
    }

    /**
     * Check if user belongs to training panel.
     */
    public function isTrainingPanel(): bool
    {
        return $this->getPanel() === 'training';
    }

    /**
     * Check if user belongs to admin panel.
     */
    public function isAdminPanel(): bool
    {
        return $this->getPanel() === 'admin';
    }
}
