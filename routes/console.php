<?php

use Illuminate\Support\Facades\Schedule;

// Release expired seat holds and expire unpaid bookings every minute.
Schedule::command('bookings:expire')->everyMinute()->withoutOverlapping();
// Re-check payments whose webhook never arrived.
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();
// Carry out data-deletion requests that passed their cooling-off period.
Schedule::command('privacy:process-requests')->hourly()->withoutOverlapping();
// Remove expired OTP codes and old audit noise.
Schedule::command('model:prune')->daily();
