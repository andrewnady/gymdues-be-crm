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
 * Sends a dispute-received confirmation email in the background.
 * Requires QUEUE_CONNECTION=database (or redis) and a running queue worker: php artisan queue:work
 */
class SendDisputeReceivedEmailJob implements ShouldQueue
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
                'websquids.gymdirectory::mail.dispute_received',
                compact('fullName', 'gymName'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Dispute Received for ' . $gymName . ' – GymDues');
                }
            );
        } catch (\Exception $e) {
            Log::error('SendDisputeReceivedEmailJob: ' . $e->getMessage());
            throw $e;
        }
    }
}
