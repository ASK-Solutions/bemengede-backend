<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Trip;
use App\Services\BookingService;
use App\Support\Phone;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private BookingService $service) {}

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $trip = Trip::with('operator', 'bus')->findOrFail($request->integer('trip_id'));
        $booking = $this->service->create($trip, $request->validated(), $request->user('sanctum'));

        return response()->json(Present::booking($booking), 201);
    }

    /** Look up a booking with its reference AND the phone number used to book (guests). */
    public function show(Request $request, string $reference): JsonResponse
    {
        return response()->json(Present::booking($this->find($request, $reference)));
    }

    public function cancellationQuote(Request $request, string $reference): JsonResponse
    {
        return response()->json($this->service->cancellationQuote($this->find($request, $reference)));
    }

    /** Cancelling needs a signed-in user (phone verified by OTP) who owns the booking. */
    public function cancel(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();
        $booking = Booking::where('reference', strtoupper($reference))->firstOrFail();
        abort_unless($booking->user_id === $user->id || $booking->contact_phone === $user->phone, 403);

        return response()->json($this->service->cancel($booking));
    }

    public function mine(Request $request): JsonResponse
    {
        $u = $request->user();
        $bookings = Booking::where(fn ($q) => $q->where('user_id', $u->id)->orWhere('contact_phone', $u->phone))
            ->latest()->limit(50)->get()->map(fn ($b) => Present::booking($b));

        return response()->json($bookings);
    }

    private function find(Request $request, string $reference): Booking
    {
        $booking = Booking::where('reference', strtoupper($reference))->firstOrFail();
        $user = $request->user('sanctum');
        $owner = $user && ($booking->user_id === $user->id || $booking->contact_phone === $user->phone);
        $phoneMatches = Phone::normalize($request->query('phone')) === $booking->contact_phone && $booking->contact_phone !== null;
        abort_unless($owner || $phoneMatches, 404);

        return $booking;
    }
}
