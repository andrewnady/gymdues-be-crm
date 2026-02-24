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

class ProcessBestGymsPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private string $city,
        private string $state,
        private bool   $force = false,
    ) {}

    public function handle(): void
    {
        $gymsData = $this->buildTopGymsData($this->state, $this->city);

        if (empty($gymsData)) {
            Log::info("ProcessBestGymsPage: skipped [{$this->city}, {$this->state}] — not enough qualifying gyms");
            return;
        }

        $title = $this->buildTitle($this->city, $this->state);
        $slug  = $this->buildSlug($this->city, $this->state);

        $existing = BestGymsPage::where('slug', $slug)->first();

        if ($existing && !$this->force) {
            Log::info("ProcessBestGymsPage: skipped {$slug} — already exists (run with --force to overwrite)");
            return;
        }

        $payload = [
            'title'          => $title,
            'slug'           => $slug,
            'gyms_data'      => $gymsData,
            'state'          => $this->state ?: null,
            'city'           => $this->city  ?: null,
            'intro_section'  => null,
            'faq_section'    => null,
            'featured_image' => null,
        ];

        if ($existing) {
            $existing->fill($payload)->save();
            Log::info("ProcessBestGymsPage: updated {$slug}  gyms: " . count($gymsData));
        } else {
            BestGymsPage::create($payload);
            Log::info("ProcessBestGymsPage: created {$slug}  gyms: " . count($gymsData));
        }
    }

    /**
     * Orchestrates gym selection:
     *   1. Pulls all qualifying candidates from the DB (≥15 reviews, ≥4.0 rating).
     *   2. Asks Gemini (with live Google Search grounding) to rank them by
     *      real-world popularity and return an ordered list of IDs.
     *   3. Falls back to DB-rating sort when Gemini is unavailable or returns
     *      no usable results.
     */
    private function buildTopGymsData(string $state, string $city): array
    {
        $candidates = $this->queryCandidateGyms($state, $city);

        if (empty($candidates)) {
            return [];
        }

        // Ask Gemini to rank by real-world popularity via Google Search grounding.
        $gymList   = array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name']], $candidates);
        $rankedIds = (new GeminiService())->rankGymsForLocation($gymList, $city, $state);

        if (!empty($rankedIds)) {
            $byId   = collect($candidates)->keyBy('id');
            $ranked = collect($rankedIds)
                ->map(fn($id) => $byId->get($id))
                ->filter()
                ->take(10)
                ->values()
                ->all();

            if (!empty($ranked)) {
                Log::info("ProcessBestGymsPage: Gemini ranked " . count($ranked) . " gyms for [{$city}, {$state}]");
                return $ranked;
            }
        }

        // Fallback: sort by internal DB rating.
        Log::info("ProcessBestGymsPage: using DB-rating fallback for [{$city}, {$state}]");
        return collect($candidates)
            ->sortByDesc(fn($g) => $g['rating'])
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Fetch and serialize all qualifying gym candidates for a location.
     * Applies the quality gate (≥15 reviews, ≥4.0 avg rating) but does NOT
     * sort or limit — that is left to the caller.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryCandidateGyms(string $state, string $city): array
    {
        $addrQuery = Address::with(['reviews', 'gym' => function ($q) {
            $q->with(['logo', 'gallery', 'featured_image']);
        }])->whereHas('gym');

        if ($state !== '') {
            $addrQuery->where('state', $state);
        }
        if ($city !== '') {
            $addrQuery->where('city', $city);
        }

        $addresses = $addrQuery->get()->makeHidden('reviews');

        return $addresses
            ->groupBy('gym_id')
            ->map(function ($addrs) use ($state, $city) {
                $gym = $addrs->first()->gym;
                if (!$gym) {
                    return null;
                }

                $allRates = $addrs->flatMap(function ($a) {
                    return $a->reviews ? $a->reviews->pluck('rate') : collect();
                })->filter();

                $reviewCount = $allRates->count();
                $avgRating   = $allRates->isNotEmpty() ? round((float) $allRates->avg(), 2) : 0;

                if ($reviewCount < 15 || $avgRating < 4) {
                    return null;
                }

                $firstAddr = $addrs->first();

                $gym->rating      = $avgRating;
                $gym->reviewCount = $reviewCount;
                $gym->address     = $firstAddr;

                if (!$gym->featured_image) {
                    $latestGalleryImage = $gym->gallery ? $gym->gallery->sortByDesc('created_at')->first() : null;
                    $gym->featured_image = $latestGalleryImage ?: null;
                }

                $gym->filterType = ($state && $city) ? 'state' : ($state ? 'state' : 'city');

                $gym->setVisible([
                    'id',
                    'slug',
                    'trending',
                    'name',
                    'description',
                    'city',
                    'state',
                    'rating',
                    'reviewCount',
                    'logo',
                    'gallery',
                    'featured_image',
                    'address',
                    'filterType',
                ]);

                return $gym->toArray();
            })
            ->filter()
            ->values()
            ->all();
    }

    private function buildTitle(string $city, string $state): string
    {
        if ($city !== '' && $state !== '') {
            return "Best Gyms in {$city}, {$state}";
        }
        if ($city !== '') {
            return "Best Gyms in {$city}";
        }
        if ($state !== '') {
            return "Best Gyms in {$state}";
        }
        return "Best Gyms";
    }

    private function buildSlug(string $city, string $state): string
    {
        if ($city) {
            return Str::slug('best-' . $city . '-gyms');
        } else if ($state) {
            return Str::slug('best-' . $state . '-gyms');
        } else {
            $parts = array_filter([$city, $state]);
            return Str::slug('best-' . implode('-', $parts) . '-gyms');
        }
    }
}
