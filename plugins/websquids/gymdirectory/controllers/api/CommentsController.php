<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Winter\Blog\Models\Post;
use Winter\Blog\Models\Comment;

/**
 * Custom blog comments API controller (lives in gymdirectory so it is never overwritten by Winter.Blog plugin updates).
 * Uses Winter\Blog models for data only.
 */
class CommentsController extends Controller
{
    /**
     * GET /api/v1/posts/{slug}/comments
     * Get all approved comments for a blog post
     */
    public function index($slug)
    {
        $post = Post::where('slug', $slug)->firstOrFail();

        $comments = Comment::where('post_id', $post->id)
            ->approved()
            ->newest()
            ->get();

        return $comments->map(function ($comment) {
            return [
                'id' => $comment->id,
                'name' => $comment->name,
                'email' => $comment->email,
                'comment' => $comment->comment,
                'created_at' => $comment->created_at->toIso8601String(),
            ];
        });
    }

    /**
     * POST /api/v1/posts/{slug}/comments
     * Submit a new comment for a blog post
     */
    public function store(Request $request, $slug)
    {
        $post = Post::where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'comment' => 'required|string',
        ]);

        $comment = new Comment();
        $comment->post_id = $post->id;
        $comment->name = $data['name'];
        $comment->email = $data['email'];
        $comment->comment = $data['comment'];
        $comment->is_approved = false;
        $comment->save();

        return response()->json([
            'message' => 'Comment submitted successfully. It will be visible after approval.',
            'comment' => [
                'id' => $comment->id,
                'name' => $comment->name,
                'comment' => $comment->comment,
                'created_at' => $comment->created_at->toIso8601String(),
            ],
        ], 201);
    }
}
