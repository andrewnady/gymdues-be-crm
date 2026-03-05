<?php namespace websquids\Gymdirectory\Console;

use DB;
use Illuminate\Console\Command;

/**
 * Artisan command: gymdirectory:import-gyms-csv
 *
 * Reads a CSV file of US gym data and imports it into the normalized tables:
 *   - websquids_gymdirectory_gyms_normalized      (one row per unique gym)
 *   - websquids_gymdirectory_addresses_normalized  (one row per address, FK → gym)
 *
 * ══ De-duplication strategy ════════════════════════════════════════════════
 *
 * Chain gyms appear in many forms:
 *   "180 Fitness - Payson"                  → separator-based suffix
 *   "Anytime Fitness Chico"                  → city appended as suffix
 *   "Anytime Fitness Durango @ Three Springs" → @ separator
 *   "barre3 Hill Country Galleria"           → mall/neighborhood suffix
 *   "AIM Fitness" vs "Aim Fitness"           → case variation
 *   "ANF Fitness/Amped Fitness Enfield"      → city suffix on slash-brand
 *
 * These are resolved using a TWO-PASS algorithm:
 *
 *   Pass 1 — Build prefix map (fast scan, no DB writes):
 *     For every CSV row compute a normalizedKey via extractGymKey().
 *     Then, for each multi-token key, walk from shortest to longest prefix:
 *     if a shorter key already exists in the key-set, this row's key is
 *     an alias for that shorter key.
 *     This handles "barre3 hill country galleria" → "barre3" even when
 *     no city/separator can strip the suffix.
 *
 *   Pass 2 — Import rows using prefix map:
 *     normalizedKey  = extractGymKey(name, city, state)
 *     canonicalKey   = prefixMap[normalizedKey] ?? normalizedKey
 *     → look up / create gym under canonicalKey
 *
 * extractGymKey() pre-processing (applied in both passes):
 *   1. Strip location suffix after separators: " - ", " – ", " | ", " @ ", " / "
 *   2. Strip trailing city name (suffix match against CSV city column)
 *   3. Strip trailing state abbreviation / name
 *   4. Lowercase, remove apostrophes/hyphens/punctuation, collapse spaces
 *
 * Usage:
 *   php artisan gymdirectory:import-gyms-csv --file="/path/to/file.csv"
 *   php artisan gymdirectory:import-gyms-csv --file="..." --chunk=500 --fresh
 */
class ImportGymsCsv extends Command
{
    protected $signature = 'gymdirectory:import-gyms-csv
        {--file=     : Absolute path to the CSV file (required)}
        {--chunk=500 : Number of address rows to insert per DB transaction}
        {--fresh     : Truncate normalized tables before importing}';

    protected $description = 'Import and normalize gym data from a CSV file into gyms_normalized + addresses_normalized tables.';

    /**
     * Single-token or short-phrase keys that are too generic to serve as prefix anchors.
     * These would otherwise absorb completely unrelated gyms that happen to start with
     * the same common English word.
     *
     * Examples of prevented false merges:
     *   "fitness" (7 chars)  → would absorb "Fitness Studio", "Fitness First", "FitnessSF", …
     *   "anytime" (7 chars)  → "AnyTime" appears standalone but "Anytime Fitness" is the brand
     *   "workout" (7 chars)  → would absorb "Workout Anytime", "Workout World", …
     *   "health"  (6 chars)  → would absorb "Health Works", "Healthplex", …
     *
     * Legitimate single-word brand names (crossfit, orangetheory, barre3, curves, crunch, etc.)
     * are NOT in this list and will still function as valid prefix anchors.
     */
    private const GENERIC_ANCHOR_EXCLUSIONS = [
        'anytime', 'fitness', 'workout', 'health', 'wellness', 'sport', 'sports',
        'gym', 'studio', 'club', 'center', 'training', 'boxing', 'yoga', 'pilates',
        'dance', 'athletic', 'athletics', 'active', 'body', 'strong', 'shape',
        'power', 'motion', 'energy', 'life', 'fit',
    ];

