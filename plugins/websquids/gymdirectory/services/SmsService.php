<?php namespace websquids\Gymdirectory\Services;

use Illuminate\Support\Facades\Log;

/**
 * SmsService
 *
 * Sends SMS messages via Twilio when TWILIO_* env vars are configured.
 * Falls back to logging the message (useful for local dev / staging).
 *
 * Required .env keys for Twilio:
 *   TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 *   TWILIO_AUTH_TOKEN=your_auth_token
 *   TWILIO_FROM_NUMBER=+15551234567
 *   TWILIO_TO_NUMBER=+15551234567
 */
class SmsService
{
    /** @var string|null */
    protected ?string $accountSid;
    /** @var string|null */
    protected ?string $authToken;
    /** @var string|null */
    protected ?string $fromNumber;
    /** @var string|null */
    protected ?string $toNumber;

    public function __construct()
    {
        $this->accountSid = env('TWILIO_ACCOUNT_SID');
        $this->authToken  = env('TWILIO_AUTH_TOKEN');
        $this->fromNumber = env('TWILIO_FROM_NUMBER');
        $this->toNumber = env('TWILIO_TO_NUMBER');
    }

    /**
     * Send an SMS message.
     *
     * The recipient is always taken from TWILIO_TO_NUMBER env var.
     * The $to parameter is accepted for interface compatibility but ignored.
     *
     * @param  string $to      Ignored — recipient is set via TWILIO_TO_NUMBER
     * @param  string $message SMS body text
     * @return bool   True on success (or logged), false on hard failure
     */
    public function send(string $to, string $message): bool
    {
        $recipient = $this->toNumber ?: $to;

        if ($this->isTwilioConfigured()) {
            return $this->sendViaTwilio($recipient, $message);
        }

        // Fallback: log the message so it can be checked during development
        Log::info('SmsService [no-provider]: To=' . $recipient . ' | Message=' . $message);
        return true;
    }

    protected function isTwilioConfigured(): bool
    {
        return !empty($this->accountSid)
            && !empty($this->authToken)
            && !empty($this->fromNumber)
            && $this->accountSid !== 'null'
            && $this->authToken !== 'null';
    }

    protected function sendViaTwilio(string $to, string $message): bool
    {
        // Twilio REST API endpoint
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $this->accountSid . '/Messages.json';

        $data = [
            'From' => $this->fromNumber,
            'To'   => $to,
            'Body' => $message,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->accountSid . ':' . $this->authToken,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response   = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::error('SmsService@sendViaTwilio cURL error: ' . $curlError);
            return false;
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            Log::error('SmsService@sendViaTwilio HTTP ' . $httpStatus . ': ' . $response);
            return false;
        }

        return true;
    }
}
