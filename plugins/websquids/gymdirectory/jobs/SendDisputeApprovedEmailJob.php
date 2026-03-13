<?php

namespace Websquids\Gymdirectory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a dispute-approved email with dashboard URL in the background.
 * Requires QUEUE_CONNECTION=database (or redis) and a running queue worker: php artisan queue:work
 */
class SendDisputeApprovedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        private string $toEmail,
        private string $fullName,
        private string $gymName,
        private string $dashboardUrl
    ) {}

    public function handle(): void
    {
        try {
            $toEmail      = $this->toEmail;
            $fullName     = $this->fullName;
            $gymName      = $this->gymName;
            $dashboardUrl = $this->dashboardUrl;

            Mail::send(
                'websquids.gymdirectory::mail.dispute_approved',
                compact('fullName', 'gymName', 'dashboardUrl'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Dispute Approved – You\'ve Claimed ' . $gymName . ' on GymDues');
                }
            );
        } catch (\Exception $e) {
            Log::error('SendDisputeApprovedEmailJob: ' . $e->getMessage());
            throw $e;
        }
    }
}
