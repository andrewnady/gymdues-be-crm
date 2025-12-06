<?php

namespace websquids\Gymdirectory\Models;

use Model;

/**
 * Model
 */
class Gym extends Model {
    use \Winter\Storm\Database\Traits\Validation;

    use \Winter\Storm\Database\Traits\SoftDelete;

    use \Winter\Storm\Database\Traits\Sluggable;

    protected $slugs = [
        'slug' => 'name',
    ];

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

    /**
     * Scope for filtering results (Used by the API)
     */
    public function scopeFilter($query, $filters) {
        // Search (Name or Description)
        // Usage: ?search=Gold
        if (isset($filters['search']) && $filters['search']) {
            $searchTerm = $filters['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        // City Filter
        // Usage: ?city=Cairo
        if (isset($filters['city']) && $filters['city']) {
            $query->where('city', $filters['city']);
        }

        // State Filter
        // Usage: ?state=CA
        if (isset($filters['state']) && $filters['state']) {
            $query->where('state', $filters['state']);
        }

        // Trending Filter
        // Usage: ?trending=true
        if (isset($filters['trending'])) {
            $query->where('trending', $filters['trending'] === 'true');
        }

        // Sorting
        // Usage: ?sort=newest or ?sort=name_asc
        if (isset($filters['sort'])) {
            switch ($filters['sort']) {
                case 'newest':
                    $query->orderBy('created_at', 'desc');
                    break;
                case 'oldest':
                    $query->orderBy('created_at', 'asc');
                    break;
                case 'name_asc':
                    $query->orderBy('name', 'asc');
                    break;
                case 'name_desc':
                    $query->orderBy('name', 'desc');
                    break;
                default:
                    $query->orderBy('id', 'desc');
            }
        }

        return $query;
    }
}