    /** canonicalKey → gym_id */
    private array $gymMap = [];

    /** gym_id → true  (tracks which gyms already have their primary address set) */
    private array $primarySet = [];

    /** slug → count (for unique slug generation) */
    private array $slugCount = [];

    // ── Entry point ──────────────────────────────────────────────────────────

    public function handle(): int
    {
        $file = trim((string) $this->option('file'));

        if (!$file || !file_exists($file)) {
            $this->error('Please provide a valid --file path.');
            return 1;
        }

        if ($this->option('fresh')) {
            $this->warn('Truncating normalized tables…');
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table('websquids_gymdirectory_addresses_normalized')->truncate();
            DB::table('websquids_gymdirectory_gyms_normalized')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->info('Tables truncated.');
        }

        // ── Pass 1: build the prefix map ──────────────────────────────────
        $this->info('Pass 1: scanning names to detect chain gym patterns…');
        [$allEntries, $prefixMap] = $this->buildPrefixMap($file);
        $this->info('  Unique base keys : ' . count($allEntries));
        $this->info('  Chain mappings   : ' . count($prefixMap));

        // Pre-load existing rows from DB (makes the command safely resumable)
        $this->preloadGymMap();

        // ── Pass 2: import rows ───────────────────────────────────────────
        $handle = fopen($file, 'r');
        if (!$handle) {
            $this->error("Cannot open file: {$file}");
            return 1;
        }

        $rawHeaders = fgetcsv($handle);
        if (!$rawHeaders) {
            $this->error('CSV file appears to be empty.');
            fclose($handle);
            return 1;
        }
        $headers = array_map('trim', $rawHeaders);
        $this->info('Pass 2: importing rows…');

        $chunkSize     = max(1, (int) $this->option('chunk'));
        $addressBuffer = [];
        $rowNum        = 0;
        $gymsCreated   = 0;
        $addrsInserted = 0;
        $skipped       = 0;
        $now           = now()->toDateTimeString();

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            if (count($row) !== count($headers)) {
                $this->warn("Row {$rowNum}: column count mismatch — skipping.");
                $skipped++;
                continue;
            }

            $data = array_combine($headers, $row);

            $businessName = trim($data['business_name'] ?? '');
            if ($businessName === '') {
                $skipped++;
                continue;
            }

            $city  = trim($data['city']  ?? '');
            $state = trim($data['state'] ?? '');

            // Resolve to canonical key via prefix map
            $rawKey      = $this->extractGymKey($businessName, $city, $state);
            $canonicalKey = $prefixMap[$rawKey] ?? $rawKey;

            if (!isset($this->gymMap[$canonicalKey])) {
                // Use the pre-computed display name (clean base name, original casing)
                $baseName = $allEntries[$canonicalKey]
                    ?? $this->extractBaseName($businessName, $city, $state);
                $gymId = $this->insertGym($data, $baseName, $now);
                $this->gymMap[$canonicalKey] = $gymId;
                $gymsCreated++;
            }

            $gymId = $this->gymMap[$canonicalKey];

            $isPrimary = !isset($this->primarySet[$gymId]);
            if ($isPrimary) {
                $this->primarySet[$gymId] = true;
            }

            $addressBuffer[] = [
                'gym_id'            => $gymId,
                'is_primary'        => $isPrimary ? 1 : 0,
                'google_id'         => $this->nullable($data['google_id'] ?? ''),
                'category'          => $this->nullable($data['type'] ?? ''),
                'sub_category'      => $this->nullable($data['sub_types'] ?? ''),
                'full_address'      => $this->nullable($data['full_address'] ?? ''),
                'borough'           => $this->nullable($data['borough'] ?? ''),
                'street'            => $this->nullable($data['street'] ?? ''),
                'city'              => $this->nullable($city),
                'postal_code'       => $this->nullable($data['postal_code'] ?? ''),
                'state'             => $this->nullable($state),
                'country'           => $this->nullable($data['country'] ?? ''),
                'timezone'          => $this->nullable($data['timezone'] ?? ''),
                'latitude'          => $this->nullableDecimal($data['latitude'] ?? ''),
                'longitude'         => $this->nullableDecimal($data['longitude'] ?? ''),
                'google_review_url' => $this->nullable($data['review_url'] ?? ''),
                'total_reviews'     => $this->nullableInt($data['total_reviews'] ?? ''),
                'average_rating'    => $this->nullableDecimal($data['average_rating'] ?? ''),
                'reviews_per_score' => $this->parseReviewsPerScore(
                    $data['reviews_per_score'] ?? '',
                    (int) ($data['reviews_per_score_1'] ?? 0),
                    (int) ($data['reviews_per_score_2'] ?? 0),
                    (int) ($data['reviews_per_score_3'] ?? 0),
                    (int) ($data['reviews_per_score_4'] ?? 0),
                    (int) ($data['reviews_per_score_5'] ?? 0)
                ),
                'created_at'        => $now,
                'updated_at'        => $now,
            ];

            if (count($addressBuffer) >= $chunkSize) {
                DB::table('websquids_gymdirectory_addresses_normalized')->insert($addressBuffer);
                $addrsInserted += count($addressBuffer);
                $addressBuffer  = [];
                $this->line("  rows: {$rowNum} | gyms: {$gymsCreated} | addresses: {$addrsInserted}");
            }
        }

