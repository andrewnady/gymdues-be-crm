<?php namespace websquids\Gymdirectory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use websquids\Gymdirectory\Models\Address;
use websquids\Gymdirectory\Models\BestGymsPage;
use websquids\Gymdirectory\Services\GeminiService;
use websquids\Gymdirectory\Services\OpenAIService;

/**
 * Processes multiple city/state combinations in a single Gemini API call.
 *
 * Usage:
 *   BatchProcessBestGymsPages::dispatch([
 *       ['country' => 'US', 'state' => 'TX', 'city' => 'Austin'],
 *       ['country' => 'US', 'state' => 'TX', 'city' => 'Dallas'],
 *   ]);
 *
 * Recommended batch size: 20–50 locations per job to stay within timeouts.
 */
class BatchProcessBestGymsPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    /**
     * @param  array<array{country: string, state: string, city: string}>  $locations
     */
    public function __construct(
        private array $locations,
        private bool  $force = false,
    ) {}

    public function handle(): void
    {
        // 1. Build candidate gym lists per location, skip those already processed.
        $batches = [];

        foreach ($this->locations as $loc) {
            $city  = $loc['city']  ?? '';
            $state = $loc['state'] ?? '';
            $slug  = $this->buildSlug($city, $state);

            if (!$this->force && BestGymsPage::where('slug', $slug)->exists()) {
                Log::info("BatchProcessBestGymsPages: skipped {$slug} — already exists");
                continue;
            }

            $gyms = $this->queryCandidateGyms($state, $city);

            if (empty($gyms)) {
                Log::info("BatchProcessBestGymsPages: skipped [{$city}, {$state}] — no qualifying gyms");
                continue;
            }

            $batches[] = [
                'country'  => $loc['country'] ?? 'United States',
                'state'    => $state,
                'city'     => $city,
                'location' => $city !== '' ? $city : $state,
                'type'     => $city !== '' ? 'city' : 'state',
                'gyms'     => $gyms,
            ];
        }

        if (empty($batches)) {
            Log::info('BatchProcessBestGymsPages: nothing to process in this batch');
            return;
        }

        // 2. Single Gemini API call for all locations in the batch.
        $geminiInput = array_map(fn($b) => [
            'location' => $b['location'],
            'type'     => $b['type'],
            'state'    => $b['state'],
            'gyms'     => $b['gyms'],
        ], $batches);

        Log::info("Sending batch to Gemini API");

        // $rankedResults = (new GeminiService())->rankGymsForLocationsBatch($geminiInput);
        $rankedResults = (new OpenAIService())->rankGymsForLocationsBatch($geminiInput);

        // Index Gemini results by "location|type" for fast lookup.
        $rankedIndex = [];
        foreach ($rankedResults as $result) {
            $key = $result['location'] . '|' . $result['type'];
            $rankedIndex[$key] = $result['gym_ids'];
        }

        Log::info('BatchProcessBestGymsPages: Gemini returned rankings for ' . count($rankedResults) . ' locations');

        // 3. Persist a BestGymsPage record for each location.
        foreach ($batches as $batch) {
            $this->savePage($batch, $rankedIndex);
        }
    }

    private function savePage(array $batch, array $rankedIndex): void
    {
        $city    = $batch['city'];
        $state   = $batch['state'];
        $country = $batch['country'];
        $slug    = $this->buildSlug($city, $state);
        $title   = $this->buildTitle($city, $state);

        $key       = $batch['location'] . '|' . $batch['type'];
        $rankedIds = $rankedIndex[$key] ?? [];

        if (!empty($rankedIds)) {
            $gymsData = $this->fetchTopGyms(array_slice($rankedIds, 0, 10));
            Log::info("BatchProcessBestGymsPages: Gemini ranked " . count($gymsData) . " gyms for [{$city}, {$state}]");
        } else {
            // Fallback: sort by internal DB rating.
            Log::info("BatchProcessBestGymsPages: using DB-rating fallback for [{$city}, {$state}]");
            $gymsData = collect($batch['gyms'])
                ->sortByDesc(fn($g) => $g['rating'] ?? 0)
                ->take(10)
                ->values()
                ->all();
        }

        if (empty($gymsData)) {
            Log::info("BatchProcessBestGymsPages: skipped {$slug} — no gyms after ranking");
            return;
        }

        $filterType = ($city) ? 'city' : 'state';
        $introSection = $this->getIntoSection($gymsData, $city, $state, $filterType);

        $payload = [
            'title'          => $title,
            'slug'           => $slug,
            'gyms_data'      => $gymsData,
            'country'        => $country ?: null,
            'state'          => $state   ?: null,
            'city'           => $city    ?: null,
            'intro_section'  => json_encode($introSection),
            'faq_section'    => null,
            'featured_image' => null,
        ];

        Log::info("Sending payload to save in database");

        $existing = BestGymsPage::where('slug', $slug)->first();

        if ($existing) {
            $existing->fill($payload)->save();
            Log::info("BatchProcessBestGymsPages: updated {$slug}  gyms: " . count($gymsData));
        } else {
            BestGymsPage::create($payload);
            Log::info("BatchProcessBestGymsPages: created {$slug}  gyms: " . count($gymsData));
        }
    }

    /**
     * Fetch and serialize all qualifying gym candidates for a location (id + name only).
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function queryCandidateGyms(string $state, string $city): array
    {
        $query = Address::with(['gym:id,name'])->whereHas('gym');

        if ($state !== '') {
            $query->where('state', $state);
        }
        if ($city !== '') {
            $query->where('city', $city);
        }

        return $query->get()
            ->groupBy('gym_id')
            ->map(function ($addrs) {
                $gym = $addrs->first()->gym;
                if (!$gym) return null;

                return [
                    'id'   => $gym->id,
                    'name' => $gym->name,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Fetch full gym data (reviews, logo, gallery, address) for the ranked IDs.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTopGyms(array $gymIds): array
    {
        if (empty($gymIds)) {
            return [];
        }

        $addresses = Address::with(['reviews', 'gym' => function ($q) {
            $q->with(['logo', 'gallery', 'featured_image']);
        }])
            ->whereHas('gym')
            ->whereIn('gym_id', $gymIds)
            ->get()
            ->makeHidden('reviews');

        return $addresses
            ->groupBy('gym_id')
            ->map(function ($addrs) {
                $gym = $addrs->first()->gym;
                if (!$gym) return null;

                $allRates = $addrs->flatMap(function ($a) {
                    return $a->reviews ? $a->reviews->pluck('rate') : collect();
                })->filter();

                $gym->rating      = $allRates->isNotEmpty() ? round((float) $allRates->avg(), 2) : 0;
                $gym->reviewCount = $allRates->count();
                $gym->address     = $addrs->first();

                if (!$gym->featured_image) {
                    $gym->featured_image = $gym->gallery
                        ? $gym->gallery->sortByDesc('created_at')->first()
                        : null;
                }

                $gym->setVisible([
                    'id', 'slug', 'trending', 'name', 'description', 'city', 'state',
                    'rating', 'reviewCount', 'logo', 'gallery', 'featured_image', 'address',
                ]);

                return $gym->toArray();
            })
            ->filter()
            ->values()
            ->all();
    }

    private function buildTitle(string $city, string $state): string
    {
        if ($city !== '' && $state !== '') return "Best Gyms in {$city}, {$state}";
        if ($city !== '')                   return "Best Gyms in {$city}";
        if ($state !== '')                  return "Best Gyms in {$state}";
        return "Best Gyms";
    }

    private function buildSlug(string $city, string $state): string
    {
        if ($city !== '')  return Str::slug("best-{$city}-gyms");
        if ($state !== '') return Str::slug("best-{$state}-gyms");
        return 'best-gyms';
    }

    private function getIntoSection($gymsData, $city, $state, $filterType): array
    {
        $filter = $city !== '' ? $city : $state;

        $mainHeading = $city !== '' ? "Best Gyms in {$city}" : "Best Gyms in {$state}";

        $gymNames = collect($gymsData)
            ->take(10)
            ->pluck('name')
            ->filter()
            ->join(', ');

        if (empty($gymNames)) {
            $gymNames = 'top-rated local gyms';
        }

        if ($filterType === 'state') {
            $subHeading = "The best gyms in {$filter}—based on ratings and reviews from Google, Yelp, and ClassPass—include {$gymNames}. The fitness culture across {$filter} is shaped by 24/7 convenience, a strong strength training culture, and boutique studio variety, with popular training styles such as strength training, HIIT, Pilates, boxing, and CrossFit."
                . "\n\nIn addition to large gym chains, {$filter} has a wide range of Pilates, yoga, boxing, and HIIT studios and specialized facilities, making it easier to find a great fit for fat loss or muscle gain. Many members look for gyms near major metropolitan hubs and suburban centers because it aligns with work-life balance and local commuting patterns."
                . "\n\nSince {$filter} spans a mix of urban and residential landscapes, training habits often shift with local climate and seasonal shifts. Whether you're a beginner, a busy professional, or a powerlifter, the best gyms in {$filter} offer options from full-service health clubs to strength-focused gyms, with amenities like group classes, personal training, and saunas.";
        } else {
            $subHeading = "The best gyms in {$filter}—based on ratings and reviews from Google, Yelp, and ClassPass—include {$gymNames}. The fitness scene in {$filter} is known for 24/7 convenience, a deep-rooted strength training culture, and boutique studio variety, with popular training styles like strength training, HIIT, Pilates, boxing, and CrossFit."
                . "\n\nBeyond traditional gyms, {$filter} also has a strong mix of Pilates, yoga, boxing, and HIIT studios and specialized facilities, which is great if you're focused on fat loss or muscle gain. Many people choose gyms near major transit hubs and central landmarks because it's convenient for commuting and balancing a busy daily schedule."
                . "\n\nWith its vibrant urban layout and local seasonal shifts, workout routines in {$filter} often adapt throughout the year. Whether you're a beginner, a busy professional, or a powerlifter, the best gyms in {$filter} offer everything from full-service health clubs to strength-focused gyms, plus amenities like group classes, personal training, and saunas.";
        }

        return [
            'main_heading' => $mainHeading,
            'sub_heading'  => $subHeading,
        ];
    }
}
