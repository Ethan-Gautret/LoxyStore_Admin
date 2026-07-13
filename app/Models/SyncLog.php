<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $table = 'sync_logs';

    protected $fillable = [
        'type',
        'triggered_by',
        'started_at',
        'finished_at',
        'duration_seconds',
        'status',
        'products_created',
        'products_updated',
        'products_disabled',
        'products_skipped',
        'errors_count',
        'report',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'report'      => 'array',
    ];

    /** A run started by a user action (UI button). */
    public const TRIGGER_MANUAL = 'manual';

    /** A run started by the scheduler / cron. */
    public const TRIGGER_SCHEDULER = 'scheduler';

    /**
     * Human-readable label for the sync trigger, used by the history page chip.
     */
    public function triggerLabel(): string
    {
        return $this->triggered_by === self::TRIGGER_SCHEDULER ? 'Automatique' : 'Manuelle';
    }
}
