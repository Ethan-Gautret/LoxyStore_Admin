<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncLogController extends Controller
{
    /**
     * Return the synchronisation history, most recent first, shaped for the
     * SyncHistory page. Each row carries its trigger type (Manuelle / Automatique).
     *
     * Optional query params:
     *   ?trigger=manual|scheduler   filter by how the sync was started
     *   ?from=YYYY-MM-DD&to=YYYY-MM-DD   filter by start date
     *   ?limit=N                    cap the number of rows (default 100, max 500)
     */
    public function index(Request $request): JsonResponse
    {
        $query = SyncLog::query()->orderByDesc('started_at')->orderByDesc('id');

        $trigger = $request->string('trigger')->toString();
        if (in_array($trigger, [SyncLog::TRIGGER_MANUAL, SyncLog::TRIGGER_SCHEDULER], true)) {
            $query->where('triggered_by', $trigger);
        }

        if ($from = $request->string('from')->toString()) {
            try { $query->where('started_at', '>=', $from . ' 00:00:00'); } catch (\Throwable) {}
        }
        if ($to = $request->string('to')->toString()) {
            try { $query->where('started_at', '<=', $to . ' 23:59:59'); } catch (\Throwable) {}
        }

        $limit = min(500, max(1, (int) $request->integer('limit', 100)));

        $rows = $query->limit($limit)->get()->map(fn (SyncLog $log) => $this->formatRow($log))->all();

        return response()->json([
            'data'  => $rows,
            'count' => count($rows),
        ]);
    }

    private function formatRow(SyncLog $log): array
    {
        $tone = match ($log->status) {
            'success' => 'success',
            'partial' => 'warning',
            'failed'  => 'danger',
            default   => 'info',
        };

        $isAuto = $log->triggered_by === SyncLog::TRIGGER_SCHEDULER;

        return [
            'id'            => $log->id,
            'trigger'       => $log->triggered_by,
            'trigger_label' => $log->triggerLabel(),          // "Manuelle" / "Automatique"
            'trigger_tone'  => $isAuto ? 'auto' : 'manual',
            'type'          => $log->type,
            'operation'     => $log->report['operation'] ?? null,
            'category'      => $log->report['category'] ?? null,
            'status'        => $log->status,
            'tone'          => $tone,
            'created'       => (int) $log->products_created,
            'updated'       => (int) $log->products_updated,
            'disabled'      => (int) $log->products_disabled,
            'skipped'       => (int) $log->products_skipped,
            'errors'        => (int) $log->errors_count,
            'started_at'    => optional($log->started_at)->toIso8601String(),
            'date'          => optional($log->started_at)->format('d/m/Y H:i'),
            'duration'      => $this->formatDuration((int) $log->duration_seconds),
            'report'        => $log->report,
        ];
    }

    private function formatDuration(int $seconds): string
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
