<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use App\Services\GoogleVerifier;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Add, change or remove the ways a signed-in person can sign in. */
class LoginMethodsController extends Controller
{
    private function out(User $u): JsonResponse
    {
        return response()->json($u->only(['username', 'phone', 'email']) + ['login_methods' => $u->loginMethods()]);
    }

    /** Set a username + password (first time), or change the password. */
    public function password(Request $request): JsonResponse
    {
        $u = $request->user();
        $data = $request->validate([
            'current_password' => [$u->password ? 'required' : 'nullable', 'string'],
            'username' => [$u->username ? 'nullable' : 'required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9_.]+$/', 'unique:users,username,'.$u->id],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], ['username.regex' => 'Use lowercase letters, numbers, dots or underscores.', 'username.unique' => 'That username is taken.']);
        if ($u->password && ! Hash::check($data['current_password'] ?? '', $u->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }
        $u->forceFill(array_filter(['username' => $u->username ? null : $data['username'], 'password' => $data['password']]))->save();
        // Sign out other devices after a password change.
        $u->tokens()->where('id', '!=', $u->currentAccessToken()?->id)->delete();
        AuditLog::record('account.password_set', $u);

        return $this->out($u);
    }

    public function linkGoogle(Request $request, GoogleVerifier $google): JsonResponse
    {
        $data = $request->validate(['id_token' => ['required_without:access_token', 'nullable', 'string'], 'access_token' => ['required_without:id_token', 'nullable', 'string']]);
        $g = $google->verify($data['id_token'] ?? null, $data['access_token'] ?? null);
        $other = User::where('google_id', $g['sub'])->where('id', '!=', $request->user()->id)->exists();
        abort_if($other, 422, 'This Google account is already linked to another Bemengede account.');
        $request->user()->forceFill(['google_id' => $g['sub']])->save();
        AuditLog::record('account.google_linked', $request->user());

        return $this->out($request->user());
    }

    public function unlinkGoogle(Request $request): JsonResponse
    {
        $u = $request->user();
        $m = $u->loginMethods();
        abort_unless($m['password'] || $m['phone'], 422, 'Add a password or a phone number first, so you can still sign in.');
        $u->forceFill(['google_id' => null])->save();
        AuditLog::record('account.google_unlinked', $u);

        return $this->out($u);
    }

    /** Add or change the phone number: step 1 sends a code to the new number. */
    public function phoneCode(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', Phone::rule()]]);
        $phone = Phone::normalize($request->phone);
        abort_if(User::where('phone', $phone)->where('id', '!=', $request->user()->id)->exists(), 422, 'This number is already used by another account.');
        $otp->send($phone, 'link');

        return response()->json(['sent' => true]);
    }

    /** Step 2: confirm the code; earlier guest bookings with this number are linked. */
    public function phone(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', Phone::rule()], 'code' => ['required', 'digits:6']]);
        $phone = Phone::normalize($data['phone']);
        if (! $otp->verify($phone, $data['code'], 'link')) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }
        abort_if(User::where('phone', $phone)->where('id', '!=', $request->user()->id)->exists(), 422, 'This number is already used by another account.');
        $u = $request->user();
        $u->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();
        Booking::whereNull('user_id')->where('contact_phone', $phone)->update(['user_id' => $u->id]);
        AuditLog::record('account.phone_verified', $u);

        return $this->out($u);
    }
}
