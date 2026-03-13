<?php

namespace Websquids\Gymdirectory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Websquids\Gymdirectory\Services\SmsService;

/**
 * Sends a 6-digit OTP via SMS for gym claim phone verification in the background.
 * Requires QUEUE_CONNECTION=database (or redis) and a running queue worker: php artisan queue:work
 */
class SendClaimPhoneSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        private string $phone,
        private string $gymName,
        private string $code
    ) {}

    public function handle(): void
    {
        try {
            $sms = new SmsService();
            $sms->send(
                $this->phone,
                'Your GymDues verification code for ' . $this->gymName . ': ' . $this->code . '. Valid for 10 minutes.'
            );

            Log::info('VERIFICATION CODE VIA SMS : ' . $this->code);
        } catch (\Exception $e) {
            Log::error('SendClaimPhoneSmsJob: ' . $e->getMessage());
            throw $e;
        }
    }
}
