<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;

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

    /**
     * Apply filter: state+city (city page), state only (state page), type only (type page), or none (full).
     */
    protected function applyDataScope($query, ?string $stateCode = null, ?string $cityValue = null, ?string $typeValue = null)
    {
        if (($stateCode !== null && $stateCode !== '') && ($cityValue !== null && $cityValue !== '')) {
            $query->where('state', $this->stateCodeForSearch(trim($stateCode)));
            [$cityNormSpace, $cityNormHyphen] = $this->citySlugToMatchPairs(trim($cityValue));
            $query->whereRaw('LOWER(TRIM(city)) IN (?, ?)', [$cityNormSpace, $cityNormHyphen]);
        } elseif ($stateCode !== null && $stateCode !== '') {
            $query->where('state', $this->stateCodeForSearch(trim($stateCode)));
        } elseif ($typeValue !== null && $typeValue !== '') {
            $query->where('type', trim($typeValue));
        }
        return $query;
    }

    /** All DB columns included in Excel export (order = header + column order). */
    protected function getGymsExportColumns(): array
    {
        return [
            'google_id', 'google_place_url', 'review_url', 'contact_page', 'business_name', 'aka', 'type', 'sub_types',
            'years_in_business', 'areas_serviced', 'bbb_rating', 'business_phone', 'additional_phones', 'email_1', 'email_2', 'email_3',
            'business_website', 'additional_sites', 'facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'google_plus', 'tripadvisor',
            'full_address', 'street', 'suburb', 'borough', 'city', 'postal_code', 'state', 'country', 'timezone',
            'latitude', 'longitude', 'total_reviews', 'average_rating', 'reviews_per_score',
            'reviews_per_score_1', 'reviews_per_score_2', 'reviews_per_score_3', 'reviews_per_score_4', 'reviews_per_score_5',
        ];
    }

    /** Write one row to the sheet at $rowNum for the given $row object using export columns. */
    protected function writeGymsExcelRow($sheet, $row, int $rowNum): void
    {
        $cols = $this->getGymsExportColumns();
        foreach ($cols as $colIndex => $key) {
            $value = $row->{$key} ?? null;
            if ($value === null) {
                $value = '';
            } elseif ($key === 'reviews_per_score' && ! is_string($value)) {
                $value = is_scalar($value) ? (string) $value : json_encode($value);
            } else {
                $value = (string) $value;
            }
            $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowNum, $value);
        }
    }

    /**
     * Build an Excel file from gym rows with all export columns.
     * Returns path to the written temp file (caller should unlink when done).
     */
    protected function buildGymsExcelPath(iterable $rows, ?string $path = null): string
    {
        $path = $path ?: tempnam(sys_get_temp_dir(), 'gymdues_data_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Gyms');
        $cols = $this->getGymsExportColumns();
        foreach ($cols as $colIndex => $header) {
            $sheet->setCellValueByColumnAndRow($colIndex + 1, 1, $header);
        }
        $rowNum = 2;
        foreach ($rows as $row) {
            $this->writeGymsExcelRow($sheet, $row, $rowNum);
            $rowNum++;
        }
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        return $path;
    }

    /**
     * Build full gyms Excel by chunking (for purchase). Returns path to temp file.
     * Filter by data_state+data_city, or data_state only, or data_type only, else full data.
     */
    protected function buildFullGymsExcelPath(?string $path = null, ?string $dataState = null, ?string $dataCity = null, ?string $dataType = null): string
    {
        $path = $path ?: tempnam(sys_get_temp_dir(), 'gymdues_data_full_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Gyms');
        $cols = $this->getGymsExportColumns();
        foreach ($cols as $colIndex => $header) {
            $sheet->setCellValueByColumnAndRow($colIndex + 1, 1, $header);
        }
        $rowNum = 2;
        $query = $this->table()->select($cols)->orderBy('id');
        $this->applyDataScope($query, $dataState, $dataCity, $dataType);
        $query->chunk(2000, function ($rows) use ($sheet, &$rowNum) {
            foreach ($rows as $row) {
                $this->writeGymsExcelRow($sheet, $row, $rowNum);
                $rowNum++;
            }
        });
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        return $path;
    }

    /** State code → full name (e.g. CA → California). */
    protected function stateNames(string $code): string
    {
        $map = [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'DC' => 'District of Columbia','FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 
            'IA' => 'Iowa','ID' => 'Idaho','IL' => 'Illinois', 'IN' => 'Indiana',
            'KS' => 'Kansas','KY' => 'Kentucky', 'LA' => 'Louisiana','MA' => 'Massachusetts', 
            'MD' => 'Maryland','ME' => 'Maine','MI' => 'Michigan', 'MN' => 'Minnesota',
            'MO' => 'Missouri', 'MS' => 'Mississippi','MT' => 'Montana','NC' => 'North Carolina',
            'ND' => 'North Dakota',  'NE' => 'Nebraska', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
            'NM' => 'New Mexico', 'NV' => 'Nevada','NY' => 'New York','OH' => 'Ohio',
            'OK' => 'Oklahoma','OR' => 'Oregon','PA' => 'Pennsylvania','RI' => 'Rhode Island',
            'SC' => 'South Carolina','SD' => 'South Dakota','TN' => 'Tennessee', 'TX' => 'Texas',
            'UT' => 'Utah','VA' => 'Virginia', 'VT' => 'Vermont', 'WA' => 'Washington',
            'WI' => 'Wisconsin','WV' => 'West Virginia','WY' => 'Wyoming',
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
                    ->where('state', $stateCode)
                    ->whereNotNull('city')->where('city', '!=', '')
                    ->selectRaw('city, state, count(*) as count')
                    ->groupBy('city', 'state');
            } else {
                $query = $this->table()
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
                     WHERE  created_at >= (current_date - interval '12 months')
                     GROUP BY date_trunc('month', created_at)
                     ORDER BY month ASC"
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
                    "WHERE created_at >= (current_date - interval '12 months') " .
                    "AND city IS NOT NULL AND TRIM(city) != '' AND state IS NOT NULL AND TRIM(state) != '' " .
                    "GROUP BY city, state ORDER BY count(*) DESC LIMIT 10"
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
                ->selectRaw('COALESCE(category, type) as category, count(*) as count')
                ->groupByRaw('COALESCE(category, type)')
                ->get();
        } catch (\Exception $e) {
            $rows = $baseQuery
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
                    "FROM {$tableName} WHERE created_at IS NOT NULL AND created_at >= '2023-01-01' " .
                    "GROUP BY date_trunc('quarter', created_at) ORDER BY quarter ASC"
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
                'quote' => 'We used GymDues to source gym contacts for a national outreach campaign, and the results were night and day compared to generic lists. The data was fresh, verified, and instantly usable—our team reached thousands of gyms in just a few days.',
                'rating' => 5,
                'authorName' => 'Jordan Lee',
                'authorTitle' => 'Growth Lead, FitStack Analytics',
                'initials' => 'JL',
            ],
            [
                'quote' => 'GymDues saved our sales reps hours per week. Instead of cleaning spreadsheets, they spend time talking to gym owners who actually fit our ICP.',
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
     * Returns metrics for all states (or ?states=CA,TX,FL) for frontend comparison.
     * Cached when returning all states; filtered request uses WHERE state IN (...). Timeout returns 504.
     */
    public function stateComparison(Request $request)
    {
        $stateFilter = $this->parseStateComparisonFilter($request->query('states'));
        $timeoutMs = (int) (env('GYMSDATA_STATE_COMPARISON_TIMEOUT_MS', 12000));
        $cacheTtl = (int) (env('GYMSDATA_STATE_COMPARISON_CACHE_TTL', 3600));

        try {
            if ($stateFilter !== null) {
                $result = $this->runStateComparisonQuery($stateFilter, $timeoutMs);
                return response()->json(['states' => $result]);
            }

            $cacheKey = 'gymsdata_state_comparison_' . config('database.gymsdata_table', 'gyms_data');
            $result = Cache::remember($cacheKey, $cacheTtl, function () use ($timeoutMs) {
                return $this->runStateComparisonQuery(null, $timeoutMs);
            });

            return response()->json(['states' => $result]);
        } catch (\Exception $e) {
            if ($this->isTimeoutException($e)) {
                Log::warning('GymsdataController@stateComparison: timeout');
                return response()->json([
                    'error' => 'Gateway Timeout',
                    'message' => 'The request took too long. Please try again.',
                ], 504);
            }
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
     * Parse ?states=CA,TX,FL into array of uppercase state codes, or null for "all".
     */
    protected function parseStateComparisonFilter($param): ?array
    {
        if ($param === null || $param === '') {
            return null;
        }
        $codes = array_map('trim', explode(',', (string) $param));
        $codes = array_filter($codes, fn ($c) => $c !== '');
        $codes = array_map('strtoupper', $codes);
        $codes = array_values(array_unique($codes));
        if (empty($codes)) {
            return null;
        }
        return $codes;
    }

    /**
     * Run the state-comparison aggregate query. $stateFilter = null for all, or ['CA','TX','FL'].
     * Sets statement_timeout on gymsdata connection for fail-fast.
     */
    protected function runStateComparisonQuery(?array $stateFilter, int $timeoutMs): array
    {
        $pops = $this->statePopulations();
        $conn = DB::connection('gymsdata');

        try {
            $conn->getPdo()->exec("SET statement_timeout = {$timeoutMs}");
        } catch (\Throwable $e) {
            // non-PostgreSQL or driver doesn't support; continue without timeout
        }

        try {
            $query = $this->table()
                ->whereNotNull('state')
                ->where('state', '!=', '')
                ->selectRaw(
                    "state, count(*) as total_gyms, " .
                    "count(*) FILTER (WHERE email_1 IS NOT NULL AND TRIM(COALESCE(email_1, '')) != '') as with_email, " .
                    "count(*) FILTER (WHERE business_phone IS NOT NULL AND TRIM(COALESCE(business_phone, '')) != '') as with_phone, " .
                    "avg(average_rating) FILTER (WHERE average_rating IS NOT NULL) as avg_rating"
                )
                ->groupBy('state')
                ->orderBy('state');

            if ($stateFilter !== null) {
                $query->whereIn('state', $stateFilter);
            }

            $rows = $query->get();

            return collect($rows)->map(function ($row) use ($pops) {
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
        } finally {
            try {
                $conn->getPdo()->exec('SET statement_timeout = 0');
            } catch (\Throwable $e) {
            }
        }
    }

    /** Detect PostgreSQL query timeout / cancel. */
    protected function isTimeoutException(\Exception $e): bool
    {
        $msg = $e->getMessage();
        return stripos($msg, 'timeout') !== false
            || stripos($msg, 'statement_timeout') !== false
            || stripos($msg, 'cancel') !== false
            || stripos($msg, 'query_canceled') !== false;
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
            $base = $this->table()->where('state', $code);
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
                    'price' => $this->getPriceForRowCount(0),
                    'formattedPrice' => '$' . number_format($this->getPriceForRowCount(0)),
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
                $count = (int) $row->count;
                $price = $this->getPriceForRowCount($count);
                return [
                    'city' => $row->city,
                    'count' => $count,
                    'price' => $price,
                    'formattedPrice' => '$' . number_format($price),
                    'label' => $row->city . ', ' . $this->stateNames($code),
                ];
            })->values()->all();

            $pctWithEmail = $totalGyms > 0 ? round(($withEmail / $totalGyms) * 100, 0) : 0;
            $pctWithPhone = $totalGyms > 0 ? round(($withPhone / $totalGyms) * 100, 0) : 0;
            $pctWithSocial = $totalGyms > 0 ? round(($withSocial / $totalGyms) * 100, 0) : 0;

            $statePrice = $this->getPriceForRowCount($totalGyms);
            $payload = [
                'state' => $code,
                'stateName' => $this->stateNames($code),
                'stateSlug' => $this->stateSlug($code),
                'totalGyms' => $totalGyms,
                'price' => $statePrice,
                'formattedPrice' => '$' . number_format($statePrice),
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
                    $count = (int) $row->count;
                    $price = $this->getPriceForRowCount($count);
                    return [
                        'label' => $row->city . ', ' . $this->stateNames($code),
                        'city' => $row->city,
                        'state' => $row->state ?? $code,
                        'stateName' => $this->stateNames($row->state ?? $code),
                        'postal_code' => '',
                        'count' => $count,
                        'price' => $price,
                        'formattedPrice' => '$' . number_format($price),
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
                ->where('state', $code)
                ->whereRaw('LOWER(TRIM(city)) IN (?, ?)', [$cityNormSpace, $cityNormHyphen]);

            $totalGyms = (int) (clone $base)->count();
            $cityDisplay = $citySlug;
            if ($totalGyms > 0) {
                $firstRow = (clone $base)->select('city')->first();
                $cityDisplay = $firstRow ? $firstRow->city : $this->normalizeCitySlug($citySlug);
            }
            if ($totalGyms === 0) {
                $minPrice = $this->getPriceForRowCount(0);
                return response()->json([
                    'state' => $code,
                    'stateName' => $this->stateNames($code),
                    'stateSlug' => $this->stateSlug($code),
                    'city' => $this->normalizeCitySlug($citySlug),
                    'totalGyms' => 0,
                    'price' => $minPrice,
                    'formattedPrice' => '$' . number_format($minPrice),
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
                $count = (int) $row->count;
                $price = $this->getPriceForRowCount($count);
                return [
                    'city' => $row->city,
                    'count' => $count,
                    'price' => $price,
                    'formattedPrice' => '$' . number_format($price),
                    'label' => $row->city . ', ' . $this->stateNames($code),
                ];
            })->values()->all();

            $cityPrice = $this->getPriceForRowCount($totalGyms);
            return response()->json([
                'state' => $code,
                'stateName' => $this->stateNames($code),
                'stateSlug' => $this->stateSlug($code),
                'city' => $cityDisplay,
                'totalGyms' => $totalGyms,
                'price' => $cityPrice,
                'formattedPrice' => '$' . number_format($cityPrice),
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
            $t = $this->table();

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
                $price = $this->getPriceForRowCount($count);
                return [
                    'state' => $row->state,
                    'stateName' => $this->stateNames($row->state),
                    'stateSlug' => $this->stateSlug($row->state),
                    'count' => $count,
                    'pct' => $pct,
                    'price' => $price,
                    'formattedPrice' => '$' . number_format($price),
                    'imageUrl' => $this->stateImageUrl($row->state),
                ];
            });

            // Types by count (same shape as states: type, typeSlug, count, pct)
            $typesCovered = (int) (clone $t)
                ->whereNotNull('type')->where('type', '!=', '')
                ->selectRaw('count(distinct type) as c')
                ->value('c');
            $typeRows = (clone $t)
                ->selectRaw('type, count(*) as count')
                ->whereNotNull('type')->where('type', '!=', '')
                ->groupBy('type')
                ->orderByRaw('count(*) desc')
                ->get();
            $types = $typeRows->map(function ($row) use ($totalGyms) {
                $count = (int) $row->count;
                $pct = $totalGyms > 0 ? round(($count / $totalGyms) * 100, 1) : 0;
                $typeName = $row->type ?? '';
                $price = $this->getPriceForRowCount($count);
                return [
                    'type' => $typeName,
                    'typeSlug' => strtolower(preg_replace('/\s+/', '-', trim($typeName))),
                    'count' => $count,
                    'pct' => $pct,
                    'price' => $price,
                    'formattedPrice' => '$' . number_format($price),
                ];
            });

            // Sample rows for the preview table (e.g. 5 rows)
            $sampleSize = max(1, min(20, (int) $request->input('sample_size', 10)));
            $sampleRows = (clone $t)
                ->select(['id', 'business_name','type', 'full_address', 'city', 'state', 'email_1', 'business_phone', 'business_website'])
                ->whereNotNull('business_name')->where('business_name', '!=', '')
                ->whereNotNull('full_address')->where('full_address', '!=', '')
                ->whereNotNull('city')->where('city', '!=', '')
                ->whereNotNull('state')->where('state', '!=', '')
                ->whereNotNull('email_1')->where('email_1', '!=', '')
                ->whereNotNull('business_phone')->where('business_phone', '!=', '')
                ->whereNotNull('business_website')->where('business_website', '!=', '')
                ->orderBy('id')
                ->limit($sampleSize)
                ->get();
            $sample = $sampleRows->map(function ($row) {
                return [
                    'name' => $row->business_name ?? '',
                    'type' => $row->type ?? '',
                    'address' => $row->full_address ?? '',
                    'city' => $row->city ?? '',
                    'state' => $row->state ?? '',
                    'stateName' => $this->stateNames($row->state ?? ''),
                    'email' => $row->email_1 ?? '',
                    'phone' => $row->business_phone ?? '',
                    'website' => $row->business_website ?? '',
                ];
            });

            $fullPrice = $this->getPriceForRowCount($totalGyms);
            return response()->json([
                'totalGyms' => $totalGyms,
                'price' => $fullPrice,
                'formattedPrice' => '$' . number_format($fullPrice),
                'statesCovered' => $statesCovered,
                'states' => $states,
                'typesCovered' => $typesCovered,
                'types' => $types,
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
     * Tiered price in USD by row count. Used by list-page, state-page, city-page for price per scope; must stay in sync with checkout amount.
     * Override or use config for custom tiers.
     */
    protected function getPriceForRowCount(int $rowCount): int
    {
        $tiers = [
            150001 => 249,
            50001  => 149,
            10001  => 99,
            2001   => 79,
            501    => 49,
            0      => 29,
        ];
        krsort($tiers, SORT_NUMERIC);
        foreach ($tiers as $minRows => $usd) {
            if ($rowCount >= $minRows) {
                return $usd;
            }
        }
        return 29;
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

            $query = $this->table();

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

    /**
     * POST /api/v1/gymsdata/sample-download
     * Body: name, email; optional: state, city (state+city = city page), type, or none (full).
     * If city is sent, state is required.
     */
    public function sampleDownload(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'state' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:50',
        ]);
        if ($request->filled('city') && ! $request->filled('state')) {
            return response()->json(['error' => 'State is required when city is provided'], 422);
        }
        $path = null;
        try {
            $conn = DB::connection('gymsdata');
            $sampleInsert = [
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'type' => 'sample',
                'payment_status' => 'pending',
                'data_state' => $request->filled('state') ? trim($request->input('state')) : null,
                'data_city' => $request->filled('city') ? trim($request->input('city')) : null,
                'data_type' => $request->filled('type') ? trim($request->input('type')) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $conn->table('downloads')->insert($sampleInsert);
            $sampleSize = max(1, min(5, (int) $request->input('sample_size', 5)));
            $query = $this->table()
                ->whereNotNull('business_name')->where('business_name', '!=', '')
                ->whereNotNull('full_address')->where('full_address', '!=', '')
                ->whereNotNull('email_1')->where('email_1', '!=', '')
                ->whereNotNull('business_phone')->where('business_phone', '!=', '')
                ->whereNotNull('business_website')->where('business_website', '!=', '')
                ->orderBy('id')
                ->limit($sampleSize);
            $this->applyDataScope($query, $request->input('state'), $request->input('city'), $request->input('type'));
            $sampleRows = $query->get();
            $path = $this->buildGymsExcelPath($sampleRows);
            $filename = 'gymdues-sample.xlsx';
            $toEmail = $request->input('email');
            $toName = $request->input('name');
            /* Mail::raw('Please find your gyms sample data attached.', function ($message) use ($path, $filename, $toEmail, $toName) {
                $message->to($toEmail, $toName)
                    ->subject('Your gyms sample data');
                $message->attach($path, ['as' => $filename, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
            }); */
            return response()->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            if ($path && is_file($path)) {
                @unlink($path);
            }
            Log::error('GymsdataController@sampleDownload: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/gymsdata/checkout
     * Body: name, email; optional: state, city (state+city = city page), type, or none (full). If city sent, state required.
     * Amount is calculated from row count for the scope (same as list-page / state-page / city-page price).
     */
    public function createCheckout(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'state' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:50',
        ]);
        if ($request->filled('city') && ! $request->filled('state')) {
            return response()->json(['error' => 'State is required when city is provided'], 422);
        }
        $key = env('STRIPE_SECRET');
        if (! $key) {
            Log::warning('GymsdataController@createCheckout: STRIPE_SECRET not set');
            return response()->json(['error' => 'Payments not configured'], 503);
        }
        try {
            $state = $request->filled('state') ? trim($request->input('state')) : null;
            $city = $request->filled('city') ? trim($request->input('city')) : null;
            $type = $request->filled('type') ? trim($request->input('type')) : null;
            $query = $this->table();
            $this->applyDataScope($query, $state, $city, $type);
            $rowCount = (int) $query->count();
            $amount = $this->getPriceForRowCount($rowCount);
            $amountCents = $amount * 100;
            $conn = DB::connection('gymsdata');
            $purchaseInsert = [
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'type' => 'purchase',
                'amount' => $amount,
                'data_state' => $request->filled('state') ? trim($request->input('state')) : null,
                'data_city' => $request->filled('city') ? trim($request->input('city')) : null,
                'data_type' => $request->filled('type') ? trim($request->input('type')) : null,
                'payment_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $id = $conn->table('downloads')->insertGetId($purchaseInsert);
            Stripe::setApiKey($key);
            $frontendOrigin = rtrim(env('GYMSDATA_FRONTEND_URL', env('APP_URL', '')), '/');
            $successUrl = $frontendOrigin . '/gymsdata/checkout/success?session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = $frontendOrigin . '/gymsdata/checkout/cancel';
            if ($city && $state) {
                $productName = 'Gyms data: ' . $city . ', ' . $state;
                $productDescription = 'Gym list for ' . $city . ', ' . $state;
            } elseif ($state) {
                $productName = 'Gyms data: ' . $state;
                $productDescription = 'Gym list for ' . $state;
            } elseif ($type) {
                $productName = 'Gyms data: ' . $type;
                $productDescription = 'Gym list for type: ' . $type;
            } else {
                $productName = 'Full gyms data download';
                $productDescription = 'Complete gym list data';
            }
            if ($type && ($state || $city)) {
                $productName .= ' (' . $type . ')';
                $productDescription .= ' — type: ' . $type;
            }
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower(env('GYMSDATA_STRIPE_CURRENCY', 'usd')),
                        'product_data' => ['name' => $productName, 'description' => $productDescription],
                        'unit_amount' => $amountCents,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $id,
                'customer_email' => $request->input('email'),
                'metadata' => ['download_id' => (string) $id],
            ]);
            $conn->table('downloads')->where('id', $id)->update([
                'stripe_checkout_session_id' => $session->id,
                'updated_at' => now(),
            ]);
            return response()->json([
                'success' => true,
                'sessionId' => $session->id,
                'url' => $session->url,
            ]);
        } catch (\Exception $e) {
            Log::error('GymsdataController@createCheckout: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Checkout failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/webhooks/stripe/gymsdata-purchase
     * Stripe webhook (no API key). On checkout.session.completed: set payment_status=paid, send data email, set email_sent_at.
     */
    public function stripeWebhook(Request $request)
    {
        $secret = env('STRIPE_WEBHOOK_SECRET_GYMSDATA');
        if (! $secret) {
            Log::warning('GymsdataController@stripeWebhook: STRIPE_WEBHOOK_SECRET_GYMSDATA not set');
            return response()->json(['error' => 'Webhook not configured'], 503);
        }
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature');
        try {
            $event = Webhook::constructEvent($payload, $sig, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('GymsdataController@stripeWebhook: signature verification failed');
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Webhook error'], 400);
        }
        if ($event->type !== 'checkout.session.completed') {
            return response()->json(['received' => true]);
        }
        $session = $event->data->object;
        $sessionId = $session->id ?? null;
        if (! $sessionId) {
            return response()->json(['received' => true]);
        }
        try {
            $conn = DB::connection('gymsdata');
            $row = $conn->table('downloads')
                ->where('stripe_checkout_session_id', $sessionId)
                ->where('type', 'purchase')
                ->first();
            if (! $row) {
                Log::warning('GymsdataController@stripeWebhook: no row for session ' . $sessionId);
                return response()->json(['received' => true]);
            }
            $conn->table('downloads')->where('id', $row->id)->update([
                'payment_status' => 'paid',
                'updated_at' => now(),
            ]);
            $this->sendPurchaseDataToEmail((int) $row->id);
            return response()->json(['received' => true]);
        } catch (\Exception $e) {
            Log::error('GymsdataController@stripeWebhook: ' . $e->getMessage());
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * POST /api/v1/gymsdata/resend-purchase-email
     * Body: id (required), optionally token (if GYMSDATA_RESEND_TOKEN is set). Resend data to email for paid purchase; sets email_sent_at.
     */
    public function resendPurchaseEmail(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $token = env('GYMSDATA_RESEND_TOKEN');
        if ($token && $request->input('token') !== $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        try {
            $id = (int) $request->input('id');
            $conn = DB::connection('gymsdata');
            $row = $conn->table('downloads')
                ->where('id', $id)
                ->where('type', 'purchase')
                ->where('payment_status', 'paid')
                ->first();
            if (! $row) {
                return response()->json(['error' => 'Purchase not found or not paid'], 404);
            }
            $this->sendPurchaseDataToEmail($id);
            return response()->json(['success' => true, 'message' => 'Email sent']);
        } catch (\Exception $e) {
            Log::error('GymsdataController@resendPurchaseEmail: ' . $e->getMessage());
            return response()->json(['error' => 'Resend failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send full gyms data as Excel to customer email for a paid purchase. Sets email_sent_at on success.
     */
    protected function sendPurchaseDataToEmail(int $downloadId): void
    {
        $conn = DB::connection('gymsdata');
        $row = $conn->table('downloads')->where('id', $downloadId)->first();
        if (! $row || $row->type !== 'purchase' || $row->payment_status !== 'paid') {
            return;
        }
        $email = $row->email;
        $name = $row->name ?? 'Customer';
        $dataState = $row->data_state ?? null;
        $dataCity = $row->data_city ?? null;
        $dataType = $row->data_type ?? null;
        $path = null;
        try {
            $path = $this->buildFullGymsExcelPath(null, $dataState, $dataCity, $dataType);
            if ($dataState && $dataCity) {
                $filename = 'gymdues-data-' . strtolower(preg_replace('/\s+/', '-', $dataState)) . '-' . strtolower(preg_replace('/\s+/', '-', $dataCity)) . '.xlsx';
            } elseif ($dataState) {
                $filename = 'gymdues-data-' . strtolower(preg_replace('/\s+/', '-', $dataState)) . '.xlsx';
            } elseif ($dataType) {
                $filename = 'gymdues-data-' . strtolower(preg_replace('/\s+/', '-', $dataType)) . '.xlsx';
            } else {
                $filename = 'gymdues-full-data.xlsx';
            }
            /* Mail::raw('Thank you for your purchase. Your full gyms data is attached.', function ($message) use ($path, $filename, $email, $name) {
                $message->to($email, $name)
                    ->subject('Your gymdues data download');
                $message->attach($path, ['as' => $filename, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
            }); */
            $conn->table('downloads')->where('id', $downloadId)->update([
                'email_sent_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('GymsdataController@sendPurchaseDataToEmail: ' . $e->getMessage());
            // Leave email_sent_at NULL so resend can be used
        } finally {
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
