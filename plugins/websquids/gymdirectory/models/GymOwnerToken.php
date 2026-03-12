<?php namespace websquids\Gymdirectory\Models;

use Model;

/**
 * GymOwnerToken Model
 *
 * Stores three kinds of tokens:
 *   magic          – One-time login link included in the approval email.
 *   session        – Bearer token used by the frontend for authenticated API calls.
 *   password_reset – One-time link emailed when the owner uses "Forgot Password".
 */
class GymOwnerToken extends Model
{
    public $table = 'websquids_gymdirectory_gym_owner_tokens';

    // No updated_at column in this table
    public $timestamps = false;

    protected $dates = ['expires_at', 'used_at', 'created_at'];

    protected $fillable = [
        'user_id',
        'token',
        'type',
        'expires_at',
        'used_at',
        'created_at',
    ];

    const TYPE_MAGIC          = 'magic';
    const TYPE_SESSION        = 'session';
    const TYPE_PASSWORD_RESET = 'password_reset';

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeValid($query)
    {
        return $query->whereNull('used_at')
                     ->where('expires_at', '>', now());
    }

    public function scopeMagic($query)
    {
        return $query->where('type', self::TYPE_MAGIC);
    }

    public function scopeSession($query)
    {
        return $query->where('type', self::TYPE_SESSION);
    }

    public function scopePasswordReset($query)
    {
        return $query->where('type', self::TYPE_PASSWORD_RESET);
    }
}
