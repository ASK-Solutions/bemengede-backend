<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\OtpCode;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        Mail::fake();
        config(['services.google.client_ids' => ['test-client']]);
    }

    private function register(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'username' => 'hana_t', 'password' => 'travel2026now', 'password_confirmation' => 'travel2026now',
            'accept_terms' => true, 'confirm_adult' => true,
        ], $over));
    }

    public function test_register_with_username_requires_consents_and_a_strong_password(): void
    {
        $this->register(['accept_terms' => false])->assertJsonValidationErrors('accept_terms');
        $this->register(['confirm_adult' => false])->assertJsonValidationErrors('confirm_adult');
        $this->register(['password' => 'short', 'password_confirmation' => 'short'])->assertJsonValidationErrors('password');
        $this->register(['username' => 'Has Spaces'])->assertJsonValidationErrors('username');

        $this->register()->assertCreated()->assertJsonStructure(['token', 'user' => ['username', 'login_methods']])
            ->assertJsonPath('user.login_methods.password', true);
        $this->assertDatabaseHas('consents', ['type' => 'terms', 'granted' => true]);
        $this->register()->assertJsonValidationErrors('username'); // taken
    }

    public function test_login_with_username_and_wrong_password_is_rate_limited(): void
    {
        $this->register();
        $this->postJson('/api/v1/auth/login', ['login' => 'hana_t', 'password' => 'travel2026now'])->assertOk()->assertJsonStructure(['token']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'hana_t', 'password' => 'wrong-password1'])->assertJsonValidationErrors('login');
        }
        $this->postJson('/api/v1/auth/login', ['login' => 'hana_t', 'password' => 'travel2026now'])
            ->assertJsonValidationErrors('login'); // locked for a minute even with the right password
    }

    public function test_unknown_user_gets_the_same_error_as_a_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => 'nobody', 'password' => 'whatever123'])
            ->assertJsonPath('errors.login.0', 'Username or password is incorrect.');
    }

    public function test_password_reset_by_email_code(): void
    {
        $this->register(['email' => 'hana@example.com']);
        $this->postJson('/api/v1/auth/password/forgot', ['login' => 'hana@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/password/forgot', ['login' => 'nobody@example.com'])->assertOk(); // no enumeration
        OtpCode::where('phone', 'hana@example.com')->update(['code_hash' => Hash::make('111222')]);

        $this->postJson('/api/v1/auth/password/reset', ['login' => 'hana_t', 'code' => '111222', 'password' => 'brandnew2026x', 'password_confirmation' => 'brandnew2026x'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => 'hana_t', 'password' => 'brandnew2026x'])->assertOk();
    }

    public function test_google_sign_up_asks_for_consent_then_creates_the_account(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => 'test:g-123:new@example.com'])
            ->assertStatus(422)->assertJsonPath('needs_consent', true);
        $this->postJson('/api/v1/auth/google', ['id_token' => 'test:g-123:new@example.com', 'confirm_adult' => true, 'accept_terms' => true])
            ->assertOk()->assertJsonPath('user.login_methods.google', true);
        // Second time: straight in.
        $this->postJson('/api/v1/auth/google', ['id_token' => 'test:g-123:new@example.com'])->assertOk();
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
    }

    public function test_google_does_not_take_over_an_account_with_an_unverified_email(): void
    {
        $this->register(['email' => 'victim@example.com']); // email not verified
        $this->postJson('/api/v1/auth/google', ['id_token' => 'test:g-999:victim@example.com', 'confirm_adult' => true, 'accept_terms' => true])->assertOk();
        $victim = User::where('username', 'hana_t')->first();
        $this->assertNull($victim->google_id);
    }

    public function test_accounts_without_a_phone_cannot_see_anonymised_bookings(): void
    {
        $trip = Trip::where('departure_at', '>', now()->addDays(2))->first();
        $ref = $this->postJson('/api/v1/bookings', [
            'trip_id' => $trip->id, 'contact_phone' => '0911444555',
            'passengers' => [['full_name' => 'Someone', 'seat_number' => 9, 'fare_category' => 'adult']],
            'accept_terms' => true, 'booker_is_adult' => true,
        ])->json('reference');
        Booking::where('reference', $ref)->update(['contact_phone' => null, 'anonymized_at' => now()]);

        $token = $this->register()->json('token');
        $this->withToken($token)->getJson('/api/v1/me/bookings')->assertOk()->assertJsonCount(0);
        $this->withToken($token)->getJson("/api/v1/bookings/{$ref}")->assertNotFound();
    }

    public function test_adding_a_phone_needs_a_code_and_links_guest_bookings(): void
    {
        $trip = Trip::where('departure_at', '>', now()->addDays(2))->first();
        $this->postJson('/api/v1/bookings', [
            'trip_id' => $trip->id, 'contact_phone' => '0911666777',
            'passengers' => [['full_name' => 'Hana', 'seat_number' => 11, 'fare_category' => 'adult']],
            'accept_terms' => true, 'booker_is_adult' => true,
        ])->assertCreated();
        $user = User::find($this->register()->json('user.id'));
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/phone/code', ['phone' => '0911666777'])->assertOk();
        OtpCode::where('phone', '+251911666777')->update(['code_hash' => Hash::make('333444')]);
        $this->postJson('/api/v1/me/phone', ['phone' => '0911666777', 'code' => '000000'])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/me/phone', ['phone' => '0911666777', 'code' => '333444'])->assertOk()->assertJsonPath('login_methods.phone', true);
        $this->assertSame(1, Booking::where('user_id', $user->id)->count());
    }

    public function test_cannot_remove_google_when_it_is_the_only_way_in(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => 'test:g-55:only@example.com', 'confirm_adult' => true, 'accept_terms' => true]);
        Sanctum::actingAs(User::where('email', 'only@example.com')->first());
        $this->deleteJson('/api/v1/me/google')->assertStatus(422);
    }
}
