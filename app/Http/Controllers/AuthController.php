<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\GoogleVerifier;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp, private ConsentService $consents) {}

    // ------------------------------------------------------------------
    // 1. Phone number + one-time code
    // ------------------------------------------------------------------

    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', Phone::rule()]]);
        $phone = Phone::normalize($request->phone);
        $this->otp->send($phone);

        return response()->json([
            'sent' => true,
            // Tells the app whether to show the 18+ and terms boxes (new accounts only).
            'new_account' => ! User::where('phone', $phone)->exists(),
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', Phone::rule()],
            'code' => ['required', 'digits:6'],
            'confirm_adult' => ['nullable', 'boolean'],
            'accept_terms' => ['nullable', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);
        $phone = Phone::normalize($data['phone']);
        if (! $this->otp->verify($phone, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user) {
            $this->requireNewAccountConsents($data);
            $user = User::create(['phone' => $phone, 'phone_verified_at' => now(), 'adult_confirmed_at' => now()]);
            $this->consents->recordAccountConsents($user, $this->source($request));
        } elseif (! $user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }
        $this->linkGuestBookings($user);

        return $this->issue($user, $data['device_name'] ?? $this->source($request));
    }

    // ------------------------------------------------------------------
    // 2. Username (or email / phone) + password
    // ------------------------------------------------------------------

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9_.]+$/', 'unique:users,username'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'name' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email:rfc', 'max:120', 'unique:users,email'],
            'accept_terms' => ['accepted'],
            'confirm_adult' => ['accepted'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ], self::consentMessages() + [
            'username.regex' => 'Use lowercase letters, numbers, dots or underscores.',
            'username.unique' => 'That username is taken.',
            'email.unique' => 'An account already uses this email. Sign in instead, or reset your password.',
        ]);

        $user = User::create([
            'username' => $data['username'],
            'name' => $data['name'] ?? null,
            'email' => isset($data['email']) ? Str::lower($data['email']) : null,
            'adult_confirmed_at' => now(),
        ]);
        $user->forceFill(['password' => $data['password']])->save();
        $this->consents->recordAccountConsents($user, $this->source($request));

        return $this->issue($user, $data['device_name'] ?? $this->source($request), 201);
    }

    /** Sign in with a username, email or phone number plus password. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);
        $key = 'login:'.Str::lower($data['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds, or reset your password.']);
        }

        $user = $this->findByLogin($data['login']);
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            // Same message whether the account exists or not.
            throw ValidationException::withMessages(['login' => 'Username or password is incorrect.']);
        }
        RateLimiter::clear($key);

        return $this->issue($user, $data['device_name'] ?? $this->source($request));
    }

    /** Send a reset code to the account's verified phone, or its email. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:120']]);
        $user = $this->findByLogin($data['login']);
        if ($user && ($to = $this->resetDestination($user))) {
            $this->otp->send($to, 'reset');
        }

        // Never reveal whether an account exists.
        return response()->json(['message' => 'If an account matches, we’ve sent a 6-digit code to its phone or email.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $user = $this->findByLogin($data['login']);
        $to = $user ? $this->resetDestination($user) : null;
        if (! $to || ! $this->otp->verify($to, $data['code'], 'reset')) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }
        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()->delete(); // sign out everywhere after a reset

        return $this->issue($user, $this->source($request));
    }

    // ------------------------------------------------------------------
    // 3. Google
    // ------------------------------------------------------------------

    public function google(Request $request, GoogleVerifier $google): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required_without:access_token', 'nullable', 'string'],
            'access_token' => ['required_without:id_token', 'nullable', 'string'],
            'confirm_adult' => ['nullable', 'boolean'],
            'accept_terms' => ['nullable', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);
        $g = $google->verify($data['id_token'] ?? null, $data['access_token'] ?? null);

        $user = User::where('google_id', $g['sub'])->first();
        // Only join an existing account by email when BOTH sides have verified that email.
        if (! $user && $g['email'] && $g['email_verified']) {
            $user = User::where('email', $g['email'])->whereNotNull('email_verified_at')->first();
            $user?->forceFill(['google_id' => $g['sub']])->save();
        }

        if (! $user) {
            if (! ($data['confirm_adult'] ?? false) || ! ($data['accept_terms'] ?? false)) {
                // The app shows the two boxes and sends the same token again.
                return response()->json([
                    'message' => 'Please confirm you are 18 or older and accept the terms to create your account.',
                    'needs_consent' => true,
                    'name' => $g['name'],
                    'errors' => array_filter([
                        'confirm_adult' => ($data['confirm_adult'] ?? false) ? null : [self::consentMessages()['confirm_adult.accepted']],
                        'accept_terms' => ($data['accept_terms'] ?? false) ? null : [self::consentMessages()['accept_terms.accepted']],
                    ]),
                ], 422);
            }
            $emailFree = $g['email'] && ! User::where('email', $g['email'])->exists();
            $user = User::create([
                'name' => $g['name'],
                'email' => $emailFree ? $g['email'] : null,
                'email_verified_at' => $emailFree && $g['email_verified'] ? now() : null,
                'adult_confirmed_at' => now(),
            ]);
            $user->forceFill(['google_id' => $g['sub']])->save();
            $this->consents->recordAccountConsents($user, $this->source($request));
        }

        return $this->issue($user, $data['device_name'] ?? $this->source($request));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function consentMessages(): array
    {
        return [
            'confirm_adult.accepted' => 'You must be 18 or older to create an account. If you are under 18, ask a parent or guardian to book for you.',
            'accept_terms.accepted' => 'Please accept the Terms of Service and Privacy Policy to create an account.',
        ];
    }

    private function requireNewAccountConsents(array $data): void
    {
        $errors = [];
        if (! ($data['confirm_adult'] ?? false)) {
            $errors['confirm_adult'] = self::consentMessages()['confirm_adult.accepted'];
        }
        if (! ($data['accept_terms'] ?? false)) {
            $errors['accept_terms'] = self::consentMessages()['accept_terms.accepted'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function findByLogin(string $login): ?User
    {
        $login = trim($login);
        if (str_contains($login, '@')) {
            return User::where('email', Str::lower($login))->first() ?? User::where('email', $login)->first();
        }
        if ($phone = Phone::normalize($login)) {
            return User::where('phone', $phone)->first();
        }

        return User::where('username', Str::lower($login))->first();
    }

    private function resetDestination(User $u): ?string
    {
        return ($u->phone && $u->phone_verified_at) ? $u->phone : ($u->email ?: null);
    }

    private function linkGuestBookings(User $user): void
    {
        if ($user->phone && $user->phone_verified_at) {
            Booking::whereNull('user_id')->where('contact_phone', $user->phone)->update(['user_id' => $user->id]);
        }
    }

    private function source(Request $r): string
    {
        return $r->header('X-Client') === 'mobile' ? 'mobile' : 'web';
    }

    private function issue(User $user, string $device, int $status = 200): JsonResponse
    {
        $hours = $user->role === User::ROLE_PASSENGER ? 24 * 30 : 12;
        $token = $user->createToken(substr($device, 0, 60), ['*'], now()->addHours($hours))->plainTextToken;

        return response()->json(['token' => $token, 'user' => $this->me($user)], $status);
    }

    /** Staff sign in with email + password (operators and platform admins). */
    public function staffLogin(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $data['email'])->where('role', '!=', User::ROLE_PASSENGER)->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email or password is incorrect.']);
        }

        return response()->json(['token' => $user->createToken('staff', ['*'], now()->addHours(12))->plainTextToken, 'user' => $this->me($user)]);
    }

    public function invitation(string $token): JsonResponse
    {
        $inv = \App\Models\StaffInvitation::with('operator:id,name')->where('token', $token)->whereNull('accepted_at')->where('expires_at', '>', now())->firstOrFail();

        return response()->json(['name' => $inv->name, 'email' => $inv->email, 'role' => $inv->role, 'operator' => $inv->operator?->name]);
    }

    /** A staff member sets their password from the emailed invitation link. */
    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:10', 'confirmed'],
            'confirm_adult' => ['accepted'],
        ], ['confirm_adult.accepted' => 'Staff accounts are for people aged 18 or older.']);
        $inv = \App\Models\StaffInvitation::where('token', $token)->whereNull('accepted_at')->where('expires_at', '>', now())->firstOrFail();

        $user = User::firstOrNew(['phone' => $inv->phone]);
        $user->fill(['name' => $inv->name, 'email' => $inv->email])->forceFill([
            'role' => $inv->role, 'operator_id' => $inv->operator_id, 'password' => $request->password,
            'adult_confirmed_at' => now(), 'email_verified_at' => now(),
        ])->save();
        $inv->update(['accepted_at' => now()]);
        \App\Models\AuditLog::record('staff.joined', $user, ['role' => $inv->role], $inv->operator_id);

        return response()->json(['token' => $user->createToken('staff', ['*'], now()->addHours(12))->plainTextToken, 'user' => $this->me($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->me($request->user()));
    }

    private function me(User $u): array
    {
        return $u->only(['id', 'name', 'username', 'phone', 'email', 'locale', 'role', 'operator_id']) + ['login_methods' => $u->loginMethods()];
    }
}
