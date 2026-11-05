<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
    }

    private function trip(): Trip
    {
        return Trip::where('departure_at', '>', now()->addDays(3))->firstOrFail();
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'trip_id' => $this->trip()->id,
            'contact_phone' => '0911234567',
            'passengers' => [['full_name' => 'Hana Tesfaye', 'seat_number' => 10, 'fare_category' => 'adult']],
            'accept_terms' => true,
            'booker_is_adult' => true,
        ], $over);
    }

    public function test_search_price_already_includes_service_fee(): void
    {
        $trip = $this->trip()->load('route.origin', 'route.destination');
        $res = $this->getJson('/api/v1/trips/search?'.http_build_query([
            'from' => $trip->route->origin->city_id, 'to' => $trip->route->destination->city_id,
            'date' => $trip->departure_at->setTimezone('Africa/Addis_Ababa')->format('Y-m-d'),
        ]))->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $trip->id);
        $this->assertSame($trip->base_fare + config('bemengede.pricing.service_fee_per_seat'), $row['price_per_seat']);
        $this->assertTrue($res->json('prices_include_service_fee'));
    }

    public function test_checkout_total_equals_advertised_price(): void
    {
        $trip = $this->trip();
        $res = $this->postJson('/api/v1/bookings', $this->payload())->assertCreated();
        $this->assertSame($trip->base_fare + config('bemengede.pricing.service_fee_per_seat'), $res->json('price.total'));
    }

    public function test_terms_must_be_actively_accepted(): void
    {
        $this->postJson('/api/v1/bookings', $this->payload(['accept_terms' => false]))->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
        $p = $this->payload();
        unset($p['accept_terms']);
        $this->postJson('/api/v1/bookings', $p)->assertUnprocessable();
    }

    public function test_booker_must_confirm_they_are_an_adult(): void
    {
        $this->postJson('/api/v1/bookings', $this->payload(['booker_is_adult' => false]))->assertJsonValidationErrors('booker_is_adult');
    }

    public function test_children_can_travel_as_passengers_at_child_fare(): void
    {
        $trip = $this->trip();
        $res = $this->postJson('/api/v1/bookings', $this->payload(['passengers' => [
            ['full_name' => 'Parent', 'seat_number' => 1, 'fare_category' => 'adult'],
            ['full_name' => 'Child', 'seat_number' => 2, 'fare_category' => 'child'],
        ]]))->assertCreated();
        $this->assertSame((int) round($trip->base_fare / 2), $res->json('passengers.1.fare'));
    }

    public function test_marketing_is_off_unless_ticked(): void
    {
        $this->postJson('/api/v1/bookings', $this->payload())->assertCreated();
        $this->assertDatabaseCount('marketing_subscriptions', 0);
        $this->assertDatabaseHas('consents', ['type' => 'terms', 'granted' => true]);
    }

    public function test_a_seat_cannot_be_sold_twice(): void
    {
        $this->postJson('/api/v1/bookings', $this->payload())->assertCreated();
        $this->postJson('/api/v1/bookings', $this->payload(['contact_phone' => '0922000000']))
            ->assertUnprocessable()->assertJsonValidationErrors('seats');
    }

    public function test_only_needed_fields_are_stored(): void
    {
        $this->postJson('/api/v1/bookings', $this->payload([
            'passengers' => [['full_name' => 'Hana', 'seat_number' => 3, 'fare_category' => 'adult', 'gender' => 'F', 'id_number' => '123']],
        ]))->assertCreated();
        $this->assertSame(['id', 'booking_id', 'full_name', 'seat_number', 'fare_category', 'fare', 'qr_token', 'checked_in_at', 'boarded_at', 'created_at', 'updated_at'],
            array_keys(\App\Models\Passenger::first()->getAttributes()));
    }

    public function test_guest_lookup_needs_the_booking_phone(): void
    {
        $ref = $this->postJson('/api/v1/bookings', $this->payload())->json('reference');
        $this->getJson("/api/v1/bookings/{$ref}?phone=0999999999")->assertNotFound();
        $this->getJson("/api/v1/bookings/{$ref}?phone=0911234567")->assertOk();
    }

    public function test_mock_chapa_payment_confirms_only_after_server_check(): void
    {
        $ref = $this->postJson('/api/v1/bookings', $this->payload())->json('reference');
        $tx = $this->postJson("/api/v1/bookings/{$ref}/pay", ['method' => 'chapa', 'phone' => '0911234567'])->assertOk()->json('tx_ref');

        // Returning from the provider without paying does not confirm anything.
        $this->getJson("/api/v1/payments/{$tx}?phone=0911234567")->assertJsonPath('booking.status', 'pending_payment');

        $this->postJson("/api/v1/payments/{$tx}/mock-complete")->assertJsonPath('status', 'succeeded');
        $this->assertSame(Booking::CONFIRMED, Booking::where('reference', $ref)->value('status'));
    }

    public function test_transfer_auto_confirms_only_when_every_check_passes(): void
    {
        $ref = $this->postJson('/api/v1/bookings', $this->payload())->json('reference');
        $tx = $this->postJson("/api/v1/bookings/{$ref}/pay", ['method' => 'transfer', 'phone' => '0911234567'])->json('tx_ref');
        $this->postJson("/api/v1/payments/{$tx}/transfer", ['phone' => '0911234567', 'bank' => 'CBE', 'reference' => 'FT25316ABCD'])
            ->assertOk()->assertJsonPath('decision', 'auto_approved')->assertJsonPath('booking.status', 'confirmed');
    }

    public function test_underpaid_transfer_goes_to_manual_review_and_reference_cannot_be_reused(): void
    {
        $ref = $this->postJson('/api/v1/bookings', $this->payload())->json('reference');
        $tx = $this->postJson("/api/v1/bookings/{$ref}/pay", ['method' => 'transfer', 'phone' => '0911234567'])->json('tx_ref');
        $this->postJson("/api/v1/payments/{$tx}/transfer", ['phone' => '0911234567', 'bank' => 'CBE', 'reference' => 'FT25316SHORT'])
            ->assertJsonPath('decision', 'needs_review')->assertJsonPath('booking.status', 'awaiting_verification');

        $ref2 = $this->postJson('/api/v1/bookings', $this->payload(['passengers' => [['full_name' => 'X', 'seat_number' => 20, 'fare_category' => 'adult']]]))->json('reference');
        $tx2 = $this->postJson("/api/v1/bookings/{$ref2}/pay", ['method' => 'transfer', 'phone' => '0911234567'])->json('tx_ref');
        $this->postJson("/api/v1/payments/{$tx2}/transfer", ['phone' => '0911234567', 'bank' => 'CBE', 'reference' => 'FT25316SHORT'])
            ->assertJsonValidationErrors('reference');
    }
}
