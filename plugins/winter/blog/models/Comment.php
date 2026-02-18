<?php

namespace Winter\Blog\Models;

use Winter\Storm\Database\Model;
use Winter\Storm\Database\Traits\Validation;

class Comment extends Model
{
    use Validation;

    public $table = 'winter_blog_comments';

    public $rules = [
        'post_id' => 'required|integer',
        'name'    => 'required|string|max:255',
        'email'   => 'required|email|max:255',
        'comment' => 'required|string',
    ];

    protected $fillable = [
        'post_id',
        'name',
        'email',
        'comment',
        'is_approved',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
    ];

    /*
     * Relations
     */
    public $belongsTo = [
        'post' => [\Winter\Blog\Models\Post::class],
    ];

    /*
     * Scopes
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopeNewest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
