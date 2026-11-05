<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\SeatHold;
use App\Services\BookingService;
use Illuminate\Console\Command;

class ExpireBookings extends Command
{
    protected $signature = 'bookings:expire';

    protected $description = 'Expire unpaid bookings and free their seats';

    public function handle(BookingService $service): int
    {
        Booking::where('status', Booking::PENDING)->where('expires_at', '<', now())
            ->each(fn ($b) => $service->expire($b));
        SeatHold::whereNull('booking_id')->where('expires_at', '<', now())->delete();

        return self::SUCCESS;
    }
}
