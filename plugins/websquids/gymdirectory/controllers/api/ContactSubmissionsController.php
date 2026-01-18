<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use websquids\Gymdirectory\Models\ContactSubmission;

class ContactSubmissionsController extends Controller
{
    /**
     * POST /api/v1/contact-submissions
     * Store a new contact form submission
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $submission = ContactSubmission::create($validated);

        return response()->json([
            'message' => 'Contact submission received successfully',
            'id' => $submission->id,
        ], 201);
    }
}

