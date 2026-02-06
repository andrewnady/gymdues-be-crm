<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Winter\Blog\Models\Post;
use Winter\Storm\Support\Facades\Url;

/**
 * Custom blog API controller (lives in gymdirectory so it is never overwritten by Winter.Blog plugin updates).
 * Uses Winter\Blog models for data only.
 */
class BlogController extends Controller
{
    /**
     * GET /api/v1/posts
     * List all blog posts with filters and pagination
     */
    public function index(Request $request)
    {
        $query = Post::with(['categories', 'featured_images', 'user']);

        if ($request->has('published')) {
            if ($request->boolean('published')) {
                $query->isPublished();
            }
        } else {
            $query->isPublished();
        }

        if ($request->has('category')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('slug', $request->input('category'));
            });
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        $query->orderBy('published_at', 'desc')
            ->orderBy('created_at', 'desc');

        $perPage = $request->input('per_page', 10);
        $posts = $query->paginate($perPage);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $posts */
        $collection = $posts->getCollection();
        $transformed = $collection->map(function ($post) {
            return $this->transformPost($post);
        });
        $posts->setCollection($transformed);

        return $posts;
    }

    /**
     * GET /api/v1/posts/{slug}
     * Get single blog post details
     */
    public function show($slug)
    {
        $post = Post::with(['categories', 'featured_images', 'content_images', 'user'])
            ->where('slug', $slug)
            ->firstOrFail();

        return $this->transformPost($post, true);
    }

    /**
     * Transform post data for API response
     */
    private function transformPost($post, $includeContent = false)
    {
        $data = [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'summary' => $post->summary ?? '',
            'published' => $post->published ? true : false,
            'published_at' => $post->published_at ? $post->published_at->toIso8601String() : null,
            'created_at' => $post->created_at->toIso8601String(),
            'updated_at' => $post->updated_at->toIso8601String(),
            'categories' => $post->categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ];
            }),
            'featured_images' => $post->featured_images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'path' => $image->path,
                    'url' => Url::to($image->getPath()),
                    'filename' => $image->filename,
                ];
            }),
            'author' => $post->user ? [
                'id' => $post->user->id,
                'name' => $post->user->full_name ?? $post->user->login ?? 'Unknown',
                'avatar' => $post->user->avatar?->path,
            ] : null,
        ];

        if ($includeContent) {
            $data['content'] = $post->content;
            $data['content_html'] = $post->content_html ?? '';
            $data['content_images'] = $post->content_images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'path' => $image->path,
                    'url' => Url::to($image->getPath()),
                    'filename' => $image->filename,
                ];
            });
        }

        return $data;
    }
}
