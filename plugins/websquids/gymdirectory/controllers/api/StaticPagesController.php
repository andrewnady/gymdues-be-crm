<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use websquids\Gymdirectory\Models\StaticPage;

class StaticPagesController extends Controller
{
    /**
     * GET /api/v1/static-pages
     * List all published static pages
     */
    public function index(Request $request)
    {
        $pages = StaticPage::where('is_published', true)
            ->orderBy('title', 'asc')
            ->get();

        return $pages->map(function ($page) {
            return [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
            ];
        });
    }

    /**
     * GET /api/v1/static-pages/{slug}
     * Get single static page by slug
     */
    public function show($slug)
    {
        $page = StaticPage::where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if (!$page) {
            return response()->json(['error' => 'Page not found'], 404);
        }

        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'content' => $page->content,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'created_at' => $page->created_at,
            'updated_at' => $page->updated_at,
        ];
    }
}

