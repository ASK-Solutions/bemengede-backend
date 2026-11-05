<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', Phone::rule()]]);
        $phone = Phone::normalize($request->phone);
        $this->otp->send($phone);

        return response()->json([
            'sent' => true,
            // Tells the app whether to show the 18+ confirmation (new accounts only).
            'new_account' => ! User::where('phone', $phone)->exists(),
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', Phone::rule()],
            'code' => ['required', 'digits:6'],
            'confirm_adult' => ['nullable', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);
        $phone = Phone::normalize($data['phone']);
        if (! $this->otp->verify($phone, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user) {
            // Accounts are for adults only. Children travel on an adult's booking.
            if (! ($data['confirm_adult'] ?? false)) {
                throw ValidationException::withMessages([
                    'confirm_adult' => 'You must be 18 or older to create an account. If you are under 18, ask a parent or guardian to book for you.',
                ]);
            }
            $user = User::create(['phone' => $phone, 'phone_verified_at' => now(), 'adult_confirmed_at' => now()]);
        }
        // Link earlier guest bookings made with this number.
        Booking::whereNull('user_id')->where('contact_phone', $phone)->update(['user_id' => $user->id]);

        $token = $user->createToken($data['device_name'] ?? 'web', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json(['token' => $token, 'user' => $this->me($user)]);
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
        return $u->only(['id', 'name', 'phone', 'email', 'locale', 'role', 'operator_id']);
    }
}
