<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Verifies a Google sign-in server side. Accepts either:
 *  - an ID token (mobile apps, Google One Tap), checked with Google's tokeninfo endpoint, or
 *  - an OAuth access token (website popup), whose audience is checked before reading the profile.
 * In both cases the token must have been issued to one of OUR client IDs, otherwise
 * another app's token could be replayed here.
 */
class GoogleVerifier
{
    /** @return array{sub:string, email:?string, email_verified:bool, name:?string} */
    public function verify(?string $idToken, ?string $accessToken): array
    {
        $allowed = config('services.google.client_ids');
        if (! $allowed) {
            throw ValidationException::withMessages(['google' => 'Google sign-in is not set up on this server yet.']);
        }

        if (app()->environment('testing') && ($idToken ?? $accessToken) && str_starts_with((string) ($idToken ?? $accessToken), 'test:')) {
            // Test-only shortcut: "test:<sub>:<email>"
            [, $sub, $email] = explode(':', (string) ($idToken ?? $accessToken), 3) + [null, null, null];

            return ['sub' => $sub, 'email' => $email, 'email_verified' => true, 'name' => 'Test User'];
        }

        if ($idToken) {
            $info = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
            $d = $info->json() ?? [];
            $ok = $info->ok()
                && in_array($d['aud'] ?? null, $allowed, true)
                && in_array($d['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
                && (int) ($d['exp'] ?? 0) > time();
            if (! $ok) {
                throw ValidationException::withMessages(['google' => 'Google sign-in failed. Please try again.']);
            }

            return [
                'sub' => (string) $d['sub'],
                'email' => $d['email'] ?? null,
                'email_verified' => filter_var($d['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
                'name' => $d['name'] ?? null,
            ];
        }

        if ($accessToken) {
            $info = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $accessToken])->json() ?? [];
            if (! in_array($info['aud'] ?? $info['azp'] ?? null, $allowed, true) || (int) ($info['expires_in'] ?? 0) <= 0) {
                throw ValidationException::withMessages(['google' => 'Google sign-in failed. Please try again.']);
            }
            $p = Http::timeout(10)->withToken($accessToken)->get('https://openidconnect.googleapis.com/v1/userinfo')->json() ?? [];
            if (empty($p['sub']) || $p['sub'] !== ($info['sub'] ?? $p['sub'])) {
                throw ValidationException::withMessages(['google' => 'Google sign-in failed. Please try again.']);
            }

            return [
                'sub' => (string) $p['sub'],
                'email' => $p['email'] ?? null,
                'email_verified' => (bool) ($p['email_verified'] ?? false),
                'name' => $p['name'] ?? null,
            ];
        }

        throw ValidationException::withMessages(['google' => 'Missing Google token.']);
    }
}
