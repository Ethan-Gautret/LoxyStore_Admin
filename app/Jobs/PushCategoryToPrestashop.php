<?php

namespace App\Jobs;

use App\Http\Controllers\Api\CategorySyncController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pousse en arrière-plan les produits d'une catégorie vers PrestaShop.
 *
 * Lancé via dispatchAfterResponse() depuis CategorySyncController::push() : le
 * traitement démarre APRÈS l'envoi de la réponse HTTP, dans le même process
 * (aucun worker de queue requis — même mécanisme que l'import). La progression
 * est écrite dans le cache et lue par l'UI via /push-status.
 */
class PushCategoryToPrestashop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $triggeredBy = 'manual',
        public string $syncType = 'full_catalog',
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            (new CategorySyncController())->performPush($this->code, $this->triggeredBy, $this->syncType);
        } catch (\Throwable $e) {
            Log::error('PushCategoryToPrestashop: failed', ['category' => $this->code, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
