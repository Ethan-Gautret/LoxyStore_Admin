<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TDSynexProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Évolution du catalogue : taille cumulée du catalogue (nombre de produits
     * TD SYNNEX importés) jour par jour, sur les N derniers jours.
     *
     * Données réelles dérivées de tdsynnex_products.created_at :
     *   - `added` : produits entrés dans le catalogue ce jour-là,
     *   - `total` : taille cumulée du catalogue à la fin de ce jour.
     *
     * La courbe monte à chaque import ; elle se remplit au fil des synchros.
     */
    public function catalogueEvolution(Request $request): JsonResponse
    {
        $days = min(180, max(7, (int) $request->integer('days', 30)));

        // Fenêtre [start .. aujourd'hui], bornée au début de journée.
        $start = now()->subDays($days - 1)->startOfDay();

        // Produits déjà présents AVANT la fenêtre = point de départ de la courbe.
        $baseline = TDSynexProduct::query()
            ->where('created_at', '<', $start)
            ->count();

        // Ajouts par jour dans la fenêtre (clé = date Y-m-d).
        $addedByDay = DB::table('tdsynnex_products')
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->where('created_at', '>=', $start)
            ->groupBy('d')
            ->pluck('c', 'd');

        $series = [];
        $running = $baseline;
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $added = (int) ($addedByDay[$day] ?? 0);
            $running += $added;
            $series[] = [
                'date'  => $day,
                'added' => $added,
                'total' => $running,
            ];
        }

        return response()->json([
            'success'    => true,
            'days'       => $days,
            'total_now'  => TDSynexProduct::count(),
            'active_now' => TDSynexProduct::where('is_active', true)->count(),
            'series'     => $series,
        ]);
    }
}
