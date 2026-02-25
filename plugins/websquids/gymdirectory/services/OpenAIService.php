<?php namespace websquids\Gymdirectory\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uses the OpenAI gpt-4o-search-preview model (with built-in web search) to
 * rank a list of gyms by real-world popularity for a given location.
 *
 * Setup:
 *   Add OPEN_AI_KEY=<your_key> to your .env file.
 *
 * How it works:
 *   1. Receives a short list of candidate gyms (id + name) already filtered from the DB.
 *   2. Sends them to OpenAI with a prompt asking it to rank by popularity / rating.
 *   3. The model uses live web search to research each gym before responding.
 *   4. Returns an ordered array of gym IDs (best first).
 *
 * Docs: https://platform.openai.com/docs/guides/web-search
 */
class OpenAIService
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const MODEL    = 'gpt-4o-mini';

    /**
     * Rank a list of gyms by real-world popularity using OpenAI + web search.
     *
     * @param  array<array{id: int, name: string}>  $gyms  Candidate gyms from the DB
     * @param  string  $city   Empty string for state-wide pages
     * @param  string  $state
     * @return array<int>  Ordered gym IDs (best first), or empty array on failure/no key
     */
    public function rankGymsForLocation(array $gyms, string $city, string $state): array
    {
        $apiKey = env('OPEN_AI_KEY');

        if (empty($apiKey)) {
            Log::debug('OpenAIService: OPEN_AI_KEY is not set — skipping AI ranking.');
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

Use web search to research these gyms and identify which ones are the most popular, highest-rated, and best-regarded fitness centers in {$location}. Consider: Google star rating, total number of customer reviews, brand reputation, and overall quality.

Return ONLY a valid JSON array of the gym IDs (integers) in ranked order — best first, maximum 10 gyms. Only include gyms that are genuinely popular and well-regarded. Do not include any explanation, markdown formatting, or extra text — output the raw JSON array only.

Example output: [42, 7, 15, 3]
PROMPT;

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post(self::ENDPOINT, [
                    'model'    => self::MODEL,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.1,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('OpenAIService: Connection failed — falling back to DB ranking', [
                'location' => $location,
                'error'    => $e->getMessage(),
            ]);
            return [];
        }

        if (!$response->successful()) {
            Log::error('OpenAIService: API request failed', [
                'location' => $location,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
            return [];
        }

        $text = $response->json('choices.0.message.content', '');

        Log::info('OpenAIService: Raw response', [$response->body()]);

        if (empty($text)) {
            Log::warning('OpenAIService: Empty response from API', [
                'location' => $location,
                'response' => $response->json(),
            ]);
            return [];
        }

        $validIds = array_column($gyms, 'id');

        return $this->parseIds($text, $validIds);
    }

    /**
     * Rank gyms for multiple locations in a single OpenAI API call.
     *
     * @param  array<array{location: string, type: string, state: string, gyms: array<array{id: int, name: string}>}>  $locations
     * @return array<array{location: string, type: string, gym_ids: array<int>}>
     */
    public function rankGymsForLocationsBatch(array $locations): array
    {
        $apiKey = env('OPEN_AI_KEY');

        if (empty($apiKey)) {
            Log::debug('OpenAIService: OPEN_AI_KEY is not set — skipping batch AI ranking.');
            return [];
        }

        if (empty($locations)) {
            return [];
        }

        // Build per-location blocks and index valid IDs for validation later
        $blocks   = '';
        $validMap = [];   // "location|type" => [id, ...]

        foreach ($locations as $loc) {
            $key            = $loc['location'] . '|' . $loc['type'];
            $validMap[$key] = array_column($loc['gyms'], 'id');

            $context  = $loc['type'] === 'city' ? "city in {$loc['state']}" : 'state';
            $gymJson  = json_encode($loc['gyms']);

            $blocks .= "\nLocation \"{$loc['location']}\" ({$context}):\n{$gymJson}\n";
        }

        $prompt = <<<PROMPT
            You are a fitness industry expert curating "Best Gyms" directory pages.

            Below are gyms from multiple locations. For each location, research the gyms by their IDs (provided below) and rank them by real-world popularity, Google star rating, total review count, and overall reputation. Only include gyms that are genuinely popular and well-regarded.

            {$blocks}

            Your response should be in the following JSON format:

            [
                {
                    "location": "Austin",
                    "type": "city",
                    "gym_ids": [3, 1]  // Ranked by real-world popularity, with best gyms first.
                },
                {
                    "location": "Dallas",
                    "type": "city",
                    "gym_ids": [7, 5, 6]  // Ranked by real-world popularity, with best gyms first.
                }
            ]

            Notes:
            - "location" must match the location name exactly as provided.
            - "type" must be either "city" or "state", as given.
            - "gym_ids" must be ranked with the best gyms first (based on popularity, reviews, and reputation).
            - Return only gyms that are genuinely popular and well-regarded. 
            - If there are more than 10 gyms per location, limit the list to the top 10.

            Only return the raw JSON array — no markdown, no explanation.

        PROMPT;

        try {
            Log::info('OpenAIService: Requesting batch ranking');
            $response = Http::timeout(120)
                ->withToken($apiKey)
                ->post(self::ENDPOINT, [
                    'model'    => self::MODEL,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.1,
                ]);

            Log::info('OpenAIService: Batch request done');
        } catch (ConnectionException $e) {
            Log::warning('OpenAIService: Batch connection failed — falling back to DB ranking', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (!$response->successful()) {
            Log::error('OpenAIService: Batch API request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return [];
        }

        $text = $response->json('choices.0.message.content', '');

        Log::info('OpenAIService: Batch raw response', [$response->body()]);

        if (empty($text)) {
            Log::warning('OpenAIService: Empty batch response from API', [
                'response' => $response->json(),
            ]);
            return [];
        }

        return $this->parseLocationsBatch($text, $validMap);
    }

    /**
     * Parse and validate the batch response.
     * Each entry is validated so only known IDs survive.
     *
     * @param  array<string, array<int>>  $validMap  "location|type" => [valid IDs]
     * @return array<array{location: string, type: string, gym_ids: array<int>}>
     */
    private function parseLocationsBatch(string $text, array $validMap): array
    {
        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', $text);
        $text = trim($text);

        $raw = json_decode($text, true);

        if (!is_array($raw)) {
            Log::warning('OpenAIService: Batch response is not a parseable JSON array', ['text' => $text]);
            return [];
        }

        $results = [];

        foreach ($raw as $entry) {
            if (!isset($entry['location'], $entry['type'], $entry['gym_ids'])) {
                continue;
            }

            $key = $entry['location'] . '|' . $entry['type'];

            if (!isset($validMap[$key])) {
                continue;
            }

            $validIds = $validMap[$key];

            $results[] = [
                'location' => $entry['location'],
                'type'     => $entry['type'],
                'gym_ids'  => array_values(array_filter(
                    array_map('intval', (array) $entry['gym_ids']),
                    fn($id) => in_array($id, $validIds, true)
                )),
            ];
        }

        Log::info('OpenAIService: Parsed batch result', [$results]);

        return $results;
    }

    /**
     * Extract and validate gym IDs from OpenAI's response text.
     * Handles cases where the model wraps output in markdown code blocks.
     */
    private function parseIds(string $text, array $validIds): array
    {
        // Strip markdown code fences (```json ... ``` or ``` ... ```)
        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', $text);
        $text = trim($text);

        $ids = json_decode($text, true);

        if (!is_array($ids)) {
            Log::warning('OpenAIService: Response is not a parseable JSON array', ['text' => $text]);
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
