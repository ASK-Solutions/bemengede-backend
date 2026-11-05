<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Operator;
use App\Models\Settlement;
use App\Models\StaffInvitation;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        Mail::fake();
    }

    private function owner(): User
    {
        return User::where('email', 'selam@bemengede.test')->first();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@bemengede.test')->first();
    }

    private function counterSale(Trip $trip, int $seat = 12): array
    {
        return $this->postJson('/api/v1/operator/counter-sales', [
            'trip_id' => $trip->id, 'contact_phone' => '0911555000',
            'passengers' => [['full_name' => 'Walk-in Passenger', 'seat_number' => $seat, 'fare_category' => 'adult']],
            'terms_shown' => true, 'booker_is_adult' => true,
        ])->assertCreated()->json();
    }

    public function test_passengers_cannot_open_the_portal(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/operator/dashboard')->assertForbidden();
        $this->getJson('/api/v1/admin/overview')->assertForbidden();
    }

    public function test_staff_only_see_their_own_company(): void
    {
        $other = Operator::where('slug', 'abay-bus')->first();
        $agent = User::factory()->create();
        $agent->forceFill(['role' => 'operator_agent', 'operator_id' => $other->id])->save();
        $selamTrip = Trip::first();

        Sanctum::actingAs($agent);
        $this->getJson("/api/v1/operator/trips/{$selamTrip->id}/manifest")->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/v1/operator/trips')->json());
    }

    public function test_agents_cannot_create_trips_but_owners_can(): void
    {
        $selam = $this->owner()->operator;
        $agent = User::factory()->create();
        $agent->forceFill(['role' => 'operator_agent', 'operator_id' => $selam->id])->save();
        $payload = ['route_id' => $selam->routes()->value('id'), 'bus_id' => $selam->buses()->value('id'),
            'departure_local' => now('Africa/Addis_Ababa')->addDays(20)->format('Y-m-d\T06:00'), 'base_fare_etb' => 1800, 'repeat_days' => 3];

        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/operator/trips', $payload)->assertForbidden();
        Sanctum::actingAs($this->owner());
        $this->postJson('/api/v1/operator/trips', $payload)->assertCreated()->assertJsonPath('created', 3);
    }

    public function test_counter_sale_is_confirmed_and_appears_on_manifest(): void
    {
        Sanctum::actingAs($this->owner());
        $trip = Trip::where('departure_at', '>', now()->addDay())->first();
        $b = $this->counterSale($trip);
        $this->assertSame('confirmed', $b['status']);
        $this->getJson("/api/v1/operator/trips/{$trip->id}/manifest")->assertJsonPath('passengers.0.name', 'Walk-in Passenger');
    }

    public function test_operator_cancelling_a_trip_refunds_everything_including_the_fee(): void
    {
        Sanctum::actingAs($this->owner());
        $trip = Trip::where('departure_at', '>', now()->addDay())->first();
        $ref = $this->counterSale($trip)['reference'];
        $this->postJson("/api/v1/operator/trips/{$trip->id}/cancel", ['reason' => 'Bus breakdown'])->assertOk();
        $b = Booking::where('reference', $ref)->first();
        $this->assertSame('cancelled', $b->status);
        $this->assertSame($b->total, $b->refund_amount);
    }

    public function test_admin_cannot_activate_an_operator_with_missing_steps(): void
    {
        Sanctum::actingAs($this->admin());
        $abay = Operator::where('slug', 'abay-bus')->first();
        $this->postJson("/api/v1/admin/operators/{$abay->id}/status", ['status' => 'active'])->assertStatus(422);
    }

    public function test_settlements_split_commission_and_fees(): void
    {
        Sanctum::actingAs($this->owner());
        $trip = Trip::where('departure_at', '>', now()->addDay())->first();
        $this->counterSale($trip);

        Sanctum::actingAs($this->admin());
        $today = now('Africa/Addis_Ababa');
        // Settlements are for past days only; travel forward one day.
        $this->travel(1)->days();
        $this->postJson('/api/v1/admin/settlements', ['from' => $today->toDateString(), 'to' => $today->toDateString()])->assertOk();
        $s = Settlement::first();
        $this->assertSame('cash', $s->channel);
        $this->assertSame('invoiced', $s->status);
        $this->assertSame(0, (int) $s->commission); // counter sales are commission-free by default
        $this->assertSame((int) ($trip->base_fare), (int) $s->net_to_operator);
    }

    public function test_invited_staff_set_a_password_and_get_access(): void
    {
        Sanctum::actingAs($this->owner());
        $this->postJson('/api/v1/operator/staff', ['name' => 'Agent A', 'email' => 'agent@example.com', 'phone' => '0911777888', 'role' => 'operator_agent'])->assertCreated();
        $token = StaffInvitation::first()->token;

        $this->postJson("/api/v1/auth/invitations/{$token}", ['password' => 'a-long-password', 'password_confirmation' => 'a-long-password', 'confirm_adult' => true])
            ->assertOk()->assertJsonPath('user.role', 'operator_agent');
        $this->postJson("/api/v1/auth/invitations/{$token}", ['password' => 'x', 'password_confirmation' => 'x', 'confirm_adult' => true])->assertStatus(422);
    }
}
