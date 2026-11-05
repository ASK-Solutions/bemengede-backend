<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Operator;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** Only travellers with a paid booking whose bus has arrived can review — one review per booking. */
    public function store(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $u = $request->user();
        $b = Booking::where('reference', strtoupper($reference))->firstOrFail();
        abort_unless($u->owns($b), 403);
        abort_unless($b->isPaid() && $b->trip->arrival_at->isPast(), 422, 'You can review this trip after you have travelled.');
        abort_if($b->review()->exists(), 422, 'You have already reviewed this trip.');

        $review = Review::create(['booking_id' => $b->id, 'operator_id' => $b->operator_id] + $data);

        return response()->json($review, 201);
    }

    public function index(string $slug): JsonResponse
    {
        $op = Operator::publicPartner()->where('slug', $slug)->firstOrFail();

        return response()->json(Review::where('operator_id', $op->id)->where('published', true)->latest()
            ->paginate(20, ['rating', 'comment', 'operator_reply', 'created_at'])
            ->through(fn ($r) => $r->toArray() + ['verified_trip' => true]));
    }
}
