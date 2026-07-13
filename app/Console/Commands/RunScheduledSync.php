<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CategorySyncController;
use App\Models\CategoryMapping;
use App\Support\CronSettings;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Runs a scheduled synchronisation for every mapped category.
 *
 *   sync:run prices_stock   → re-push each mapped category (refresh price & stock)
 *   sync:run full_catalog   → import the TD SYNNEX catalogue, then push
 *
 * Each category push records its own sync_logs row with triggered_by=scheduler,
 * so the runs appear as "Automatique" in the history / cron page. Use --force to
 * run even when the job is disabled in the cron settings (e.g. the UI "Lancer
 * manuellement" button).
 */
class RunScheduledSync extends Command
{
    protected $signature = 'sync:run {type : prices_stock|full_catalog} {--force : Run even if the job is disabled} {--trigger=scheduler : How the run is recorded in the history (manual|scheduler)}';

    protected $description = 'Run a scheduled TD SYNNEX → PrestaShop synchronisation (prices_stock or full_catalog)';

    public function handle(): int
    {
        @set_time_limit(0);

        $type = (string) $this->argument('type');

        if (! in_array($type, CronSettings::JOBS, true)) {
            $this->error("Type de sync inconnu: {$type}. Attendu: " . implode(', ', CronSettings::JOBS));
            return self::FAILURE;
        }

        if (! $this->option('force') && ! CronSettings::isActive($type)) {
            $this->warn("La tâche '{$type}' est désactivée. Utilisez --force pour l'exécuter quand même.");
            return self::SUCCESS;
        }

        // A run launched by the scheduler is "scheduler"; the "Lancer manuellement"
        // button passes --trigger=manual so it shows as "Manuelle" in the history.
        $trigger = $this->option('trigger') === 'manual' ? 'manual' : 'scheduler';

        $categories = CategoryMapping::query()
            ->where('active', true)
            ->where('ignored', false)
            ->whereNotNull('ps_category_id')
            ->pluck('tds_category')
            ->all();

        if ($categories === []) {
            $this->warn('Aucune catégorie mappée à synchroniser.');
            return self::SUCCESS;
        }

        $syncController = new CategorySyncController();
        $importController = new CategoryController();

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalErrors = 0;

        foreach ($categories as $code) {
            // For a full-catalogue run, refresh the local DB from TD SYNNEX first.
            if ($type === 'full_catalog') {
                try {
                    $this->line("Import TD SYNNEX: {$code}…");
                    $importController->syncTdsynexCategoryProducts($code);
                } catch (\Throwable $e) {
                    Log::error('Scheduled import failed', ['category' => $code, 'error' => $e->getMessage()]);
                    $this->error("  Import échoué pour {$code}: {$e->getMessage()}");
                }
            }

            // Push to PrestaShop. push() records the sync_logs row (trigger=scheduler).
            try {
                $this->line("Push PrestaShop: {$code}…");
                $request = Request::create("/api/categories/{$code}/push", 'POST', [
                    'trigger'   => $trigger,
                    'sync_type' => $type,
                ]);
                $response = $syncController->push($request, $code);
                $data = $response->getData(true);

                $created = (int) ($data['created'] ?? 0);
                $updated = (int) ($data['updated'] ?? 0);
                $errors  = is_array($data['errors'] ?? null) ? count($data['errors']) : 0;

                $totalCreated += $created;
                $totalUpdated += $updated;
                $totalErrors  += $errors;

                $this->info("  {$code}: {$created} créés, {$updated} mis à jour, {$errors} erreurs");
            } catch (\Throwable $e) {
                $totalErrors++;
                Log::error('Scheduled push failed', ['category' => $code, 'error' => $e->getMessage()]);
                $this->error("  Push échoué pour {$code}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Terminé — {$type}: {$totalCreated} créés, {$totalUpdated} mis à jour, {$totalErrors} erreurs sur " . count($categories) . ' catégorie(s).');

        return self::SUCCESS;
    }
}
