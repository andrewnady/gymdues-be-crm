<?php

namespace websquids\Gymdirectory\Models;

use Model;
use websquids\Gymdirectory\Models\Address;

/**
 * Model
 */
class Hour extends Model {
    use \Winter\Storm\Database\Traits\Validation;

    use \Winter\Storm\Database\Traits\SoftDelete;

    protected $dates = ['deleted_at'];


    /**
     * @var string The database table used by the model.
     */
    public $table = 'websquids_gymdirectory_hours';

    /**
     * @var array Validation rules
     */
    public $rules = [];

    /**
     * @var array Attributes to hide from array/JSON (avoids circular ref in API)
     */
    protected $hidden = ['address'];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    public function getDayOptions() {
        return [
            'monday'    => 'Monday',
            'tuesday'   => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday'  => 'Thursday',
            'friday'    => 'Friday',
            'saturday'  => 'Saturday',
            'sunday'    => 'Sunday',
        ];
    }

    public $belongsTo = [
        'address' => [
            Address::class,
            'key' => 'address_id'
        ],
    ];

    /**
     * Before save hook to ensure address_id is set correctly
     * If address_id matches a gym_id (incorrect), find the primary address for that gym
     */
    public function beforeSave()
    {
        // If address_id is set and it matches a gym_id, we need to correct it
        if ($this->address_id && $this->isDirty('address_id')) {
            // Check if this address_id actually belongs to a gym (not an address)
            $gym = \websquids\Gymdirectory\Models\Gym::find($this->address_id);
            if ($gym) {
                // This is a gym_id, not an address_id - get the primary address instead
                $primaryAddress = $gym->getPrimaryAddress();
                if ($primaryAddress) {
                    $this->address_id = $primaryAddress->id;
                }
            }
        }
    }
}
