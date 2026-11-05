<?php

namespace App\Console\Commands;

use App\Models\PrivacyRequest;
use App\Services\PrivacyService;
use Illuminate\Console\Command;

class ProcessPrivacyRequests extends Command
{
    protected $signature = 'privacy:process-requests';

    protected $description = 'Carry out account deletions whose cooling-off period has ended';

    public function handle(PrivacyService $privacy): int
    {
        PrivacyRequest::with('user')->where('type', 'delete')->where('status', 'scheduled')
            ->where('run_after', '<=', now())->each(function (PrivacyRequest $r) use ($privacy) {
                if ($r->user && $privacy->blockingBookings($r->user)->isEmpty()) {
                    $privacy->erase($r->user);
                }
                $r->update(['status' => 'completed', 'completed_at' => now(), 'user_id' => null]);
            });

        return self::SUCCESS;
    }
}
