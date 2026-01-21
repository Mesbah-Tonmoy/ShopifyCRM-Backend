<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\HasRolesAndPermissions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRolesAndPermissions;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'image',
        'google_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Set the avatar image based on email.
     */
    public function updateAvatar(): void
    {
        $hash = md5(strtolower(trim($this->email)));

        $gravatarUrl = "https://www.gravatar.com/avatar/$hash?s=200&d=404";

        // Check if Gravatar exists by looking at the headers
        $headers = @get_headers($gravatarUrl);
        
        // If headers contain "200 OK", the user has a Gravatar
        if ($headers && strpos($headers[0], '200')) {
            $this->image = $gravatarUrl;
        } else {
            // Otherwise, return the UI Avatar
            $name = urlencode($this->name);
            $this->image = "https://ui-avatars.com/api/?name={$name}&color=7F9CF5&background=EBF4FF";
        }
    }

    protected static function booted()
    {
        static::creating(function ($user) {
            if (!$user->image) {
                $user->updateAvatar();
            }
        });

        static::updating(function ($user) {
            if ($user->isDirty('email') || $user->isDirty('name')) {
                $user->updateAvatar();
            }
        });
    }
}
