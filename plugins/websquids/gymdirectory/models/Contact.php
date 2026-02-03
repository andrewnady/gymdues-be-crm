<?php namespace websquids\Gymdirectory\Models;

use Model;
use Winter\Storm\Exception\ValidationException;

/**
 * Contact Model
 */
class Contact extends Model
{
    use \Winter\Storm\Database\Traits\Validation;
    use \Winter\Storm\Database\Traits\SoftDelete;

    protected $dates = ['deleted_at'];

    /**
     * @var string The database table used by the model.
     */
    public $table = 'websquids_gymdirectory_contacts';

    /**
     * @var array Attributes to hide from array/JSON (avoids circular ref in API)
     */
    protected $hidden = ['gym', 'address'];

    /**
     * @var array Validation rules
     */
    public $rules = [
        'type' => 'required|in:business_website,business_phone,email,facebook,twitter,instagram,youtube,linkedin,contact_page',
        'value' => 'nullable|string',
    ];

    /**
     * Custom validation to ensure gym_id or address_id is set (mutually exclusive)
     */
    public function beforeValidate()
    {
        if (empty($this->gym_id) && empty($this->address_id)) {
            throw new ValidationException([
                'gym_id' => 'Either gym_id or address_id must be set.',
                'address_id' => 'Either gym_id or address_id must be set.'
            ]);
        }

        if (!empty($this->gym_id) && !empty($this->address_id)) {
            throw new ValidationException([
                'gym_id' => 'gym_id and address_id cannot both be set.',
                'address_id' => 'gym_id and address_id cannot both be set.'
            ]);
        }
    }

    public $belongsTo = [
        'gym' => [
            Gym::class,
            'key' => 'gym_id'
        ],
        'address' => [
            Address::class,
            'key' => 'address_id'
        ],
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];
}

