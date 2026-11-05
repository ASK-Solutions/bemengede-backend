<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\MarketingSubscription;
use App\Models\OtpCode;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
    }

    private function otp(string $phone): void
    {
        OtpCode::create(['phone' => $phone, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5)]);
    }

    public function test_new_accounts_require_adult_confirmation(): void
    {
        $this->otp('+251911000111');
        $this->postJson('/api/v1/auth/verify', ['phone' => '0911000111', 'code' => '123456'])->assertJsonValidationErrors('confirm_adult');
        $this->otp('+251911000111');
        $this->postJson('/api/v1/auth/verify', ['phone' => '0911000111', 'code' => '123456', 'confirm_adult' => true, 'accept_terms' => true])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_unsubscribe_link_works_without_login(): void
    {
        $sub = MarketingSubscription::create(['channel' => 'sms', 'address' => '+251911000222', 'token' => str_repeat('a', 48), 'subscribed_at' => now()]);
        $this->postJson('/api/v1/unsubscribe/'.$sub->token)->assertOk()->assertJsonPath('active', false);
        $this->assertNotNull($sub->fresh()->unsubscribed_at);
        $this->assertDatabaseHas('consents', ['type' => 'marketing_sms', 'granted' => false]);
    }

    public function test_user_can_export_their_data(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me/export')->assertOk()->assertJsonStructure(['account', 'consents', 'bookings']);
    }

    public function test_deletion_is_scheduled_then_erases_personal_data_but_keeps_amounts(): void
    {
        $user = User::factory()->create(['phone' => '+251911000333']);
        $trip = Trip::where('departure_at', '>', now()->addDays(2))->first();
        $booking = $this->postJson('/api/v1/bookings', [
            'trip_id' => $trip->id, 'contact_phone' => '0911000333',
            'passengers' => [['full_name' => 'Hana', 'seat_number' => 5, 'fare_category' => 'adult']],
            'accept_terms' => true, 'booker_is_adult' => true,
        ])->json('reference');

        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/me')->assertStatus(202);
        $this->travel(config('bemengede.privacy.deletion_cooling_off_hours') + 1)->hours();
        $this->artisan('privacy:process-requests')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $b = Booking::where('reference', $booking)->first();
        $this->assertNull($b->contact_phone);
        $this->assertSame('Deleted passenger', $b->passengers()->value('full_name'));
        $this->assertGreaterThan(0, $b->total);
    }

    public function test_deletion_is_blocked_while_a_paid_trip_is_upcoming(): void
    {
        $user = User::factory()->create(['phone' => '+251911000444']);
        $trip = Trip::where('departure_at', '>', now()->addDays(2))->first();
        $ref = $this->postJson('/api/v1/bookings', [
            'trip_id' => $trip->id, 'contact_phone' => '0911000444',
            'passengers' => [['full_name' => 'Hana', 'seat_number' => 6, 'fare_category' => 'adult']],
            'accept_terms' => true, 'booker_is_adult' => true,
        ])->json('reference');
        Booking::where('reference', $ref)->update(['status' => 'confirmed']);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/me')->assertJsonValidationErrors('account');
    }

    public function test_partners_list_hides_unsigned_operators(): void
    {
        $names = collect($this->getJson('/api/v1/partners')->json())->pluck('name');
        $this->assertSame(['Selam Bus Line S.C.'], $names->all()); // only the demo-signed operator
    }

    public function test_reviews_only_from_travellers_after_the_trip(): void
    {
        $user = User::factory()->create(['phone' => '+251911000555']);
        $trip = Trip::where('departure_at', '>', now()->addDays(2))->first();
        $ref = $this->postJson('/api/v1/bookings', [
            'trip_id' => $trip->id, 'contact_phone' => '0911000555',
            'passengers' => [['full_name' => 'Hana', 'seat_number' => 7, 'fare_category' => 'adult']],
            'accept_terms' => true, 'booker_is_adult' => true,
        ])->json('reference');
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/bookings/{$ref}/review", ['rating' => 5])->assertStatus(422);
    }

    public function test_legal_documents_are_served_with_version(): void
    {
        foreach (['privacy', 'terms', 'refund', 'cookies'] as $doc) {
            $this->getJson("/api/v1/legal/{$doc}")->assertOk()->assertJsonStructure(['version', 'effective', 'markdown']);
        }
    }
}
