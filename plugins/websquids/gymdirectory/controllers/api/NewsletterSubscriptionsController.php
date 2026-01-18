<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use websquids\Gymdirectory\Models\NewsletterSubscription;

class NewsletterSubscriptionsController extends Controller
{
    /**
     * POST /api/v1/newsletter-subscriptions
     * Store a new newsletter subscription
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        // Check if email already exists
        $existing = NewsletterSubscription::where('email', $validated['email'])
            ->whereNull('unsubscribed_at')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Email is already subscribed',
                'id' => $existing->id,
            ], 200);
        }

        // Check if email was previously unsubscribed and resubscribe
        $unsubscribed = NewsletterSubscription::where('email', $validated['email'])
            ->whereNotNull('unsubscribed_at')
            ->first();

        if ($unsubscribed) {
            $unsubscribed->resubscribe();
            return response()->json([
                'message' => 'Successfully resubscribed',
                'id' => $unsubscribed->id,
            ], 200);
        }

        // Create new subscription
        $subscription = NewsletterSubscription::create([
            'email' => $validated['email'],
        ]);

        return response()->json([
            'message' => 'Successfully subscribed to newsletter',
            'id' => $subscription->id,
        ], 201);
    }
}

