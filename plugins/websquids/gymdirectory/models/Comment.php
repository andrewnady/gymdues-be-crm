<?php

namespace Websquids\Gymdirectory\Models;

use Winter\Storm\Database\Model;

/**
 * Blog comment model for API use.
 * Uses Winter.Blog table (winter_blog_comments) so the API works even when the server
 * has an older Winter.Blog plugin that does not include the Comment class (added in v2.2.2).
 */
class Comment extends Model
{
    public $table = 'winter_blog_comments';

    public $fillable = ['post_id', 'name', 'email', 'comment', 'is_approved'];

    protected $casts = [
        'is_approved' => 'boolean',
    ];

    /**
     * Scope: only approved comments.
     */
    public function scopeApproved($query)
    {
        return $query
            ->whereNotNull('is_approved')
            ->where('is_approved', true);
    }

    /**
     * Scope: newest first.
     */
    public function scopeNewest($query)
    {
        return $query->orderBy('id', 'desc');
    }
}
