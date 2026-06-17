<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Mail\Events\MessageSent;

class RecordGrowthReportSent
{
    public function handle(MessageSent $event): void
    {
        $headers = $event->message->getHeaders();
        if (! $headers->has('X-EBQ-Growth-Report-User-Id')) {
            return;
        }

        $header = $headers->get('X-EBQ-Growth-Report-User-Id');
        $userId = trim($header->getBodyAsString());

        if ($userId === '') {
            return;
        }

        User::whereKey($userId)->update(['last_growth_report_sent_at' => now()]);
    }
}
