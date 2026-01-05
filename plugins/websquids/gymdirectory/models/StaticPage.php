<?php

namespace websquids\Gymdirectory\Models;

use Model;

/**
 * StaticPage Model
 */
class StaticPage extends Model
{
    use \Winter\Storm\Database\Traits\Validation;
    use \Winter\Storm\Database\Traits\Sluggable;

    protected $slugs = [
        'slug' => 'title',
    ];

    /**
     * @var string The database table used by the model.
     */
    public $table = 'websquids_gymdirectory_static_pages';

    /**
     * @var array Validation rules
     */
    public $rules = [
        'title' => 'required|string|max:255',
        'slug' => 'required|string',
        'content' => 'required|string',
        'meta_title' => 'nullable|string|max:255',
        'meta_description' => 'nullable|string|max:500',
    ];

    /**
     * Before validation - set unique rule dynamically
     */
    public function beforeValidate()
    {
        // Set unique validation rule for slug
        if ($this->exists) {
            // Updating existing record - exclude current ID
            $this->rules['slug'] = 'required|string|unique:websquids_gymdirectory_static_pages,slug,' . $this->id;
        } else {
            // Creating new record - must be unique
            $this->rules['slug'] = 'required|string|unique:websquids_gymdirectory_static_pages,slug';
        }
    }

    /**
     * @var array Fillable fields
     */
    public $fillable = [
        'title',
        'slug',
        'content',
        'meta_title',
        'meta_description',
        'is_published',
    ];

    /**
     * @var array Dates
     */
    protected $dates = ['created_at', 'updated_at'];

    /**
     * @var array Casts
     */
    protected $casts = [
        'is_published' => 'boolean',
    ];
}

