<?php

namespace App\Console\Commands;

use App\Models\Bid;
use App\Models\Offer;
use App\Notifications\PlatformNotification;
use Illuminate\Console\Command;

/** Reminds investors who have not uploaded evidence of payment, at 12, 24 and 36 hours (legacy schedule). */
class SendPaymentReminders extends Command
{
    private const HOURS = [12, 24, 36];

    protected $signature = 'payments:remind';

    protected $description = 'Remind investors with active bids that have no evidence of payment';

    public function handle(): int
    {
        $sent = 0;

        $bids = Bid::with('user', 'offer')
            ->where('status', Bid::ACTIVE)
            ->where('payment_status', 'pending')
            ->whereNull('payment_evidence_path')
            ->where('payment_reminders_sent', '<', count(self::HOURS))
            ->whereHas('offer', function ($q) {
                $q->where('status', Offer::LIVE);
            })
            ->get();

        foreach ($bids as $bid) {
            if ($bid->created_at->diffInHours(now()) < self::HOURS[$bid->payment_reminders_sent]) {
                continue;
            }

            try {
                $bid->user->notify(new PlatformNotification(
                'Payment reminder for your bid',
                "Your bid {$bid->reference} on {$bid->offer->issuer_name} is still awaiting evidence of payment. Upload it to keep your application complete.",
                frontendUrl('/investor/bids/' . $bid->id)
                ));
            } catch (\Throwable $e) {
                // Not counted as sent, so the next run tries again.
                report($e);
                continue;
            }
            $bid->forceFill(['payment_reminders_sent' => $bid->payment_reminders_sent + 1])->save();
            $sent++;
        }

        $this->info("Reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
