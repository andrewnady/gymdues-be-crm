<?php namespace websquids\Gymdirectory\Models;

use Model;

/**
 * BestGymsPage Model
 *
 * Stores pre-generated "Best Gyms in {City/State}" page payloads.
 * Pages are generated once via the gymdirectory:generate-best-gyms-pages console command
 * and served statically from this table on every request — no live gym/address queries or
 * AI calls at page load time.
 */
class BestGymsPage extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    public $table = 'websquids_gymdirectory_best_gyms_pages';

    public $rules = [
        'title'     => 'required|string|max:255',
        'slug'      => 'required|string|max:255',
        'gyms_data' => 'required',
    ];

    public $fillable = [
        'title',
        'slug',
        'featured_image',
        'intro_section',
        'faq_section',
        'gyms_data',
        'state',
        'city',
        'country_id',
        'state_id',
        'city_id',
    ];

    protected $casts = [
        'gyms_data'  => 'array',
        'country_id' => 'integer',
        'state_id'   => 'integer',
        'city_id'    => 'integer',
    ];

    protected $dates = ['created_at', 'updated_at'];
}
