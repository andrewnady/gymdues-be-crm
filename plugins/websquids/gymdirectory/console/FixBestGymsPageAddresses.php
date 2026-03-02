<?php namespace websquids\Gymdirectory\Console;

use Illuminate\Console\Command;
use websquids\Gymdirectory\Models\Address;
use websquids\Gymdirectory\Models\BestGymsPage;

/**
 * Artisan command: gymdirectory:fix-best-gyms-page-addresses
 *
 * Corrects the address stored for each gym inside gyms_data JSON so that it
 * matches the page's own state/city, fixing records generated before the
 * address filter was applied in BatchProcessBestGymsPages.
 *
 * Usage:
 *   php artisan gymdirectory:fix-best-gyms-page-addresses
 *   php artisan gymdirectory:fix-best-gyms-page-addresses --state=TX
 *   php artisan gymdirectory:fix-best-gyms-page-addresses --state=TX --city=Austin
 *   php artisan gymdirectory:fix-best-gyms-page-addresses --dry-run
 */
class FixBestGymsPageAddresses extends Command
{
    protected $signature = 'gymdirectory:fix-best-gyms-page-addresses
        {--state=  : Only fix records for this state}
        {--city=   : Only fix records for this city}
        {--dry-run : Preview changes without saving to the database}';

    protected $description = 'Correct gym addresses in gyms_data to match each page\'s state/city.';

    public function handle(): int
    {
        $stateFilter = trim((string) $this->option('state'));
        $cityFilter  = trim((string) $this->option('city'));
        $dryRun      = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be saved.');
        }

        $query = BestGymsPage::query();

        if ($stateFilter !== '') {
            $query->where('state', $stateFilter);
        }
        if ($cityFilter !== '') {
            $query->where('city', $cityFilter);
        }

        $pages = $query->get();

        if ($pages->isEmpty()) {
            $this->warn('No BestGymsPage records found.');
            return 0;
        }

        $this->info("Found {$pages->count()} page(s) to process.");

        $updatedPages = 0;
        $updatedGyms  = 0;
        $skippedGyms  = 0;

        foreach ($pages as $page) {
            $state    = (string) ($page->state ?? '');
            $city     = (string) ($page->city  ?? '');
            $gymsData = $page->gyms_data;

            if (empty($gymsData)) {
                $this->line("  Skipping <comment>{$page->slug}</comment> — gyms_data is empty.");
                continue;
            }

            $pageChanged = false;

            foreach ($gymsData as $index => $gymEntry) {
                $gymId = $gymEntry['id'] ?? null;

                if (!$gymId) {
                    continue;
                }

                // Fetch the address that belongs to this gym AND matches the page's state/city.
                $addressQuery = Address::where('gym_id', $gymId);

                if ($state !== '') {
                    $addressQuery->where('state', $state);
                }
                if ($city !== '') {
                    $addressQuery->where('city', $city);
                }

                $correctAddress = $addressQuery->first();

                if (!$correctAddress) {
                    $this->line("  <comment>{$page->slug}</comment> — gym #{$gymId} ({$gymEntry['name']}): no address found for [{$city}, {$state}], skipped.");
                    $skippedGyms++;
                    continue;
                }

                $currentAddressId = $gymEntry['address']['id'] ?? null;

                if ((int) $currentAddressId === $correctAddress->id) {
                    // Already pointing at the correct address.
                    continue;
                }

                $this->line("  <info>{$page->slug}</info> — gym #{$gymId} ({$gymEntry['name']}): address corrected (was #{$currentAddressId} → now #{$correctAddress->id}).");

                $gymsData[$index]['address'] = $correctAddress->makeHidden('gym')->toArray();
                $pageChanged = true;
                $updatedGyms++;
            }

            if ($pageChanged) {
                if (!$dryRun) {
                    $page->gyms_data = $gymsData;
                    $page->save();
                }
                $updatedPages++;
            }
        }

        $this->info("Done. Pages updated: {$updatedPages} | Gyms corrected: {$updatedGyms} | Gyms skipped: {$skippedGyms}.");

        if ($dryRun) {
            $this->warn('Dry-run complete — remove --dry-run to apply changes.');
        }

        return 0;
    }
}
