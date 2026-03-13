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
 * Notifies a claimant that their gym ownership claim has been rejected by an admin.
 * Runs in the background so the admin save action returns immediately.
 * Requires QUEUE_CONNECTION=database (or redis) and a running queue worker: php artisan queue:work
 */
class SendClaimRejectedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        private string $toEmail,
        private string $fullName,
        private string $gymName
    ) {}

    public function handle(): void
    {
        try {
            $toEmail  = $this->toEmail;
            $fullName = $this->fullName;
            $gymName  = $this->gymName;

            Mail::send(
                'websquids.gymdirectory::mail.claim_rejected',
                compact('fullName', 'gymName'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Update on Your Claim for ' . $gymName . ' – GymDues');
                }
            );

            Log::info("SendClaimRejectedEmailJob: rejection email sent to {$toEmail} for gym {$gymName}");
        } catch (\Exception $e) {
            Log::error('SendClaimRejectedEmailJob: ' . $e->getMessage());
            throw $e;
        }
    }
}
