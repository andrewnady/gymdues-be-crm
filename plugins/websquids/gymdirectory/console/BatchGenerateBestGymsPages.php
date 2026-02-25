<?php namespace websquids\Gymdirectory\Console;

use Illuminate\Console\Command;
use websquids\Gymdirectory\Jobs\BatchProcessBestGymsPages;
use websquids\Gymdirectory\Models\Address;

/**
 * Artisan command: gymdirectory:batch-generate-best-gyms-pages
 *
 * Groups all locations into batches and dispatches one BatchProcessBestGymsPages
 * job per batch — resulting in far fewer Gemini API calls than the per-location command.
 *
 * Usage:
 *   php artisan gymdirectory:batch-generate-best-gyms-pages
 *   php artisan gymdirectory:batch-generate-best-gyms-pages --state="Texas"
 *   php artisan gymdirectory:batch-generate-best-gyms-pages --city="Houston" --state="TX"
 *   php artisan gymdirectory:batch-generate-best-gyms-pages --batch-size=30
 *   php artisan gymdirectory:batch-generate-best-gyms-pages --sync
 *   php artisan gymdirectory:batch-generate-best-gyms-pages --force
 *   php artisan gymdirectory:batch-generate-best-gyms-pages --locations=50
 */
class BatchGenerateBestGymsPages extends Command
{
    protected $signature = 'gymdirectory:batch-generate-best-gyms-pages
        {--city=         : Only generate for this city}
        {--state=        : Only generate for this state}
        {--batch-size=20 : Number of locations per Gemini API call (default: 20)}
        {--force         : Overwrite existing records}
        {--sync          : Run jobs inline instead of pushing to the queue}
        {--locations=    : Number of locations}
        {--queue-name=   : Queue name to dispatch jobs onto}';

    protected $description = 'Dispatch Best Gyms page generation in batches — one Gemini API call per batch.';

    public function handle(): int
    {
        $cityFilter    = trim((string) $this->option('city'));
        $stateFilter   = trim((string) $this->option('state'));
        $batchSize     = max(1, (int) $this->option('batch-size'));
        $force         = (bool) $this->option('force');
        $sync          = (bool) $this->option('sync');
        $queueName     = trim((string) $this->option('queue-name'));
        $locationLimit = $this->option('locations') !== null ? max(1, (int) $this->option('locations')) : null;

        $locations = $this->resolveLocations($cityFilter, $stateFilter);

        if ($locations->isEmpty()) {
            $this->warn('No locations found to process.');
            return 0;
        }

        if ($locationLimit !== null) {
            $locations = $locations->take($locationLimit);
            $this->line("Limiting to <info>{$locationLimit}</info> location(s) as requested.");
        }

        $batches = $locations->chunk($batchSize);
        $mode    = $sync ? '<comment>sync</comment>' : '<info>queued</info>';

        $this->info("Found {$locations->count()} location(s) → {$batches->count()} batch(es) of up to {$batchSize} ({$mode}) …");

        $dispatchedBatches = 0;

        foreach ($batches as $chunk) {
            $payload = $chunk->map(fn($loc) => [
                'country' => '',
                'state'   => $loc->state ?? '',
                'city'    => $loc->city  ?? '',
            ])->values()->all();

            $job = new BatchProcessBestGymsPages($payload, $force);

            if ($queueName !== '') {
                $job->onQueue($queueName);
            }

            $sync ? dispatch_sync($job) : dispatch($job);

            $labels = implode(', ', array_map(
                fn($l) => trim(($l['city'] ? $l['city'] . ' ' : '') . $l['state'], ' '),
                $payload
            ));

            $this->line("  batch <info>{$dispatchedBatches}</info>: {$labels}");
            $dispatchedBatches++;
        }

        $this->info("Done. {$dispatchedBatches} batch job(s) dispatched.");
        return 0;
    }

    private function resolveLocations(string $city, string $state)
    {
        $cityStateQuery = Address::selectRaw('DISTINCT city, state')
            ->whereNotNull('city')->where('city', '!=', '')
            ->whereNotNull('state')->where('state', '!=', '');

        if ($state !== '') {
            $cityStateQuery->where('state', $state);
        }
        if ($city !== '') {
            $cityStateQuery->where('city', $city);
        }

        $cityStatePairs = $cityStateQuery->orderBy('state')->orderBy('city')->get();

        if ($city !== '') {
            return $cityStatePairs;
        }

        $stateQuery = Address::selectRaw('DISTINCT state')
            ->whereNotNull('state')->where('state', '!=', '');

        if ($state !== '') {
            $stateQuery->where('state', $state);
        }

        $stateEntries = $stateQuery->orderBy('state')->get()->map(function ($loc) {
            $loc->city = '';
            return $loc;
        });

        return $stateEntries->concat($cityStatePairs);
    }
}
