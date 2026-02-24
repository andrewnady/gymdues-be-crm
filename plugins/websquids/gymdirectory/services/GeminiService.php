<?php namespace websquids\Gymdirectory\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uses the Gemini 2.0 Flash API with Google Search grounding to rank a list
 * of gyms by real-world popularity for a given location.
 *
 * Setup:
 *   Add GEMINI_API_KEY=<your_key> to your .env file.
 *   The key must have access to "Gemini API" in Google AI Studio or Google Cloud.
 *
 * How it works:
 *   1. Receives a short list of candidate gyms (id + name) already filtered from the DB.
 *   2. Sends them to Gemini with a prompt asking it to rank by popularity / rating.
 *   3. Gemini uses live Google Search to research each gym before responding.
 *   4. Returns an ordered array of gym IDs (best first).
 *
 * Docs: https://ai.google.dev/gemini-api/docs/grounding
 */
class GeminiService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent';

    /**
     * Rank a list of gyms by real-world popularity using Gemini + Google Search.
     *
     * @param  array<array{id: int, name: string}>  $gyms  Candidate gyms from the DB
     * @param  string  $city   Empty string for state-wide pages
     * @param  string  $state
     * @return array<int>  Ordered gym IDs (best first), or empty array on failure/no key
     */
    public function rankGymsForLocation(array $gyms, string $city, string $state): array
    {
        $apiKey = env('GEMINI_API_KEY');

        if (empty($apiKey)) {
            Log::debug('GeminiService: GEMINI_API_KEY is not set — skipping AI ranking.');
            return [];
        }

        if (empty($gyms)) {
            return [];
        }

        $location    = $city !== '' ? "{$city}, {$state}" : $state;
        $gymListJson = json_encode($gyms, JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
You are a fitness industry expert curating a "Best Gyms" directory page.

Below is a list of gyms in our database for {$location}. Each entry has an "id" (integer) and "name" (string):
{$gymListJson}

Use Google Search to research these gyms and identify which ones are the most popular, highest-rated, and best-regarded fitness centers in {$location}. Consider: Google star rating, total number of customer reviews, brand reputation, and overall quality.

Return ONLY a valid JSON array of the gym IDs (integers) in ranked order — best first, maximum 10 gyms. Only include gyms that are genuinely popular and well-regarded. Do not include any explanation, markdown formatting, or extra text — output the raw JSON array only.

Example output: [42, 7, 15, 3]
PROMPT;

        try {
            $response = Http::timeout(30)->post(self::ENDPOINT . '?key=' . $apiKey, [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]],
                ],
                'tools' => [
                    ['googleSearch' => (object) []],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                ],
            ]);
        } catch (ConnectionException $e) {
            Log::warning('GeminiService: Connection failed — falling back to DB ranking', [
                'location' => $location,
                'error'    => $e->getMessage(),
            ]);
            return [];
        }

        if (!$response->successful()) {
            Log::error('GeminiService: API request failed', [
                'location' => $location,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
            return [];
        }

        $text = $response->json('candidates.0.content.parts.0.text', '');

        if (empty($text)) {
            Log::warning('GeminiService: Empty response from API', [
                'location' => $location,
                'response' => $response->json(),
            ]);
            return [];
        }

        $validIds = array_column($gyms, 'id');

        return $this->parseIds($text, $validIds);
    }

    /**
     * Extract and validate gym IDs from Gemini's response text.
     * Handles cases where the model wraps output in markdown code blocks.
     */
    private function parseIds(string $text, array $validIds): array
    {
        // Strip markdown code fences (```json ... ``` or ``` ... ```)
        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', $text);
        $text = trim($text);

        $ids = json_decode($text, true);

        if (!is_array($ids)) {
            Log::warning('GeminiService: Response is not a parseable JSON array', ['text' => $text]);
            return [];
        }

        // Only keep IDs that actually exist in the candidate list (prevent hallucinated IDs)
        return array_values(
            array_filter(
                array_map('intval', $ids),
                fn($id) => in_array($id, $validIds, true)
            )
        );
    }
}
