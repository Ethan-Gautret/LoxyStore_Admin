<?php

namespace App\Jobs;

use App\Http\Controllers\Api\CategoryController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTdsynexCategoryProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $categoryCode;

    public function __construct(string $categoryCode)
    {
        $this->categoryCode = $categoryCode;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            $controller = new CategoryController();
            $result = $controller->syncTdsynexCategoryProducts($this->categoryCode);
            Log::info('SyncTdsynexCategoryProducts: completed', ['category' => $this->categoryCode, 'result' => $result]);
        } catch (\Throwable $e) {
            Log::error('SyncTdsynexCategoryProducts: failed', ['category' => $this->categoryCode, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
