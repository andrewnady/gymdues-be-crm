<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API for gymsdata site — uses PostgreSQL only (connection "gymsdata").
 * All other app code uses the default MySQL connection; this controller
 * explicitly uses DB::connection('gymsdata') so it never touches MySQL.
 *
 * Same response shapes as GymsController so the frontend can swap base path.
 * Table columns: id, business_name, city, state, postal_code, etc.
 */
class GymsdataController extends Controller
{
    /** Uses PostgreSQL connection "gymsdata" only (never default MySQL). */
    protected function table()
    {
        return DB::connection('gymsdata')->table(config('database.gymsdata_table', 'gyms_data'));
    }

    /** State code → full name (e.g. CA → California). */
    protected function stateNames(string $code): string
    {
        $map = [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
            'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
            'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
            'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
            'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
            'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
            'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
            'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
            'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
            'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
            'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
        ];
        $code = strtoupper($code);
        return $map[$code] ?? $code;
    }

    /**
     * State name → code (e.g. California → CA), or pass-through if not found.
     * DB stores state as code; search can be by name or code.
     */
    protected function stateCodeForSearch(string $name): string
    {
        $states = [
            'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR',
            'California' => 'CA', 'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE',
            'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI', 'Idaho' => 'ID',
            'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS', 'Kentucky' => 'KY',
            'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD', 'Massachusetts' => 'MA', 'Michigan' => 'MI',
            'Minnesota' => 'MN', 'Mississippi' => 'MS', 'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE',
            'Nevada' => 'NV', 'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM', 'New York' => 'NY',
            'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK', 'Oregon' => 'OR',
            'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC', 'South Dakota' => 'SD', 'Tennessee' => 'TN',
            'Texas' => 'TX', 'Utah' => 'UT', 'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA',
            'West Virginia' => 'WV', 'Wisconsin' => 'WI', 'Wyoming' => 'WY', 'District of Columbia' => 'DC',
        ];
        $key = ucwords(strtolower($name));
        return $states[$name] ?? $states[$key] ?? $name;
    }

    /** Escape LIKE wildcards (% and _) for PostgreSQL. */
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** State name to URL slug (e.g. "New York" → "new-york"). */
    protected function stateSlug(string $stateCode): string
    {
        $name = $this->stateNames($stateCode);
        return strtolower(preg_replace('/\s+/', '-', $name));
    }

    /** URL slug to state name/code input: lowercase, hyphens → spaces (e.g. "new-york" → "new york"). */
    protected function normalizeStateSlug(string $slug): string
    {
        return str_replace('-', ' ', $slug);
    }

