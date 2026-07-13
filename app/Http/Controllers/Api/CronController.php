<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Support\CronSettings;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class CronController extends Controller
{
    /**
     * Return the cron configuration for both scheduled jobs, with their next run
     * time, average duration and recent automatic runs (from sync_logs).
     */
    public function index(): JsonResponse
    {
        $config = CronSettings::all();
        $jobs = [];

        foreach (CronSettings::JOBS as $key) {
            $jobs[$key] = $this->describeJob($key, $config[$key]);
        }

        return response()->json([
            'jobs'     => $jobs,
            'advanced' => $config['advanced'] ?? [],
            // The Laravel scheduler only fires if the OS runs `php artisan schedule:run`
            // every minute. Surface that so the UI can warn when it is not set up.
            'scheduler_hint' => 'Le déclenchement automatique nécessite la tâche système : * * * * * php artisan schedule:run',
        ]);
    }

    /**
     * Persist a (partial) cron configuration update. Validates cron expressions.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->all();

        // Validate any cron expressions provided.
        foreach (CronSettings::JOBS as $key) {
            $cron = $data[$key]['cron'] ?? null;
            if ($cron !== null && ! CronExpression::isValidExpression((string) $cron)) {
                return response()->json([
                    'success' => false,
                    'message' => "Expression cron invalide pour « {$key} » : {$cron}",
                ], 422);
            }
        }

        $config = CronSettings::update($data);
        $jobs = [];
        foreach (CronSettings::JOBS as $key) {
            $jobs[$key] = $this->describeJob($key, $config[$key]);
        }

        return response()->json([
            'success'  => true,
            'message'  => 'Configuration enregistrée.',
            'jobs'     => $jobs,
            'advanced' => $config['advanced'] ?? [],
        ]);
    }

    /**
     * Trigger a sync job immediately (the "Lancer manuellement" button).
     * Runs synchronously; a full_catalog run can take several minutes.
     */
    public function run(string $job): JsonResponse
    {
        if (! in_array($job, CronSettings::JOBS, true)) {
            return response()->json(['success' => false, 'message' => 'Tâche inconnue.'], 422);
        }

        @set_time_limit(0);

        try {
            // Launched from the UI button → recorded as "Manuelle" in the history.
            Artisan::call('sync:run', ['type' => $job, '--force' => true, '--trigger' => 'manual']);
            $output = trim(Artisan::output());

            return response()->json([
                'success' => true,
                'message' => 'Synchronisation exécutée.',
                'job'     => $job,
                'output'  => $output,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Échec de l\'exécution : ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function describeJob(string $key, array $config): array
    {
        $nextRunSeconds = null;
        $nextRunIso = null;

        if (! empty($config['cron']) && CronExpression::isValidExpression($config['cron'])) {
            try {
                $next = (new CronExpression($config['cron']))->getNextRunDate();
                $nextRunIso = $next->format(DATE_ATOM);
                $nextRunSeconds = max(0, $next->getTimestamp() - now()->getTimestamp());
            } catch (\Throwable) {
            }
        }

        $runs = SyncLog::query()
            ->where('type', $key)
            ->where('triggered_by', SyncLog::TRIGGER_SCHEDULER)
            ->orderByDesc('started_at')
            ->limit(5)
            ->get();

        $durations = $runs->where('status', 'success')->pluck('duration_seconds')->filter()->all();
        $avg = $durations ? (int) round(array_sum($durations) / count($durations)) : null;

        return [
            'key'             => $key,
            'label'           => $config['label'] ?? $key,
            'description'     => $config['description'] ?? '',
            'active'          => (bool) ($config['active'] ?? false),
            'frequency'       => $config['frequency'] ?? null,
            'cron'            => $config['cron'] ?? null,
            'next_run'        => $nextRunIso,
            'next_run_human'  => $config['active'] ? $this->relativeFuture($nextRunSeconds) : 'Désactivé',
            'avg_duration'    => $avg !== null ? $this->humanDuration($avg) : '—',
            'recent_runs'     => $runs->map(fn (SyncLog $l) => [
                'id'       => $l->id,
                'status'   => $l->status,
                'tone'     => $l->status === 'success' ? 'success' : ($l->status === 'partial' ? 'warning' : 'error'),
                'date'     => optional($l->started_at)->format('d/m/Y H:i'),
                'duration' => $this->humanDuration((int) $l->duration_seconds),
                'created'  => (int) $l->products_created,
                'updated'  => (int) $l->products_updated,
                'errors'   => (int) $l->errors_count,
            ])->all(),
        ];
    }

    private function relativeFuture(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds < 60) {
            return 'dans moins d\'une minute';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return "dans {$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $restMin = $minutes % 60;
        if ($hours < 24) {
            return $restMin > 0 ? "dans {$hours}h {$restMin}min" : "dans {$hours}h";
        }

        $days = intdiv($hours, 24);
        $restH = $hours % 24;

        return $restH > 0 ? "dans {$days}j {$restH}h" : "dans {$days}j";
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $rest > 0 ? "{$minutes}min {$rest}s" : "{$minutes}min";
    }
}