        if (!empty($addressBuffer)) {
            DB::table('websquids_gymdirectory_addresses_normalized')->insert($addressBuffer);
            $addrsInserted += count($addressBuffer);
        }

        fclose($handle);

        $this->info('Import complete.');
        $this->info("  Total rows processed : {$rowNum}");
        $this->info("  Gyms created         : {$gymsCreated}");
        $this->info("  Addresses inserted   : {$addrsInserted}");
        $this->info("  Rows skipped         : {$skipped}");

        return 0;
    }

    // ── Pass 1 ───────────────────────────────────────────────────────────────

    /**
     * Scan the CSV once and return:
     *   $allEntries[normalizedKey] = displayName  (first occurrence, stripped, original casing)
     *   $prefixMap[longKey]        = canonicalKey  (for chain gym patterns)
     *
     * Prefix map logic:
     *   For each key with ≥ 2 tokens, walk from the shortest possible prefix upward.
     *   If a shorter key exists in $allEntries, map the current key to that shorter key.
     *   This resolves patterns like "barre3 hill country galleria" → "barre3" even when
     *   no separator or city/state column can strip the location suffix.
     *
     *   Minimum prefix validity to avoid false positives:
     *     - ≥ 2 tokens:  "anytime fitness" is a safe anchor
     *     - 1 token AND ≥ 6 non-space chars: allows single-word brands like "barre3"
     *       but prevents short abbreviations like "la", "fit", "aim" from absorbing
     *       unrelated gyms.
     */
    private function buildPrefixMap(string $file): array
    {
        $handle  = fopen($file, 'r');
        $headers = array_map('trim', fgetcsv($handle));

        $allEntries = []; // normalizedKey → displayName

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($headers)) {
                continue;
            }
            $data         = array_combine($headers, $row);
            $businessName = trim($data['business_name'] ?? '');
            if ($businessName === '') {
                continue;
            }

            $city  = trim($data['city']  ?? '');
            $state = trim($data['state'] ?? '');

            $key         = $this->extractGymKey($businessName, $city, $state);
            $displayName = $this->extractBaseName($businessName, $city, $state);

            // First occurrence of each key wins as the canonical display name
            if (!isset($allEntries[$key])) {
                $allEntries[$key] = $displayName;
            }
        }

        fclose($handle);

        // Build prefix map
        $prefixMap = [];

        foreach (array_keys($allEntries) as $key) {
            $tokens = explode(' ', $key);
            $numTokens = count($tokens);

            if ($numTokens <= 1) {
                continue; // single-token key is already minimal
            }

            // Walk from shortest to longest prefix — take the first valid match
            for ($i = 1; $i < $numTokens; $i++) {
                $prefix = implode(' ', array_slice($tokens, 0, $i));

                if (!isset($allEntries[$prefix])) {
                    continue;
                }

                // Skip prefix if it is a generic English word that would cause false merges
                if (in_array($prefix, self::GENERIC_ANCHOR_EXCLUSIONS, true)) {
                    continue;
                }

                // Validate the prefix is "meaningful" enough to prevent false merges
                $nonSpaceLen = strlen(str_replace(' ', '', $prefix));
                if ($i >= 2 || $nonSpaceLen >= 6) {
                    $prefixMap[$key] = $prefix;
                    break; // most-reduced form found
                }
            }
        }

        // Resolve transitive chains: A → B → C becomes A → C
        // e.g. "barre3 hill country galleria" → "barre3 hill" → "barre3"
        foreach ($prefixMap as $key => $target) {
            $visited = [$key => true];
            while (isset($prefixMap[$target]) && !isset($visited[$target])) {
                $visited[$target] = true;
                $target = $prefixMap[$target];
            }
            $prefixMap[$key] = $target;
        }

        return [$allEntries, $prefixMap];
    }

    // ── DB helpers ───────────────────────────────────────────────────────────

    /**
     * Pre-load existing gyms so the command is safely resumable.
     * Existing gym names are already stripped/canonical, so extractGymKey
     * with empty city/state is sufficient to rebuild the lookup key.
     */
    private function preloadGymMap(): void
    {
        DB::table('websquids_gymdirectory_gyms_normalized')
            ->select('id', 'name')
            ->orderBy('id')
            ->chunk(5000, function ($rows) {
                foreach ($rows as $row) {
                    $key = $this->extractGymKey(trim($row->name), '', '');
                    if (!isset($this->gymMap[$key])) {
                        $this->gymMap[$key] = $row->id;
                    }
                }
            });

        if (!empty($this->gymMap)) {
            $this->info('Pre-loaded ' . count($this->gymMap) . ' existing gym(s) from DB.');
        }

        // Mark gyms that already have a primary address (so we don't assign a second one)
        DB::table('websquids_gymdirectory_addresses_normalized')
            ->select('gym_id')
            ->where('is_primary', 1)
            ->orderBy('gym_id')
            ->chunk(5000, function ($rows) {
                foreach ($rows as $row) {
                    $this->primarySet[$row->gym_id] = true;
                }
            });

        // Pre-load slugs to ensure uniqueness on resume
        DB::table('websquids_gymdirectory_gyms_normalized')
            ->select('slug')
            ->whereNotNull('slug')
            ->orderBy('slug')
            ->chunk(5000, function ($rows) {
                foreach ($rows as $row) {
                    $this->slugCount[$row->slug] = ($this->slugCount[$row->slug] ?? 0) + 1;
                }
            });
    }

    /**
     * Insert a new gym row and return its auto-increment ID.
     * $baseName is the clean display name (location-stripped, original casing).
     */
    private function insertGym(array $data, string $baseName, string $now): int
    {
        return DB::table('websquids_gymdirectory_gyms_normalized')->insertGetId([
            'slug'               => $this->generateUniqueSlug($baseName),
            'name'               => $baseName,
            'description'        => null,
            'trending'           => 0,
            'google_place_url'   => $this->nullable($data['google_place_url'] ?? ''),
            'business_name'      => $baseName,
            'website_built_with' => null,
            'website_title'      => null,
            'website_desc'       => null,
            'is_popular'         => 0,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    // ── Name normalization ────────────────────────────────────────────────────

    /**
     * Derive the dedup/lookup key from a business name + location columns.
     *
     * Step 1 – strip location suffix after common separators:
     *   "180 Fitness - Payson"                  → "180 Fitness"
     *   "Anytime Fitness Durango @ Three Springs" → "Anytime Fitness Durango"
     *
     * Step 2 – strip trailing city name (suffix match, word-boundary):
     *   "Anytime Fitness Chico"   (city=Chico)       → "Anytime Fitness"
     *   "Anytime Fitness Durango" (city=Durango)      → "Anytime Fitness"
     *   "All Hours Fitness DeQuincy" (city=DeQuincy)  → "All Hours Fitness"
     *
     * Step 3 – strip trailing state name / abbreviation:
     *   "Planet Fitness Texas" (state=Texas) → "Planet Fitness"
     *
     * Step 4 – normalize for comparison:
     *   Lowercase, remove apostrophes/hyphens/punctuation, collapse spaces.
     *   "Jiu-Jitsu" == "Jiu Jitsu", "Gold's" == "Golds", "AIM" == "aim"
     */
    private function extractGymKey(string $name, string $city, string $state): string
    {
        $base = $this->extractBaseName($name, $city, $state);

        $key = mb_strtolower($base);
        $key = preg_replace("/['\"\-]/", '', $key);           // remove apostrophes, quotes, hyphens
        $key = preg_replace('/[^a-z0-9\s]/u', ' ', $key);    // replace remaining punctuation with space
        $key = preg_replace('/\s+/', ' ', $key);
        $key = trim($key);

        return $key !== '' ? $key : mb_strtolower(trim($name));
    }

    /**
     * Strip location suffixes and noise from a business name, preserving original casing.
     * The result is stored as the gym's display name in the database.
     *
     * Patterns handled (derived from full scan of the 60k-row dataset):
     *
     *   Step 1 — Business legal suffixes (not part of brand):
     *     "247 Fitness & Sports Training, LLC"  → "247 Fitness & Sports Training"
     *     "Absolute Fitness LLC"                → "Absolute Fitness"
     *
     *   Step 2 — Space-delimited separators followed by location descriptor:
     *     "180 Fitness - Payson"                 (2,526 names with ' - ')
     *     "Arsen's Gym | 24/7 Orange Ct"         (183 names with ' | ')
     *     "CrossFit PCR / Ellicott City"         (82 names with ' / ')
     *     "Anytime Fitness Durango @ Three Springs" (58 names with ' @ ')
     *     "Rock Spot Climbing: Boston-Dedham"    (':' colon separator)
     *
     *   Step 3 — Trailing "(City)" in parentheses:
     *     "Answer Is Fitness (Canton)"   city=Canton → "Answer Is Fitness"
     *     "Atilis Gym (Wildwood)"        city=Wildwood → "Atilis Gym"
     *
     *   Step 4 — Trailing ", City" comma-separated location:
     *     "Atilis Gym, Wildwood"                 city=Wildwood → "Atilis Gym"
     *     "Bayhealth Lifestyles Fitness Center, Dover" city=Dover → "Bayhealth Lifestyles..."
     *
     *   Step 5 — Trailing city name as plain suffix:
     *     "Anytime Fitness Chico"        city=Chico  → "Anytime Fitness"
     *     "All Hours Fitness DeQuincy"   city=DeQuincy → "All Hours Fitness"
     *
     *   Step 6 — Trailing state name or abbreviation:
     *     "Planet Fitness Texas"         state=Texas → "Planet Fitness"
     *
     * NOT handled here (handled by prefix map in Pass 1):
     *     "barre3 Hill Country Galleria" → no separator, city mismatch → prefix map finds "barre3"
     *     "Anytime Fitness The Woodlands" → handled via city strip (city="The Woodlands")
     */
    private function extractBaseName(string $name, string $city, string $state): string
    {
        $base = trim($name);

        // Step 1: strip business legal suffixes
        $base = preg_replace('/,?\s*\b(LLC|L\.L\.C\.?|Inc\.?|Corp\.?|Ltd\.?|L\.P\.?|Co\.)\s*$/i', '', $base);
        $base = trim($base);

        // Step 2: strip everything after space-delimited separators
        // Covers: ' - '  ' – '  ' — '  ' | '  ' @ '  ' / '  ' : ' (colon with space)
        // Also handles colon without leading space: "Name: Location"
        $base = preg_replace('/\s+[-–—|@\/]\s+.+$/u', '', $base);   // space + char + space
        $base = preg_replace('/\s*:\s+[A-Z].+$/u', '', $base);        // colon + capital letter
        $base = trim($base);

        // Step 3: strip trailing "(City)" or "(State)" in parentheses
        foreach ([$city, $state] as $loc) {
            if ($loc === '') continue;
            $esc  = preg_quote(trim($loc), '/');
            $base = preg_replace('/\s*\(\s*' . $esc . '\s*\)\s*$/iu', '', $base);
            $base = trim($base);
        }

        // Step 4: strip trailing ", City" or ", State" (comma-separated location)
        foreach ([$city, $state] as $loc) {
            if ($loc === '') continue;
            $esc  = preg_quote(trim($loc), '/');
            $base = preg_replace('/,\s*' . $esc . '\s*$/iu', '', $base);
            $base = trim($base);
        }

        // Step 5: strip trailing city name as plain suffix (word-boundary)
        if ($city !== '') {
            $esc  = preg_quote(trim($city), '/');
            $base = preg_replace('/\s+' . $esc . '\s*$/iu', '', $base);
            $base = trim($base);
        }

        // Step 6: strip trailing state abbreviation or full state name
        if ($state !== '') {
            $esc  = preg_quote(trim($state), '/');
            $base = preg_replace('/\s+' . $esc . '\s*$/iu', '', $base);
            $base = trim($base);
        }

        return $base !== '' ? $base : trim($name);
    }

    /**
     * Generate a URL-safe slug that is unique within this import run.
     *   "Gold's Gym"   → "golds-gym"
     *   (2nd instance) → "golds-gym-2"
     */
    private function generateUniqueSlug(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 190);

        if (!isset($this->slugCount[$slug])) {
            $this->slugCount[$slug] = 1;
            return $slug;
        }

        do {
            $this->slugCount[$slug]++;
            $candidate = $slug . '-' . $this->slugCount[$slug];
        } while (isset($this->slugCount[$candidate]));

        $this->slugCount[$candidate] = 1;
        return $candidate;
    }

    // ── Data parsing helpers ─────────────────────────────────────────────────

    /**
     * Parse reviews_per_score.
     * Prefers individual score columns; falls back to parsing the raw JSON-like string.
     * CSV format example: "{1: 0, 2: 0, 3: 0, 4: 0, 5: 3}"
     */
    private function parseReviewsPerScore(
        string $raw,
        int $s1, int $s2, int $s3, int $s4, int $s5
    ): ?string {
        if ($s1 + $s2 + $s3 + $s4 + $s5 > 0) {
            return json_encode(['1' => $s1, '2' => $s2, '3' => $s3, '4' => $s4, '5' => $s5]);
        }

        if ($raw === '') {
            return null;
        }

        // Convert "{1: 0, 2: 0}" → valid JSON {"1": 0, "2": 0}
        $json    = preg_replace('/(\d+)\s*:/', '"$1":', $raw);
        $decoded = json_decode($json, true);

        return $decoded !== null ? json_encode($decoded) : null;
    }

    private function nullable(string $val): ?string
    {
        $val = trim($val);
        return $val !== '' ? $val : null;
    }

    private function nullableDecimal(string $val): ?float
    {
        $val = trim($val);
        return is_numeric($val) ? (float) $val : null;
    }

    private function nullableInt(string $val): ?int
    {
        $val = trim($val);
        return is_numeric($val) ? (int) $val : null;
    }
}
