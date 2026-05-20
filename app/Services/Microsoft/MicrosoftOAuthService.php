<?php

namespace App\Services\Microsoft;

use App\Models\MicrosoftAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Persists Microsoft Graph OAuth tokens. Mirrors the shape of
 * {@see \App\Services\Google\GoogleOAuthService} so the OAuth callback
 * controllers stay symmetric.
 */
class MicrosoftOAuthService
{
    public function persistAccount(User $user, SocialiteUser $msUser): MicrosoftAccount
    {
        $data = [
            'access_token' => $msUser->token,
            'expires_at' => Carbon::now()->addSeconds((int) ($msUser->expiresIn ?? 3600)),
            'email' => (string) ($msUser->getEmail() ?: ''),
        ];

        if ($msUser->refreshToken) {
            $data['refresh_token'] = $msUser->refreshToken;
        }

        return MicrosoftAccount::updateOrCreate(
            ['user_id' => $user->id, 'microsoft_id' => $msUser->getId()],
            $data,
        );
    }
}
