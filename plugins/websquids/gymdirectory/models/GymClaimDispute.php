<?php namespace websquids\Gymdirectory\Models;

use Model;

/**
 * GymClaimDispute Model
 * Represents a dispute filed by someone who believes an existing claim on a gym is fraudulent.
 *
 * Status flow:
 *   pending        → Dispute created, awaiting document upload
 *   under_review   → Document uploaded, admin reviewing (≤ 48 h)
 *   approved       → Admin approved; original claim revoked, disputer granted ownership
 *   rejected       → Admin rejected the dispute
 */
class GymClaimDispute extends Model
{
    use \Winter\Storm\Database\Traits\SoftDelete;

    public $table = 'websquids_gymdirectory_gym_claim_disputes';

    protected $dates = ['deleted_at', 'reviewed_at'];

    protected $fillable = [
        'gym_id',
        'existing_claim_id',
        'full_name',
        'job_title',
        'business_email',
        'phone_number',
        'document_path',
        'status',
        'admin_notes',
        'ip_address',
        'reviewed_at',
    ];

    // Statuses
    const STATUS_PENDING      = 'pending';
    const STATUS_UNDER_REVIEW = 'under_review';
    const STATUS_APPROVED     = 'approved';
    const STATUS_REJECTED     = 'rejected';

    public $belongsTo = [
        'gym' => [
            Gym::class,
            'key' => 'gym_id',
        ],
        'existingClaim' => [
            GymClaimRequest::class,
            'key' => 'existing_claim_id',
        ],
    ];
}