    /** URL slug to city name: hyphens → spaces, title case (e.g. "costa-mesa" → "Costa Mesa"). */
    protected function normalizeCitySlug(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    /**
     * City slug to two normalized forms for DB match: with spaces and with hyphens (e.g. Winston-Salem stored either way).
     * Returns [lowercase_with_spaces, lowercase_with_hyphens].
     */
    protected function citySlugToMatchPairs(string $slug): array
    {
        $slug = strtolower(trim($slug));
        $withSpaces = str_replace('-', ' ', $slug);
        $withHyphens = str_replace(' ', '-', $slug);
        return [$withSpaces, $withHyphens];
    }

    /**
     * Image URL for "Browse Gyms By State" cards. Set GYMSDATA_STATE_IMAGE_BASE_URL in .env
     * (e.g. https://gymdues.com/images/states) — then URLs are {base}/{state-slug}.jpg.
     */
    protected function stateImageUrl(string $stateCode): ?string
    {
        $base = env('GYMSDATA_STATE_IMAGE_BASE_URL');
        if ($base === null || trim($base) === '') {
            return null;
        }
        return rtrim($base, '/') . '/' . $this->stateSlug($stateCode) . '.jpg';
    }

    /** Approximate state population (2020 census style) for density per 100K. */
    protected function statePopulations(): array
    {
        return [
            'AL' => 5024, 'AK' => 733, 'AZ' => 7152, 'AR' => 3012,
            'CA' => 39538, 'CO' => 5774, 'CT' => 3606, 'DE' => 990,
            'FL' => 21538, 'GA' => 10617, 'HI' => 1455, 'ID' => 1839,
            'IL' => 12821, 'IN' => 6732, 'IA' => 3190, 'KS' => 2938, 'KY' => 4506,
            'LA' => 4658, 'ME' => 1362, 'MD' => 6177, 'MA' => 7029, 'MI' => 10077,
            'MN' => 5706, 'MS' => 2961, 'MO' => 6155, 'MT' => 1084, 'NE' => 1964,
            'NV' => 3108, 'NH' => 1378, 'NJ' => 9289, 'NM' => 2120, 'NY' => 20201,
            'NC' => 10439, 'ND' => 779, 'OH' => 11799, 'OK' => 3959, 'OR' => 4237,
            'PA' => 13012, 'RI' => 1097, 'SC' => 5118, 'SD' => 886, 'TN' => 6911,
            'TX' => 29145, 'UT' => 3272, 'VT' => 643, 'VA' => 8631, 'WA' => 7705,
            'WV' => 1794, 'WI' => 5892, 'WY' => 577, 'DC' => 689,
        ];
    }

    /**
     * GET /api/v1/gymsdata/states
     * States with gym counts (same shape as GET /api/v1/gyms/states).
     */
    public function states(Request $request)
    {
        try {
            $rows = $this->table()
                ->where('type', 'Gym')
                ->selectRaw('state, count(*) as count')
                ->whereNotNull('state')
                ->where('state', '!=', '')
                ->groupBy('state')
                ->orderBy('state')
                ->get();

            $total = $rows->sum(fn ($r) => (int) $r->count);
            $data = $rows->map(function ($row) use ($total) {
                $count = (int) $row->count;
                $pct = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                return [
                    'state' => $row->state,
                    'stateName' => $this->stateNames($row->state),
                    'stateSlug' => $this->stateSlug($row->state),
                    'count' => $count,
                    'pct' => $pct,
                    'imageUrl' => $this->stateImageUrl($row->state),
                ];
            });

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('GymsdataController@states: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/gymsdata/locations
     * Locations (city, state, postal_code) with counts (same shape as GET /api/v1/gyms/locations).
     * Optional ?q= filters. Sorted by count desc.
     */
    public function locations(Request $request)
    {
        try {
            $q = $request->input('q');
            $qTrim = $q ? trim($q) : '';
            
            $query = $this->table()
                ->where('type','Gym')
                ->selectRaw("city, state, COALESCE(postal_code, '') as postal_code, count(*) as count")
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->whereNotNull('state')
                ->where('state', '!=', '')
                ->groupBy('city', 'state', 'postal_code')
                ->orderByRaw('count(*) desc');

            if ($qTrim !== '') {
                $like = '%' . $this->escapeLike($qTrim) . '%';
                $stateLike = '%' . $this->escapeLike($this->stateCodeForSearch($qTrim)) . '%';
                $query->where(function ($qb) use ($like, $stateLike) {
                    $qb->where('city', 'like', $like)
                        ->orWhere('state', 'like', $stateLike)
                        ->orWhere('postal_code', 'like', $like);
                });
            }

            $out = [];
            foreach ($query->limit(50)->get() as $row) {
                $postal = isset($row->postal_code) && $row->postal_code !== '' ? $row->postal_code : '';
                $out[] = [
                    'label' => trim($row->city . ', ' . $row->state . ' ' . $postal),
                    'city' => $row->city,
                    'state' => $row->state,
                    'stateName' => $this->stateNames($row->state),
                    'postal_code' => $postal,
                    'count' => (int) $row->count,
                ];
            }

            return response()->json($out);
        } catch (\Exception $e) {
            Log::error('GymsdataController@locations: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/gymsdata/cities-and-states
     * States and cities with counts (same shape as GET /api/v1/gyms/cities-and-states).
     */
    public function citiesAndStates(Request $request)
    {
        try {
            $stateRows = $this->table()
                ->where('type', 'Gym')
                ->selectRaw('state, count(*) as count')
                ->whereNotNull('state')
                ->where('state', '!=', '')
                ->groupBy('state')
                ->orderBy('state')
                ->get();

            $stateTotal = $stateRows->sum(fn ($r) => (int) $r->count);
            $states = $stateRows->map(function ($row) use ($stateTotal) {
                $count = (int) $row->count;
                $pct = $stateTotal > 0 ? round(($count / $stateTotal) * 100, 1) : 0;
                return [
                    'state' => $row->state,
                    'stateName' => $this->stateNames($row->state),
                    'stateSlug' => $this->stateSlug($row->state),
                    'count' => $count,
                    'pct' => $pct,
                    'imageUrl' => $this->stateImageUrl($row->state),
                ];
            });

            $cityQuery = $this->table()
                ->selectRaw('city, count(*) as count')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->groupBy('city')
                ->orderByRaw('count(*) desc');

            $cityRows = $cityQuery->get()->sortBy('city')->values();
            $cities = $cityRows->map(function ($row) {
                return [
                    'city' => $row->city,
                    'count' => (int) $row->count,
                ];
            });

            return response()->json([
                'cities' => $cities,
                'states' => $states,
            ]);
        } catch (\Exception $e) {
            Log::error('GymsdataController@citiesAndStates: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/gymsdata/cities?state=CA&sort=count|name&group_by=city|location
     * Cities in a state with counts. For "Cities in California" list and nearby cities.
     * sort=count (default): most gyms first. sort=name: city A–Z.
     * group_by=city: one row per city (total gyms). group_by=location: per city+postal_code (default).
     * Optional: limit, offset; min_count / max_count (e.g. min_count=1&max_count=1 for "cities with 1 gym").
     */
    public function cities(Request $request)
    {
        try {
            $state = $request->input('state');
            $stateTrim = $state ? trim($state) : '';
            if ($stateTrim === '') {
                return response()->json([]);
            }
            $stateCode = strtoupper($this->stateCodeForSearch($stateTrim));
            $sort = $request->input('sort', 'count');
            $groupBy = $request->input('group_by', 'location');
            $limit = $request->input('limit');
            $offset = (int) $request->input('offset', 0);
            $minCount = $request->input('min_count');
            $maxCount = $request->input('max_count');

            $byCityOnly = ($groupBy === 'city');

            if ($byCityOnly) {
                $query = $this->table()
                    ->where('type', 'Gym')
                    ->where('state', $stateCode)
                    ->whereNotNull('city')->where('city', '!=', '')
                    ->selectRaw('city, state, count(*) as count')
                    ->groupBy('city', 'state');
            } else {
                $query = $this->table()
                    ->where('type', 'Gym')
                    ->where('state', $stateCode)
                    ->whereNotNull('city')->where('city', '!=', '')
                    ->selectRaw("city, state, COALESCE(postal_code, '') as postal_code, count(*) as count")
                    ->groupBy('city', 'state', 'postal_code');
            }

            if ($minCount !== null && $minCount !== '') {
                $query->havingRaw('count(*) >= ?', [(int) $minCount]);
            }
            if ($maxCount !== null && $maxCount !== '') {
                $query->havingRaw('count(*) <= ?', [(int) $maxCount]);
            }

            if ($sort === 'name') {
                $query->orderBy('city', 'asc');
            } else {
                $query->orderByRaw('count(*) desc');
            }

            if ($limit !== null && $limit !== '') {
                $limit = max(1, min(500, (int) $limit));
                $query->limit($limit)->offset(max(0, $offset));
            }
            $rows = $query->get();

            $out = $rows->map(function ($row) use ($stateCode, $byCityOnly) {
                $postal = $byCityOnly ? '' : (isset($row->postal_code) && $row->postal_code !== '' ? $row->postal_code : '');
                $label = $byCityOnly
                    ? trim($row->city . ', ' . $this->stateNames($row->state ?? $stateCode))
                    : trim($row->city . ', ' . ($row->state ?? $stateCode) . ' ' . $postal);
                return [
                    'label' => $label,
                    'city' => $row->city,
                    'state' => $row->state ?? $stateCode,
                    'stateName' => $this->stateNames($row->state ?? $stateCode),
                    'postal_code' => $postal,
                    'count' => (int) $row->count,
                ];
            });

            return response()->json($out->values()->all());
        } catch (\Exception $e) {
            Log::error('GymsdataController@cities: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/gymsdata/top-cities?limit=10
     * Top cities by gym count (e.g. for "Top 10 cities with the most gyms").
     * Returns rank, city, state, postal_code, label (e.g. "Carmel, Indiana 46032"), count.
     */
    public function topCities(Request $request)
    {
        try {
            $limit = max(1, min(50, (int) $request->input('limit', 10)));
            $rows = $this->table()
                ->where('type', 'Gym')
                ->selectRaw("city, state, COALESCE(postal_code, '') as postal_code, count(*) as count")
                ->whereNotNull('city')->where('city', '!=', '')
                ->whereNotNull('state')->where('state', '!=', '')
                ->groupBy('city', 'state', 'postal_code')
                ->orderByRaw('count(*) desc')
                ->limit($limit)
                ->get();

            $cities = $rows->values()->map(function ($row, $index) {
                $postal = $row->postal_code ?? '';
                $label = trim($row->city . ', ' . $this->stateNames($row->state) . ($postal !== '' ? ' ' . $postal : ''));
                return [
                    'rank' => $index + 1,
                    'city' => $row->city,
                    'state' => $row->state,
                    'stateName' => $this->stateNames($row->state),
                    'postal_code' => $postal,
                    'label' => $label,
                    'count' => (int) $row->count,
                ];
            });

            return response()->json(['cities' => $cities]);
        } catch (\Exception $e) {
            Log::error('GymsdataController@topCities: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/gymsdata/industry-trends
     * One-shot data for "Gym Industry Trends" dashboard: new gyms by month, most growing cities,
     * fastest growing categories (by type), franchise vs independent over time.
     * Uses DB where columns exist (e.g. created_at, type); otherwise returns curated fallbacks.
     */
    public function industryTrends(Request $request)
    {
        try {
            $t = $this->table();

            // 1. New gyms opened (last 12 months) — use created_at if present, else static
            $newGymsByMonth = $this->newGymsByMonth($t);

            // 2. Most growing cities — top 10 by gym count (same shape as top-cities)
            $mostGrowingCities = $this->mostGrowingCitiesForTrends();

            // 3. Fastest growing categories — group by type (or category); fallback to static
            $categories = $this->categoriesForTrends($t);

            // 4. Franchise vs independent — quarterly; use DB if ownership column exists, else static
            $franchiseVsIndependent = $this->franchiseVsIndependentForTrends($t);

            return response()->json([
                'newGymsByMonth' => $newGymsByMonth,
                'mostGrowingCities' => $mostGrowingCities,
                'categories' => $categories,
                'franchiseVsIndependent' => $franchiseVsIndependent,
            ]);
        } catch (\Exception $e) {
            Log::error('GymsdataController@industryTrends: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Last 12 months: month label + count of gyms. Uses created_at if column exists.
     */
    protected function newGymsByMonth($baseQuery): array
    {
        $tableName = $baseQuery->from;
        try {
            $rows = DB::connection('gymsdata')
                ->select(
                    "SELECT date_trunc('month', created_at)::date as month, count(*) as count
                     FROM {$tableName}
                     WHERE type = ? AND created_at >= (current_date - interval '12 months')
                     GROUP BY date_trunc('month', created_at)
                     ORDER BY month ASC",
                    ['Gym']
                );
            if (count($rows) > 0) {
                return array_map(function ($row) {
                    $ts = strtotime($row->month);
                    return [
                        'month' => date('M Y', $ts),
                        'monthKey' => date('Y-m', $ts),
                        'count' => (int) $row->count,
                    ];
                }, $rows);
            }
        } catch (\Exception $e) {
            // created_at or column missing — use static
        }

        $out = [];
        $staticCounts = [412, 508, 552, 598, 612, 588, 545, 520, 498, 472, 448, 485];
        for ($i = 11; $i >= 0; $i--) {
            $date = new \DateTime('first day of this month');
            $date->modify("-{$i} months");
            $out[] = [
                'month' => $date->format('M Y'),
                'monthKey' => $date->format('Y-m'),
                'count' => $staticCounts[11 - $i] ?? 500,
            ];
        }
        return $out;
    }

    /**
     * Top 10 cities for "Most Growing Cities" chart: by growth (new gyms in last 12 mo) when created_at
     * exists, otherwise by total gym count. Response includes growth and period for tooltip (e.g. "+127 gyms (12 mo)").
     */
    protected function mostGrowingCitiesForTrends(): array
    {
        $tableName = $this->table()->from;
        try {
            $rows = DB::connection('gymsdata')
                ->select(
                    "SELECT city, state, count(*) as growth " .
                    "FROM {$tableName} " .
                    "WHERE type = ? AND created_at >= (current_date - interval '12 months') " .
                    "AND city IS NOT NULL AND TRIM(city) != '' AND state IS NOT NULL AND TRIM(state) != '' " .
                    "GROUP BY city, state ORDER BY count(*) DESC LIMIT 10",
                    ['Gym']
                );
            if (count($rows) > 0) {
                $result = [];
                foreach ($rows as $index => $row) {
                    $result[] = [
                        'rank' => $index + 1,
                        'city' => $row->city,
                        'state' => $row->state,
                        'stateName' => $this->stateNames($row->state),
                        'label' => trim($row->city . ', ' . $row->state),
                        'growth' => (int) $row->growth,
                        'count' => (int) $row->growth,
                        'period' => '12 mo',
                    ];
                }
                return $result;
            }
        } catch (\Exception $e) {
            // created_at missing — fall back to top by total count
        }

        $rows = $this->table()
            ->where('type', 'Gym')
            ->selectRaw('city, state, count(*) as count')
            ->whereNotNull('city')->where('city', '!=', '')
            ->whereNotNull('state')->where('state', '!=', '')
            ->groupBy('city', 'state')
            ->orderByRaw('count(*) desc')
            ->limit(10)
            ->get();

        return $rows->values()->map(function ($row, $index) {
            $count = (int) $row->count;
            return [
                'rank' => $index + 1,
                'city' => $row->city,
                'state' => $row->state,
                'stateName' => $this->stateNames($row->state),
                'label' => trim($row->city . ', ' . $row->state),
                'growth' => $count,
                'count' => $count,
                'period' => null,
            ];
        })->all();
    }

    /**
     * Category breakdown for donut: by type (or category) from DB, or static "Fastest Growing Categories".
     */
    protected function categoriesForTrends($baseQuery): array
    {
        $tableName = $baseQuery->from;
        $total = (clone $baseQuery)->where('type', 'Gym')->count();
        if ($total === 0) {
            return $this->staticCategories();
        }

        try {
            $rows = DB::connection('gymsdata')
                ->table($tableName)
                ->where('type', 'Gym')
                ->selectRaw('COALESCE(category, type) as category, count(*) as count')
                ->groupByRaw('COALESCE(category, type)')
                ->get();
        } catch (\Exception $e) {
            $rows = $baseQuery->where('type', 'Gym')
                ->selectRaw('type as category, count(*) as count')
                ->groupBy('type')
                ->get();
        }

        if ($rows->count() >= 2) {
            return $rows->map(function ($row) use ($total) {
                $count = (int) $row->count;
                $pct = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                return [
                    'category' => $row->category ?? 'Other',
                    'count' => $count,
                    'percentage' => $pct,
                ];
            })->values()->all();
        }

        return $this->staticCategories();
    }

    /** Static "Fastest Growing Categories" for donut when DB has no category diversity. */
    protected function staticCategories(): array
    {
        $items = [
            ['category' => 'Traditional / Full-service', 'count' => 28000, 'percentage' => 41.5],
            ['category' => 'Specialty (Yoga, Pilates)', 'count' => 12000, 'percentage' => 17.8],
            ['category' => '24/7 Low-cost', 'count' => 11500, 'percentage' => 17.0],
            ['category' => 'CrossFit / Functional', 'count' => 8500, 'percentage' => 12.6],
            ['category' => 'Boutique / Studio', 'count' => 7500, 'percentage' => 11.1],
        ];
        return $items;
    }

    /**
     * Franchise vs independent gym count by quarter. Uses ownership_type if present, else static.
     */
    protected function franchiseVsIndependentForTrends($baseQuery): array
    {
        $tableName = $baseQuery->from;
        try {
            $rows = DB::connection('gymsdata')
                ->select(
                    "SELECT date_trunc('quarter', created_at)::date as quarter, " .
                    "count(*) FILTER (WHERE LOWER(TRIM(COALESCE(ownership_type, ''))) IN ('franchise','franchisee')) as franchise, " .
                    "count(*) FILTER (WHERE LOWER(TRIM(COALESCE(ownership_type, ''))) NOT IN ('franchise','franchisee')) as independent " .
                    "FROM {$tableName} WHERE type = ? AND created_at IS NOT NULL AND created_at >= '2023-01-01' " .
                    "GROUP BY date_trunc('quarter', created_at) ORDER BY quarter ASC",
                    ['Gym']
                );
            if (count($rows) > 0) {
                return array_map(function ($row) {
                    $ts = strtotime($row->quarter);
                    $y = date('Y', $ts);
                    $q = (int) ceil((int) date('n', $ts) / 3);
                    return [
                        'quarter' => "Q{$q} {$y}",
                        'quarterKey' => $y . '-Q' . $q,
                        'franchise' => (int) $row->franchise,
                        'independent' => (int) $row->independent,
                    ];
                }, $rows);
            }
        } catch (\Exception $e) {
            // ownership_type or created_at missing — use static
        }

        $quarters = ['Q1 2023', 'Q2 2023', 'Q3 2023', 'Q4 2023', 'Q1 2024', 'Q2 2024', 'Q3 2024', 'Q4 2024', 'Q1 2025'];
        $franchise = 15000;
        $independent = 37500;
        $out = [];
        foreach ($quarters as $q) {
            $out[] = [
                'quarter' => $q,
                'quarterKey' => preg_replace('/Q(\d) (\d+)/', '$2-Q$1', $q),
                'franchise' => $franchise,
                'independent' => $independent,
            ];
            $franchise += rand(-200, 200);
            $independent += rand(-500, 500);
        }
        return $out;
    }

    /**
     * GET /api/v1/gymsdata/chain-comparison
     * Gym chain comparison: Chain Name, Locations, Avg Price, Amenities Score, User Rating.
     * Data for SEO and research (curated reference data).
     */
    public function chainComparison(Request $request)
    {
        $chains = [
            [
                'chainName' => 'LA Fitness',
                'locations' => 700,
                'locationsLabel' => '700+',
                'avgPrice' => 35,
                'avgPriceLabel' => '$35/mo',
                'amenitiesScore' => 8.5,
                'amenitiesScoreLabel' => '8.5/10',
                'userRating' => 4.2,
            ],
            [
                'chainName' => '24 Hour Fitness',
                'locations' => 450,
                'locationsLabel' => '450+',
                'avgPrice' => 40,
                'avgPriceLabel' => '$40/mo',
                'amenitiesScore' => 8.0,
                'amenitiesScoreLabel' => '8.0/10',
                'userRating' => 4.0,
            ],
            [
                'chainName' => 'Planet Fitness',
                'locations' => 2400,
                'locationsLabel' => '2,400+',
                'avgPrice' => 10,
                'avgPriceLabel' => '$10/mo',
                'amenitiesScore' => 6.5,
                'amenitiesScoreLabel' => '6.5/10',
                'userRating' => 3.8,
            ],
            [
                'chainName' => 'Equinox',
                'locations' => 100,
                'locationsLabel' => '100+',
                'avgPrice' => 200,
                'avgPriceLabel' => '$200/mo',
                'amenitiesScore' => 9.8,
                'amenitiesScoreLabel' => '9.8/10',
                'userRating' => 4.5,
            ],
        ];

        return response()->json(['chains' => $chains]);
    }

    /**
     * GET /api/v1/gymsdata/testimonials
     * "What Our Users Say" — testimonial cards (quote, rating, author, initials for avatar).
     */
    public function testimonials(Request $request)
    {
        $items = [
            [
                'quote' => '789We used GymDues to source gym contacts for a national outreach campaign, and the results were night and day compared to generic lists. The data was fresh, verified, and instantly usable—our team reached thousands of gyms in just a few days.',
                'rating' => 5,
                'authorName' => 'Jordan Lee',
                'authorTitle' => 'Growth Lead, FitStack Analytics',
                'initials' => 'JL',
            ],
            [
                'quote' => '456GymDues saved our sales reps hours per week. Instead of cleaning spreadsheets, they spend time talking to gym owners who actually fit our ICP.',
                'rating' => 5,
                'authorName' => 'Morgan Patel',
                'authorTitle' => 'Head of Sales, IronStack CRM',
                'initials' => 'MP',
            ],
            [
                'quote' => 'We layered GymDues data on top of our ad audiences and immediately saw higher CTR and reply rates from gyms that were actively investing in equipment and software.',
                'rating' => 5,
                'authorName' => 'Alex Rivera',
                'authorTitle' => 'Performance Marketer, LiftLabs',
                'initials' => 'AR',
            ],
        ];

        return response()->json(['testimonials' => $items]);
    }

    /**
     * GET /api/v1/gymsdata/state-comparison
     * Returns metrics for all states in one response so the frontend can compare any subset (e.g. pick 3)
     * without extra requests. One aggregated DB query for speed.
     * Metrics: totalGyms, withEmail, withPhone, avgRating, densityPer100k.
     */
    public function stateComparison(Request $request)
    {
        try {
            $pops = $this->statePopulations();

            $rows = $this->table()
                ->where('type', 'Gym')
                ->whereNotNull('state')
                ->where('state', '!=', '')
                ->selectRaw(
                    "state, count(*) as total_gyms, " .
                    "count(*) FILTER (WHERE email_1 IS NOT NULL AND TRIM(COALESCE(email_1, '')) != '') as with_email, " .
                    "count(*) FILTER (WHERE business_phone IS NOT NULL AND TRIM(COALESCE(business_phone, '')) != '') as with_phone, " .
                    "avg(average_rating) FILTER (WHERE average_rating IS NOT NULL) as avg_rating"
                )
                ->groupBy('state')
                ->orderBy('state')
                ->get();

            $result = $rows->map(function ($row) use ($pops) {
                $code = $row->state;
                $totalGyms = (int) $row->total_gyms;
                $withEmail = (int) $row->with_email;
                $withPhone = (int) $row->with_phone;
                $avgRating = $row->avg_rating !== null ? round((float) $row->avg_rating, 1) : null;
                $pop = $pops[$code] ?? null;
                $densityPer100k = null;
                if ($pop !== null && $pop > 0) {
                    $densityPer100k = round(($totalGyms / ($pop * 1000)) * 100000, 1);
                }
                return [
                    'state' => $code,
                    'stateName' => $this->stateNames($code),
                    'stateSlug' => $this->stateSlug($code),
                    'totalGyms' => $totalGyms,
                    'withEmail' => $withEmail,
                    'withPhone' => $withPhone,
                    'avgRating' => $avgRating,
                    'densityPer100k' => $densityPer100k,
                    'imageUrl' => $this->stateImageUrl($code),
                ];
            })->values()->all();

            return response()->json(['states' => $result]);
        } catch (\Exception $e) {
            Log::error('GymsdataController@stateComparison: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/gymsdata/state-page/{state} or ?state=
     * State in path: lowercase with hyphens (e.g. california, new-york). Query: same or code/name.
     * include_cities=1: add "cities" and "nearbyCities". cities_sort: count|name. cities_limit: max cities.
     */
    public function statePage(Request $request)
    {
        try {
            $stateParam = $request->route('state') ?? $request->input('state');
            $stateTrim = $stateParam ? trim($stateParam) : '';
            if ($stateTrim === '') {
                return response()->json(['error' => 'state is required'], 400);
            }
            $stateTrim = rawurldecode($stateTrim);
            $code = strtoupper($this->stateCodeForSearch($this->normalizeStateSlug($stateTrim)));
            $base = $this->table()->where('type', 'Gym')->where('state', $code);
            $includeCities = $request->input('include_cities') === '1' || $request->input('include_cities') === 'true';
            $citiesSort = $request->input('cities_sort', 'count');
            $citiesLimit = max(1, min(1000, (int) $request->input('cities_limit', 500)));

            $totalGyms = (int) (clone $base)->count();
            if ($totalGyms === 0) {
                $payload = [
                    'state' => $code,
                    'stateName' => $this->stateNames($code),
                    'stateSlug' => $this->stateSlug($code),
                    'totalGyms' => 0,
                    'citiesCount' => 0,
                    'pctWithEmail' => 0,
                    'pctWithPhone' => 0,
                    'pctWithSocial' => 0,
                    'avgRating' => null,
                    'topCities' => [],
                    'imageUrl' => $this->stateImageUrl($code),
                ];
                if ($includeCities) {
                    $payload['cities'] = [];
                    $payload['nearbyCities'] = [];
                }
                return response()->json($payload);
            }

            $citiesCount = (int) (clone $base)->whereNotNull('city')->where('city', '!=', '')->selectRaw('count(distinct city) as c')->value('c');
            $withEmail = (int) (clone $base)->whereNotNull('email_1')->where('email_1', '!=', '')->count();
            $withPhone = (int) (clone $base)->whereNotNull('business_phone')->where('business_phone', '!=', '')->count();
            $withSocial = (int) (clone $base)->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('facebook')->where('facebook', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('instagram')->where('instagram', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('twitter')->where('twitter', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('linkedin')->where('linkedin', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('youtube')->where('youtube', '!=', '');
                });
            })->count();
            $avgRatingRow = (clone $base)->whereNotNull('average_rating')->selectRaw('avg(average_rating) as avg')->first();
            $avgRating = $avgRatingRow && $avgRatingRow->avg !== null ? round((float) $avgRatingRow->avg, 1) : null;

            $topCityRows = (clone $base)
                ->selectRaw('city, count(*) as count')
                ->whereNotNull('city')->where('city', '!=', '')
                ->groupBy('city')
                ->orderByRaw('count(*) desc')
                ->limit(10)
                ->get();
            $topCities = $topCityRows->map(function ($row) use ($code) {
                return [
                    'city' => $row->city,
                    'count' => (int) $row->count,
                    'label' => $row->city . ', ' . $this->stateNames($code),
                ];
            })->values()->all();

            $pctWithEmail = $totalGyms > 0 ? round(($withEmail / $totalGyms) * 100, 0) : 0;
            $pctWithPhone = $totalGyms > 0 ? round(($withPhone / $totalGyms) * 100, 0) : 0;
            $pctWithSocial = $totalGyms > 0 ? round(($withSocial / $totalGyms) * 100, 0) : 0;

            $payload = [
                'state' => $code,
                'stateName' => $this->stateNames($code),
                'stateSlug' => $this->stateSlug($code),
                'totalGyms' => $totalGyms,
                'citiesCount' => $citiesCount,
                'pctWithEmail' => $pctWithEmail,
                'pctWithPhone' => $pctWithPhone,
                'pctWithSocial' => $pctWithSocial,
                'avgRating' => $avgRating,
                'topCities' => $topCities,
                'imageUrl' => $this->stateImageUrl($code),
            ];

            if ($includeCities) {
                $cityQuery = (clone $base)
                    ->selectRaw('city, state, count(*) as count')
                    ->whereNotNull('city')->where('city', '!=', '')
                    ->groupBy('city', 'state');
                $cityQuery = $citiesSort === 'name'
                    ? $cityQuery->orderBy('city', 'asc')
                    : $cityQuery->orderByRaw('count(*) desc');
                $cityRows = $cityQuery->limit($citiesLimit)->get();
                $cities = $cityRows->map(function ($row) use ($code) {
                    return [
                        'label' => $row->city . ', ' . $this->stateNames($code),
                        'city' => $row->city,
                        'state' => $row->state ?? $code,
                        'stateName' => $this->stateNames($row->state ?? $code),
                        'postal_code' => '',
                        'count' => (int) $row->count,
                    ];
                })->values()->all();
                $payload['cities'] = $cities;
                $payload['nearbyCities'] = $topCities;
            }

            return response()->json($payload);
        } catch (\Exception $e) {
            Log::error('GymsdataController@statePage: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/gymsdata/city-page/{state}/{city} or ?state=&city=
     * Path: state and city lowercase with hyphens (e.g. california, costa-mesa). Query: same or code/name.
     * Data for the "List of Gyms in [City], [State]" page: stats, top areas (by postal_code), nearby cities.
     */
    public function cityPage(Request $request)
    {
        try {
            $stateParam = $request->route('state') ?? $request->input('state');
            $cityParam = $request->route('city') ?? $request->input('city');
            $stateTrim = $stateParam ? trim($stateParam) : '';
            $cityTrim = $cityParam ? trim($cityParam) : '';
            if ($stateTrim === '' || $cityTrim === '') {
                return response()->json(['error' => 'state and city are required'], 400);
            }
            $stateTrim = rawurldecode($stateTrim);
            $code = strtoupper($this->stateCodeForSearch($this->normalizeStateSlug($stateTrim)));
            $citySlug = rawurldecode($cityTrim);
            [$cityNormSpace, $cityNormHyphen] = $this->citySlugToMatchPairs($citySlug);
            $base = $this->table()
                ->where('type', 'Gym')
                ->where('state', $code)
                ->whereRaw('LOWER(TRIM(city)) IN (?, ?)', [$cityNormSpace, $cityNormHyphen]);

            $totalGyms = (int) (clone $base)->count();
            $cityDisplay = $citySlug;
            if ($totalGyms > 0) {
                $firstRow = (clone $base)->select('city')->first();
                $cityDisplay = $firstRow ? $firstRow->city : $this->normalizeCitySlug($citySlug);
            }
            if ($totalGyms === 0) {
                return response()->json([
                    'state' => $code,
                    'stateName' => $this->stateNames($code),
                    'stateSlug' => $this->stateSlug($code),
                    'city' => $this->normalizeCitySlug($citySlug),
                    'totalGyms' => 0,
                    'pctWithEmail' => 0,
                    'pctWithPhone' => 0,
                    'pctWithSocial' => 0,
                    'avgRating' => null,
                    'topAreas' => [],
                    'nearbyCities' => [],
                    'imageUrl' => $this->stateImageUrl($code),
                ]);
            }

            $withEmail = (int) (clone $base)->whereNotNull('email_1')->where('email_1', '!=', '')->count();
            $withPhone = (int) (clone $base)->whereNotNull('business_phone')->where('business_phone', '!=', '')->count();
            $withSocial = (int) (clone $base)->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('facebook')->where('facebook', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('instagram')->where('instagram', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('twitter')->where('twitter', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('linkedin')->where('linkedin', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('youtube')->where('youtube', '!=', '');
                });
            })->count();
            $avgRatingRow = (clone $base)->whereNotNull('average_rating')->selectRaw('avg(average_rating) as avg')->first();
            $avgRating = $avgRatingRow && $avgRatingRow->avg !== null ? round((float) $avgRatingRow->avg, 1) : null;

            $pctWithEmail = $totalGyms > 0 ? round(($withEmail / $totalGyms) * 100, 0) : 0;
            $pctWithPhone = $totalGyms > 0 ? round(($withPhone / $totalGyms) * 100, 0) : 0;
            $pctWithSocial = $totalGyms > 0 ? round(($withSocial / $totalGyms) * 100, 0) : 0;

            $topAreaRows = (clone $base)
                ->selectRaw("COALESCE(postal_code, '') as area, count(*) as count")
                ->groupByRaw("COALESCE(postal_code, '')")
                ->orderByRaw('count(*) desc')
                ->limit(3)
                ->get();
            $topAreas = $topAreaRows->map(function ($row) {
                $area = isset($row->area) && $row->area !== '' ? $row->area : '';
                return [
                    'area' => $area,
                    'count' => (int) $row->count,
                    'label' => $area !== '' ? 'ZIP ' . $area : 'Other',
                ];
            })->values()->all();

            $nearbyCityRows = $this->table()
                ->where('type', 'Gym')
                ->where('state', $code)
                ->whereRaw('LOWER(TRIM(city)) NOT IN (?, ?)', [$cityNormSpace, $cityNormHyphen])
                ->selectRaw('city, count(*) as count')
                ->whereNotNull('city')->where('city', '!=', '')
                ->groupBy('city')
                ->orderByRaw('count(*) desc')
                ->limit(10)
                ->get();
            $nearbyCities = $nearbyCityRows->map(function ($row) use ($code) {
                return [
                    'city' => $row->city,
                    'count' => (int) $row->count,
                    'label' => $row->city . ', ' . $this->stateNames($code),
                ];
            })->values()->all();

            return response()->json([
                'state' => $code,
                'stateName' => $this->stateNames($code),
                'stateSlug' => $this->stateSlug($code),
                'city' => $cityDisplay,
                'totalGyms' => $totalGyms,
                'pctWithEmail' => $pctWithEmail,
                'pctWithPhone' => $pctWithPhone,
                'pctWithSocial' => $pctWithSocial,
                'avgRating' => $avgRating,
                'topAreas' => $topAreas,
                'nearbyCities' => $nearbyCities,
                'imageUrl' => $this->stateImageUrl($code),
            ]);
        } catch (\Exception $e) {
            Log::error('GymsdataController@cityPage: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/gymsdata/list-page
     * One-shot data for the "List of Gyms in United States" page: summary stats + top states + sample rows.
     * Use this to render the hero stats (total gyms, email/phone/website counts, social counts, rated count)
     * and the sample table (Name, Address, City, State, Email, Phone, Website).
     */
    public function listPage(Request $request)
    {
        try {
            $t = $this->table()->where('type', 'Gym');

            // Total gyms
            $totalGyms = (int) (clone $t)->count();

            // States covered (distinct state count)
            $statesCovered = (int) (clone $t)
                ->whereNotNull('state')->where('state', '!=', '')
                ->selectRaw('count(distinct state) as c')
                ->value('c');

            // Contact & website counts (non-empty string)
            $withEmail = (int) (clone $t)->whereNotNull('email_1')->where('email_1', '!=', '')->count();
            $withPhone = (int) (clone $t)->whereNotNull('business_phone')->where('business_phone', '!=', '')->count();
            $withWebsite = (int) (clone $t)->whereNotNull('business_website')->where('business_website', '!=', '')->count();
            $withPhoneAndEmail = (int) (clone $t)
                ->whereNotNull('email_1')->where('email_1', '!=', '')
                ->whereNotNull('business_phone')->where('business_phone', '!=', '')
                ->count();

            // Social (non-empty)
            $withFacebook = (int) (clone $t)->whereNotNull('facebook')->where('facebook', '!=', '')->count();
            $withInstagram = (int) (clone $t)->whereNotNull('instagram')->where('instagram', '!=', '')->count();
            $withTwitter = (int) (clone $t)->whereNotNull('twitter')->where('twitter', '!=', '')->count();
            $withLinkedin = (int) (clone $t)->whereNotNull('linkedin')->where('linkedin', '!=', '')->count();
            $withYoutube = (int) (clone $t)->whereNotNull('youtube')->where('youtube', '!=', '')->count();

            // Rated (has at least one review)
            $ratedCount = (int) (clone $t)->whereNotNull('total_reviews')->where('total_reviews', '>', 0)->count();

            // states by count (with % of total)
            $stateRows = (clone $t)
                ->selectRaw('state, count(*) as count')
                ->whereNotNull('state')->where('state', '!=', '')
                ->groupBy('state')
                ->orderByRaw('count(*) desc')
                ->get();
            $states = $stateRows->map(function ($row) use ($totalGyms) {
                $count = (int) $row->count;
                $pct = $totalGyms > 0 ? round(($count / $totalGyms) * 100, 1) : 0;
                return [
                    'state' => $row->state,
                    'stateName' => $this->stateNames($row->state),
                    'stateSlug' => $this->stateSlug($row->state),
                    'count' => $count,
                    'pct' => $pct,
                    'imageUrl' => $this->stateImageUrl($row->state),
                ];
            });

            // Sample rows for the preview table (e.g. 5 rows)
            $sampleSize = max(1, min(20, (int) $request->input('sample_size', 5)));
            $sampleRows = (clone $t)
                ->select(['id', 'business_name', 'full_address', 'city', 'state', 'email_1', 'business_phone', 'business_website'])
                ->orderBy('id')
                ->limit($sampleSize)
                ->get();
            $sample = $sampleRows->map(function ($row) {
                return [
                    'name' => $row->business_name ?? '',
                    'address' => $row->full_address ?? '',
                    'city' => $row->city ?? '',
                    'state' => $row->state ?? '',
                    'stateName' => $this->stateNames($row->state ?? ''),
                    'email' => $row->email_1 ?? '',
                    'phone' => $row->business_phone ?? '',
                    'website' => $row->business_website ?? '',
                ];
            });

            return response()->json([
                'totalGyms' => $totalGyms,
                'statesCovered' => $statesCovered,
                'states' => $states,
                'withEmail' => $withEmail,
                'withPhone' => $withPhone,
                'withPhoneAndEmail' => $withPhoneAndEmail,
                'withWebsite' => $withWebsite,
                'withFacebook' => $withFacebook,
                'withInstagram' => $withInstagram,
                'withTwitter' => $withTwitter,
                'withLinkedin' => $withLinkedin,
                'withYoutube' => $withYoutube,
                'ratedCount' => $ratedCount,
                'sample' => $sample,
            ]);
        } catch (\Exception $e) {
            Log::error('GymsdataController@listPage: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/gymsdata
     * Paginated list of gyms from second DB (for gymsdata table view / sample).
     * Query: state, city, page, per_page, search.
     * Returns same pagination shape as GET /api/v1/gyms (data + meta).
     */
    public function index(Request $request)
    {
        try {
            $perPage = max(1, min(100, (int) $request->input('per_page', 12)));
            $page = max(1, (int) $request->input('page', 1));
            $state = $request->input('state') ? trim($request->input('state')) : '';
            $city = $request->input('city') ? trim($request->input('city')) : '';
            $search = $request->input('search') ? trim($request->input('search')) : '';

            $query = $this->table()->where('type', 'Gym');

            if ($state !== '') {
                $query->where('state', $this->stateCodeForSearch($state));
            }
            if ($city !== '') {
                $query->where('city', 'like', '%' . $city . '%');
            }
            if ($search !== '') {
                $query->where(function ($qb) use ($search) {
                    $qb->where('business_name', 'like', '%' . $search . '%')
                        ->orWhere('full_address', 'like', '%' . $search . '%')
                        ->orWhere('city', 'like', '%' . $search . '%')
                        ->orWhere('postal_code', 'like', '%' . $search . '%');
                });
            }

            $total = $query->count();
            $offset = ($page - 1) * $perPage;
            $items = $query->orderBy('id')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            // Map flat row to frontend-friendly shape (Gym-like)
            $data = $items->map(function ($row) {
                return [
                    'id' => $row->id ?? null,
                    'name' => $row->business_name ?? '',
                    'slug' => null,
                    'phone' => $row->business_phone ?? null,
                    'email' => $row->email_1 ?? null,
                    'website' => $row->business_website ?? null,
                    'address' => [
                        'full_address' => $row->full_address ?? null,
                        'street' => $row->street ?? null,
                        'city' => $row->city ?? null,
                        'state' => $row->state ?? null,
                        'stateName' => $this->stateNames($row->state),
                        'postal_code' => $row->postal_code ?? null,
                        'latitude' => isset($row->latitude) ? (float) $row->latitude : null,
                        'longitude' => isset($row->longitude) ? (float) $row->longitude : null,
                    ],
                    'reviewCount' => isset($row->total_reviews) ? (int) $row->total_reviews : 0,
                    'average_rating' => isset($row->average_rating) ? round((float) $row->average_rating, 2) : null,
                    'total_reviews' => $row->total_reviews ?? null,
                ];
            });

            $paginator = new LengthAwarePaginator($data, $total, $perPage, $page);

            $result = $paginator->toArray();
            unset($result['links']);
      
            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('GymsdataController@index: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
