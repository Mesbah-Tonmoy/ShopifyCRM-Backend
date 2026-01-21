<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    // Redirect the user to Google
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    // Handle the callback from Google
    public function handleGoogleCallback()
    {
        try {
            // Get user details from Google
            $googleUser = Socialite::driver('google')->stateless()->user();

            // Check if this user already exists in your DB by google_id or email
            $user = User::where('google_id', $googleUser->id)
                ->orWhere('email', $googleUser->email)
                ->first();

            if (!$user) {
                // Create a new user if doesn't exist
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password' => Hash::make(Str::random(24)),
                ]);
            } else if (!$user->google_id) {
                // Link Google ID if user exists by email but hasn't linked Google yet
                $user->update(['google_id' => $googleUser->id]);
            }

            // Create a Sanctum token for the SPA
            $token = $user->createToken('auth-token')->plainTextToken;

            // Redirect to frontend callback URL with the token
            // We use the FRONTEND_URL from env, defaulting to localhost for dev
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:8080');
            return redirect($frontendUrl . '/auth/callback?token=' . $token);

        } catch (Exception $e) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:8080');
            return redirect($frontendUrl . '/login?error=' . urlencode('Google authentication failed: ' . $e->getMessage()));
        }
    }
}
