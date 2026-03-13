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
 * Notifies the original claim owner that their claim has been revoked due to a dispute.
 * Runs in the background so the admin approve endpoint returns immediately.
 * Requires QUEUE_CONNECTION=database (or redis) and a running queue worker: php artisan queue:work
 */
class SendClaimRevokedEmailJob implements ShouldQueue
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
                'websquids.gymdirectory::mail.claim_revoked',
                compact('fullName', 'gymName'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Important: Your Claim for ' . $gymName . ' Has Been Revoked – GymDues');
                }
            );
        } catch (\Exception $e) {
            Log::error('SendClaimRevokedEmailJob: ' . $e->getMessage());
            throw $e;
        }
    }
}
