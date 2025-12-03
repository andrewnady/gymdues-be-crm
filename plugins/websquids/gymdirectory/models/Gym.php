<?php

namespace websquids\Gymdirectory\Models;

use Model;

/**
 * Model
 */
class Gym extends Model {
    use \Winter\Storm\Database\Traits\Validation;

    use \Winter\Storm\Database\Traits\SoftDelete;

    protected $dates = ['deleted_at'];


    /**
     * @var string The database table used by the model.
     */
    public $table = 'websquids_gymdirectory_gyms';

    /**
     * @var array Validation rules
     */
    public $rules = [];

    public $hasMany = [
        'pricing' => [
            Pricing::class,
            'key' => 'gym_id'
        ],
        'hours' => [
            Hour::class,
            'key' => 'gym_id'
        ],
        'reviews' => [
            Review::class,
            'key' => 'gym_id'
        ],
        'faqs' => [
            Faq::class,
            'key' => 'gym_id'
        ],
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    public $attachOne = [
        'logo' => \System\Models\File::class,
    ];

    public $attachMany = [
        'gallery' => \System\Models\File::class,
    ];
}
