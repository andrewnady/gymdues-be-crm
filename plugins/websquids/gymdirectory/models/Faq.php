<?php

namespace websquids\Gymdirectory\Models;

use Model;

/**
 * Model
 */
class Faq extends Model {
    use \Winter\Storm\Database\Traits\Validation;

    use \Winter\Storm\Database\Traits\SoftDelete;

    protected $dates = ['deleted_at'];


    /**
     * @var string The database table used by the model.
     */
    public $table = 'websquids_gymdirectory_faqs';

    /**
     * @var array Validation rules
     */
    public $rules = [];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    public function getCategoryOptions() {
        return [
            'reviews_brand' => 'Reviews & Brand',
            'fitness_classes' => 'Fitness & Classes',
            'facilities_amenities' => 'Facilities & Amenities',
            'membership_pricing' => 'Membership & Pricing',
            'family_corporate_community' => 'Family, Corporate & Community',
            'digital_online' => 'Digital & Online',
        ];
    }
}
