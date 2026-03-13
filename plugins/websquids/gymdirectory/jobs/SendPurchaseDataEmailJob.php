<?php

namespace Websquids\Gymdirectory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Websquids\Gymdirectory\Controllers\Api\GymsdataController;

/**
 * Runs the purchase data export + email in the background so the webhook/resend endpoint returns immediately.
 * Requires QUEUE_CONNECTION=database (or redis) and a running queue worker: php artisan queue:work
 */
class SendPurchaseDataEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1200;

    public function __construct(
        private int $downloadId
    ) {}

    public function handle(): void
    {
        $controller = app(GymsdataController::class);
        $controller->sendPurchaseDataToEmail($this->downloadId);
    }
}
