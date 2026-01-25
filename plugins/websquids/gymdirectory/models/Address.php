<?php namespace websquids\Gymdirectory\Models;

use Model;

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
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = ['reviews_per_score'];
}

