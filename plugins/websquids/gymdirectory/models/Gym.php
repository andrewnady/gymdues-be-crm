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
        'faqs' => [
            Faq::class,
            'key' => 'gym_id'
        ],
        'addresses' => [
            Address::class,
            'key' => 'gym_id'
        ],
        'contacts' => [
            Contact::class,
            'key' => 'gym_id'
        ],
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    public $attachOne = [
        'logo' => \System\Models\File::class,
        'featured_image' => \System\Models\File::class,
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

    /**
     * Get the primary address for this gym
     * @return Address|null
     */
    public function getPrimaryAddress()
    {
        // First try to get address with is_primary = true
        $primary = $this->addresses()->where('is_primary', true)->first();
        
        // If no primary, get the first address (by id)
        if (!$primary) {
            $primary = $this->addresses()->orderBy('id', 'asc')->first();
        }
        
        return $primary;
    }

    /**
     * Override pricing relationship to filter by primary address
     * Creates a hasMany relationship from Gym that filters by primary address ID
     * This ensures the parent model is Gym (as RelationController expects) while filtering results
     */
    public function pricing()
    {
        $primaryAddress = $this->getPrimaryAddress();
        $addressId = $primaryAddress ? $primaryAddress->id : -1; // Use -1 if no address (will return empty)
        
        // Create relationship with Gym as parent, filtered by primary address
        return $this->hasMany(Pricing::class, 'address_id')
            ->where('address_id', $addressId);
    }

    /**
     * Override hours relationship to filter by primary address
     * Creates a hasMany relationship from Gym that filters by primary address ID
     */
    public function hours()
    {
        $primaryAddress = $this->getPrimaryAddress();
        $addressId = $primaryAddress ? $primaryAddress->id : -1;
        
        return $this->hasMany(Hour::class, 'address_id')
            ->where('address_id', $addressId);
    }

    /**
     * Override reviews relationship to filter by primary address
     * Creates a hasMany relationship from Gym that filters by primary address ID
     */
    public function reviews()
    {
        $primaryAddress = $this->getPrimaryAddress();
        $addressId = $primaryAddress ? $primaryAddress->id : -1;
        
        return $this->hasMany(Review::class, 'address_id')
            ->where('address_id', $addressId);
    }
}
