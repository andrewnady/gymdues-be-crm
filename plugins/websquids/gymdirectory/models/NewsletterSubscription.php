<?php namespace websquids\Gymdirectory\Models;

use Model;

/**
 * NewsletterSubscription Model
 */
class NewsletterSubscription extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    /**
     * @var string The database table used by the model.
     */
    public $table = 'websquids_gymdirectory_newsletter_subscriptions';

    /**
     * @var array Validation rules
     */
    public $rules = [
        'email' => 'required|email|max:255|unique:websquids_gymdirectory_newsletter_subscriptions',
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    /**
     * @var array Fillable attributes
     */
    public $fillable = [
        'email',
        'unsubscribed_at',
    ];

    /**
     * @var array Dates
     */
    public $dates = ['unsubscribed_at', 'created_at', 'updated_at'];

    /**
     * Check if subscription is active
     */
    public function getIsActiveAttribute()
    {
        return is_null($this->unsubscribed_at);
    }

    /**
     * Unsubscribe
     */
    public function unsubscribe()
    {
        if ($this->is_active) {
            $this->unsubscribed_at = now();
            $this->save();
        }
    }

    /**
     * Resubscribe
     */
    public function resubscribe()
    {
        if (!$this->is_active) {
            $this->unsubscribed_at = null;
            $this->save();
        }
    }
}

