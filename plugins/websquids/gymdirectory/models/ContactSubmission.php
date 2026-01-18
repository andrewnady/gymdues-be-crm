<?php namespace websquids\Gymdirectory\Models;

use Model;

/**
 * ContactSubmission Model
 */
class ContactSubmission extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    /**
     * @var string The database table used by the model.
     */
    public $table = 'websquids_gymdirectory_contact_submissions';

    /**
     * @var array Validation rules
     */
    public $rules = [
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'subject' => 'required|string|max:255',
        'message' => 'required|string',
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    /**
     * @var array Fillable attributes
     */
    public $fillable = [
        'name',
        'email',
        'subject',
        'message',
        'read_at',
    ];

    /**
     * @var array Dates
     */
    public $dates = ['read_at', 'created_at', 'updated_at'];

    /**
     * Check if submission has been read
     */
    public function getIsReadAttribute()
    {
        return !is_null($this->read_at);
    }

    /**
     * Mark submission as read
     */
    public function markAsRead()
    {
        if (!$this->is_read) {
            $this->read_at = now();
            $this->save();
        }
    }
}

