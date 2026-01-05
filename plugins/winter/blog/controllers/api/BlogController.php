<?php

namespace Winter\Blog\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Winter\Blog\Models\Post;
use Winter\Blog\Models\Category;

class BlogController extends Controller {
  /**
   * GET /api/v1/posts
   * List all blog posts with filters and pagination
   */
  public function index(Request $request) {
    // Query posts
    $query = Post::with(['categories', 'featured_images', 'user']);

    // Filter by published status
    if ($request->has('published')) {
      if ($request->boolean('published')) {
        $query->isPublished();
      }
    } else {
      // Default to published only
      $query->isPublished();
    }

    // Filter by category
    if ($request->has('category')) {
      $query->whereHas('categories', function ($q) use ($request) {
        $q->where('slug', $request->input('category'));
      });
    }

    // Search by title or content
    if ($request->has('search')) {
      $search = $request->input('search');
      $query->where(function ($q) use ($search) {
        $q->where('title', 'like', "%{$search}%")
          ->orWhere('content', 'like', "%{$search}%")
          ->orWhere('excerpt', 'like', "%{$search}%");
      });
    }

    // Sort by published_at descending (newest first), with fallback to created_at
    $query->orderBy('published_at', 'desc')
      ->orderBy('created_at', 'desc');

    // Pagination
    $perPage = $request->input('per_page', 10);
    $posts = $query->paginate($perPage);

    // Transform data
    // @phpstan-ignore-next-line - getCollection() exists on paginator
    $collection = $posts->getCollection();
    $transformed = $collection->map(function ($post) {
      return $this->transformPost($post);
    });
    // @phpstan-ignore-next-line - setCollection() exists on paginator
    $posts->setCollection($transformed);

    return $posts;
  }

  /**
   * GET /api/v1/posts/{slug}
   * Get single blog post details
   */
  public function show($slug) {
    $post = Post::with(['categories', 'featured_images', 'content_images', 'user'])
      ->where('slug', $slug)
      ->firstOrFail();

    return $this->transformPost($post, true);
  }

  /**
   * POST /api/v1/posts
   * Create a new blog post
   */
  public function store(Request $request) {
    $data = $request->validate([
      'title' => 'required|string|max:255',
      'slug' => 'nullable|string|max:255|unique:winter_blog_posts,slug',
      'content' => 'required|string',
      'excerpt' => 'nullable|string',
      'published' => 'sometimes|boolean',
      'published_at' => 'nullable|date',
      'categories' => 'sometimes|array',
      'categories.*' => 'exists:winter_blog_categories,id',
    ]);

    // Generate slug if not provided
    if (empty($data['slug'])) {
      $data['slug'] = \Illuminate\Support\Str::slug($data['title']);
    }

    // Create post
    $post = new Post;
    $post->title = $data['title'];
    $post->slug = $data['slug'];
    $post->content = $data['content'];
    $post->excerpt = $data['excerpt'] ?? '';
    $post->published = $data['published'] ?? false;

    if (isset($data['published_at'])) {
      $post->published_at = $data['published_at'];
    } elseif ($post->published && !$post->published_at) {
      $post->published_at = now();
    }

    $post->save();

    // Attach categories
    if (!empty($data['categories'])) {
      $post->categories()->sync($data['categories']);
    }

    return response()->json([
      'message' => 'Post created successfully',
      'post' => $this->transformPost($post->load(['categories', 'featured_images', 'user']))
    ], 201);
  }

  /**
   * Transform post data for API response
   */
  private function transformPost($post, $includeContent = false) {
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
          'url' => \Winter\Storm\Support\Facades\Url::to($image->getPath()),
          'filename' => $image->filename,
        ];
      }),
      'author' => $post->user ? [
        'id' => $post->user->id,
        'name' => $post->user->full_name ?? $post->user->login ?? 'Unknown',
        'avatar' => $post->user->avatar?->path,
      ] : null,
    ];

    // Include full content if requested
    if ($includeContent) {
      $data['content'] = $post->content;
      $data['content_html'] = $post->content_html ?? '';
      $data['content_images'] = $post->content_images->map(function ($image) {
        return [
          'id' => $image->id,
          'path' => $image->path,
          'url' => \Winter\Storm\Support\Facades\Url::to($image->getPath()),
          'filename' => $image->filename,
        ];
      });
    }

    return $data;
  }
}
