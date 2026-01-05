<?php

namespace Winter\Blog\Models;

use Winter\Storm\Database\Model;
use Winter\Storm\Database\Traits\Validation;

/**
 * Comment Model
 */
class Comment extends Model
{
    use Validation;

    /**
     * @var string The database table used by the model.
     */
    public $table = 'winter_blog_comments';

    /**
     * @var array Validation rules
     */
    public $rules = [
        'post_id' => 'required|exists:winter_blog_posts,id',
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'comment' => 'required|string',
        'is_approved' => 'boolean',
    ];

    /**
     * @var array Fillable fields
     */
    public $fillable = [
        'post_id',
        'name',
        'email',
        'comment',
        'is_approved',
    ];

    /**
     * @var array Dates
     */
    protected $dates = ['created_at', 'updated_at'];

    /**
     * @var array Casts
     */
    protected $casts = [
        'is_approved' => 'boolean',
    ];

    /**
     * Relations
     */
    public $belongsTo = [
        'post' => [Post::class],
    ];

    /**
     * Scope to get only approved comments
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope to order by newest first
     */
    public function scopeNewest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}

