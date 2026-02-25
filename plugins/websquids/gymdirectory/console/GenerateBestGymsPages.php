<?php namespace websquids\Gymdirectory\Console;

use Illuminate\Console\Command;
use websquids\Gymdirectory\Jobs\ProcessBestGymsPage;
use websquids\Gymdirectory\Models\Address;

/**
 * Artisan command: gymdirectory:generate-best-gyms-pages
 *
 * Resolves all locations to process and dispatches one ProcessBestGymsPage
 * job per location. Jobs run on the configured queue driver (database, Redis,
 * etc.). Use --sync to run all jobs inline in the current process instead.
 *
 * Usage:
 *   php artisan gymdirectory:generate-best-gyms-pages                          # queue all states + all city+state combos
 *   php artisan gymdirectory:generate-best-gyms-pages --state="California"     # queue state-wide page + all cities in that state
 *   php artisan gymdirectory:generate-best-gyms-pages --city="Houston" --state="TX"  # queue single city page only
 *   php artisan gymdirectory:generate-best-gyms-pages --sync                   # run inline (no queue worker needed)
 *   php artisan gymdirectory:generate-best-gyms-pages --force                  # overwrite existing records
 *   php artisan gymdirectory:generate-best-gyms-pages --queue-name=high        # dispatch onto a specific named queue
 */
class GenerateBestGymsPages extends Command
{
    protected $signature = 'gymdirectory:generate-best-gyms-pages
        {--city=        : Only generate for this city}
        {--state=       : Only generate for this state (e.g. California)}
        {--force        : Overwrite existing records}
        {--sync         : Run jobs inline instead of pushing to the queue}
        {--queue-name=  : Queue name to dispatch jobs onto (default: queue config default)}';

    protected $description = 'Dispatch Best Gyms page generation jobs — one per location.';

    public function handle(): int
    {
        $cityFilter  = trim((string) $this->option('city'));
        $stateFilter = trim((string) $this->option('state'));
        $force       = (bool) $this->option('force');
        $sync        = (bool) $this->option('sync');
        $queueName   = trim((string) $this->option('queue-name'));

        $locations = $this->resolveLocations($cityFilter, $stateFilter);

        if ($locations->isEmpty()) {
            $this->warn('No locations found to process.');
            return 0;
        }

        $mode = $sync ? '<comment>sync</comment>' : '<info>queued</info>';
        $this->info("Found {$locations->count()} location(s). Dispatching ({$mode}) …");

        $dispatched = 0;

        foreach ($locations as $loc) {
            $city  = $loc->city  ?? '';
            $state = $loc->state ?? '';

            $job = new ProcessBestGymsPage($city, $state, $force);

            if ($queueName !== '') {
                $job->onQueue($queueName);
            }

            $sync ? dispatch_sync($job) : dispatch($job);

            $label = trim("{$city}, {$state}", ', ');
            $this->line("  dispatched: <info>{$label}</info>");
            $dispatched++;
        }

        $this->info("Done. {$dispatched} job(s) dispatched.");
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
