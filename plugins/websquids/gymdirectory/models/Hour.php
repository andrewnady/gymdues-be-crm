<?php

namespace websquids\Gymdirectory\Models;

use Model;

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
        'gym' => [
            Gym::class,
            'key' => 'gym_id'
        ],
    ];
}
