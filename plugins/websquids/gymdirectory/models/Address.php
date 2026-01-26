<?php namespace websquids\Gymdirectory\Models;

use Model;
use websquids\Gymdirectory\Models\Contact;
use websquids\Gymdirectory\Models\Review;
use websquids\Gymdirectory\Models\Hour;
use websquids\Gymdirectory\Models\Pricing;

/**
 * Address Model
 */
class Address extends Model
{
    use \Winter\Storm\Database\Traits\Validation;
    use \Winter\Storm\Database\Traits\SoftDelete;

    protected $dates = ['deleted_at'];

    /**
     * @var string The database table used by the model.
     */
    public $table = 'websquids_gymdirectory_addresses';

    /**
     * @var array Validation rules
     */
    public $rules = [];

    public $belongsTo = [
        'gym' => [
            Gym::class,
            'key' => 'gym_id'
        ],
    ];

    public $hasMany = [
        'contacts' => [
            Contact::class,
            'key' => 'address_id'
        ],
        'reviews' => [
            Review::class,
            'key' => 'address_id'
        ],
        'hours' => [
            Hour::class,
            'key' => 'address_id'
        ],
        'pricing' => [
            Pricing::class,
            'key' => 'address_id'
        ],
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = ['reviews_per_score'];

    /**
     * Scope to get primary address
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }
}

