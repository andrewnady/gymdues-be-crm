<?php

namespace websquids\Gymdirectory\Models;

use Winter\Storm\Database\Model;

/**
 * GymTeamMember
 *
 * Represents a user who has been invited by a gym owner to co-manage a gym
 * listing without going through the claim verification flow.
 *
 * Status lifecycle:
 *   pending  → invitation sent, awaiting acceptance
 *   accepted → user accepted via magic link and has active dashboard access
 *   revoked  → owner removed access
 */
class GymTeamMember extends Model
{
    use \Winter\Storm\Database\Traits\SoftDelete;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REVOKED  = 'revoked';

    protected $table = 'websquids_gymdirectory_gym_team_members';

    protected $fillable = [
        'gym_id',
        'invited_by_user_id',
        'user_id',
        'email',
        'name',
        'role',
        'status',
        'invited_at',
        'accepted_at',
    ];

    protected $dates = [
        'invited_at',
        'accepted_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    // =========================================================================
    // Relations
    // =========================================================================

    public $belongsTo = [
        'gym' => [\websquids\Gymdirectory\Models\Gym::class, 'key' => 'gym_id'],
    ];

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', self::STATUS_ACCEPTED);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACCEPTED]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function accept(int $userId): void
    {
        $this->user_id     = $userId;
        $this->status      = self::STATUS_ACCEPTED;
        $this->accepted_at = now();
        $this->save();
    }

    public function revoke(): void
    {
        $this->status = self::STATUS_REVOKED;
        $this->save();
    }
}
