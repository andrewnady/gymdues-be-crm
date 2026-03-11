<?php namespace websquids\Gymdirectory\Models;

use Model;

/**
 * GymClaimRequest Model
 * Represents a gym ownership claim submitted by a business owner.
 *
 * Status flow:
 *   pending        → Claim created, no code sent yet
 *   code_sent      → Verification code sent (email or SMS)
 *   document_uploaded → Supporting doc uploaded (awaiting manual review)
 *   approved       → Verified and approved
 *   rejected       → Rejected by admin or expired
 */
class GymClaimRequest extends Model
{
    use \Winter\Storm\Database\Traits\SoftDelete;

    public $table = 'websquids_gymdirectory_gym_claim_requests';

    protected $dates = ['deleted_at', 'verification_code_expires_at', 'verified_at'];

    protected $fillable = [
        'gym_id',
        'full_name',
        'job_title',
        'business_email',
        'phone_number',
        'verification_method',
        'verification_code',
        'verification_code_expires_at',
        'document_path',
        'status',
        'ip_address',
        'verified_at',
    ];

    // Verification methods
    const METHOD_EMAIL_DOMAIN = 'email_domain';
    const METHOD_PHONE_SMS    = 'phone_sms';
    const METHOD_DOCUMENT     = 'document';

    // Statuses
    const STATUS_PENDING           = 'pending';
    const STATUS_CODE_SENT         = 'code_sent';
    const STATUS_DOCUMENT_UPLOADED = 'document_uploaded';
    const STATUS_APPROVED          = 'approved';
    const STATUS_REJECTED          = 'rejected';

    public $belongsTo = [
        'gym' => [
            Gym::class,
            'key' => 'gym_id',
        ],
    ];

    /**
     * Generate a fresh 6-digit OTP and set expiry (10 minutes).
     * Saves the model.
     */
    public function generateAndSaveCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->verification_code            = $code;
        $this->verification_code_expires_at = now()->addMinutes(10);
        $this->status                       = self::STATUS_CODE_SENT;
        $this->save();

        return $code;
    }

    /**
     * Check whether the given code matches and has not expired.
     */
    public function isCodeValid(string $code): bool
    {
        if ($this->verification_code !== $code) {
            return false;
        }

        if (!$this->verification_code_expires_at || now()->isAfter($this->verification_code_expires_at)) {
            return false;
        }

        return true;
    }

    /**
     * Mark claim as approved.
     */
    public function approve(): void
    {
        $this->status      = self::STATUS_APPROVED;
        $this->verified_at = now();
        $this->verification_code            = null;
        $this->verification_code_expires_at = null;
        $this->save();
    }
}
