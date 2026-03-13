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
 * Sends a 6-digit OTP verification email for a gym claim in the background.
 * Requires QUEUE_CONNECTION=database (or redis) and a running queue worker: php artisan queue:work
 */
class SendClaimVerificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        private string $toEmail,
        private string $fullName,
        private string $gymName,
        private string $code
    ) {}

    public function handle(): void
    {
        try {
            $toEmail  = $this->toEmail;
            $fullName = $this->fullName;
            $gymName  = $this->gymName;
            $code     = $this->code;

            Mail::send(
                'websquids.gymdirectory::mail.claim_verification',
                compact('code', 'fullName', 'gymName'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Verify Your Claim for ' . $gymName . ' on GymDues');
                }
            );

            Log::info('VERIFICATION CODE VIA EMAIL ON ' . $toEmail . ': ' . $code);
        } catch (\Exception $e) {
            Log::error('SendClaimVerificationEmailJob: ' . $e->getMessage());
            throw $e;
        }
    }
}
