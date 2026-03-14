<?php namespace websquids\Gymdirectory\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Websquids\Gymdirectory\Controllers\Api\GymsdataController;

/**
 * Pre-generate all gymsdata export ZIPs (full, by type, by state, by city).
 * Run weekly via cron so purchase emails attach pre-built files instead of generating on demand.
 * Files are stored in storage/app/gymsdata_exports/.
 *
 * Usage:
 *   php artisan gymdirectory:pregenerate-gymsdata-exports
 *   php artisan gymdirectory:pregenerate-gymsdata-exports --full
 *   php artisan gymdirectory:pregenerate-gymsdata-exports --types
 *   php artisan gymdirectory:pregenerate-gymsdata-exports --states
 *   php artisan gymdirectory:pregenerate-gymsdata-exports --cities
 *
 * Cron (weekly, e.g. Sunday 2am):
 *   0 2 * * 0 cd /var/www/your-app && php artisan gymdirectory:pregenerate-gymsdata-exports >> /var/log/gymsdata-exports.log 2>&1
 */
class PreGenerateGymsdataExports extends Command
{
    protected $signature = 'gymdirectory:pregenerate-gymsdata-exports
        {--full : Only full dataset}
        {--types : Only per-type}
        {--states : Only per-state}
        {--cities : Only per-city}';

    protected $description = 'Pre-generate all gymsdata export ZIPs for fast purchase email delivery (run weekly via cron).';

    public function handle(): int
    {
        $controller = app(GymsdataController::class);
        $table = config('database.gymsdata_table', 'gyms_data');
        $conn = DB::connection('gymsdata');

        $fullOnly = (bool) $this->option('full');
        $typesOnly = (bool) $this->option('types');
        $statesOnly = (bool) $this->option('states');
        $citiesOnly = (bool) $this->option('cities');
        $doFull = $fullOnly || (! $typesOnly && ! $statesOnly && ! $citiesOnly);
        $doTypes = $typesOnly || (! $fullOnly && ! $statesOnly && ! $citiesOnly);
        $doStates = $statesOnly || (! $fullOnly && ! $typesOnly && ! $citiesOnly);
        $doCities = $citiesOnly || (! $fullOnly && ! $typesOnly && ! $statesOnly);

        $generated = 0;
        $failed = 0;

        if ($doFull) {
            $this->info('Generating full export...');
            try {
                $controller->buildAndSavePreGeneratedExport(null, null, null);
                $this->line('  OK full');
                $generated++;
            } catch (\Throwable $e) {
                $this->error('  FAIL full: ' . $e->getMessage());
                $failed++;
            }
        }

        if ($doTypes) {
            $types = $conn->table($table)
                ->whereNotNull('type')
                ->where('type', '!=', '')
                ->distinct()
                ->pluck('type');
            $this->info('Generating ' . $types->count() . ' type export(s)...');
            foreach ($types as $type) {
                try {
                    $controller->buildAndSavePreGeneratedExport(null, null, $type);
                    $this->line('  OK type: ' . $type);
                    $generated++;
                } catch (\Throwable $e) {
                    $this->error('  FAIL type ' . $type . ': ' . $e->getMessage());
                    $failed++;
                }
            }
        }

        if ($doStates) {
            $states = $conn->table($table)
                ->whereNotNull('state')
                ->where('state', '!=', '')
                ->distinct()
                ->pluck('state');
            $this->info('Generating ' . $states->count() . ' state export(s)...');
            foreach ($states as $state) {
                try {
                    $controller->buildAndSavePreGeneratedExport($state, null, null);
                    $this->line('  OK state: ' . $state);
                    $generated++;
                } catch (\Throwable $e) {
                    $this->error('  FAIL state ' . $state . ': ' . $e->getMessage());
                    $failed++;
                }
            }
        }

        if ($doCities) {
            $cityRows = $conn->table($table)
                ->select('state', 'city')
                ->whereNotNull('state')
                ->where('state', '!=', '')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->get();
            $this->info('Generating ' . $cityRows->count() . ' city export(s)...');
            foreach ($cityRows as $row) {
                try {
                    $controller->buildAndSavePreGeneratedExport($row->state, $row->city, null);
                    $this->line('  OK city: ' . $row->city . ', ' . $row->state);
                    $generated++;
                } catch (\Throwable $e) {
                    $this->error('  FAIL city ' . $row->city . ',' . $row->state . ': ' . $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Done. Generated: {$generated}, Failed: {$failed}.");

        return $failed > 0 ? 1 : 0;
    }
}
