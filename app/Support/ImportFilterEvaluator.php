<?php

namespace App\Support;

use App\Models\ImportFilter;

/**
 * Décide, pour un produit TD SYNNEX donné, s'il passe les filtres d'import.
 *
 * Retourne ['keep' => bool, 'active' => bool] :
 *   - keep = false  → le produit est exclu (non importé / purgé du catalogue local),
 *   - keep = true, active = false → importé mais désactivé (poussé inactif vers PS),
 *   - keep = true, active = true  → importé normalement.
 *
 * Les overrides catégorie (min_stock / min_price / max_price) priment sur le
 * réglage global quand ils sont définis.
 */
class ImportFilterEvaluator
{
    /**
     * @param array $p  ['name','ean','weight','description','cost_price','stock_qty']
     * @param array|null $override ['min_stock','min_price','max_price'] (valeurs nullables)
     */
    public static function evaluate(array $p, ImportFilter $f, ?array $override = null): array
    {
        $exclude = ['keep' => false, 'active' => false];

        $minStock = $override['min_stock'] ?? $f->min_stock ?? 0;
        $minPrice = $override['min_price'] ?? $f->min_price;
        $maxPrice = $override['max_price'] ?? $f->max_price;

        $cost  = (float) ($p['cost_price'] ?? 0);
        $stock = (int) ($p['stock_qty'] ?? 0);
        $name  = mb_strtolower(trim((string) ($p['name'] ?? '')));

        // 1) Attributs obligatoires : un attribut requis manquant = exclusion.
        $required = $f->required_attributes ?? [];
        if (in_array('ean', $required, true) && trim((string) ($p['ean'] ?? '')) === '') {
            return $exclude;
        }
        if (in_array('weight', $required, true) && ! ((float) ($p['weight'] ?? 0) > 0)) {
            return $exclude;
        }
        if (in_array('description', $required, true) && trim((string) ($p['description'] ?? '')) === '') {
            return $exclude;
        }
        // 'image' non supporté (aucune donnée image extraite) → ignoré.

        // 2) Bornes de prix d'achat (uniquement si définies).
        if ($minPrice !== null && $cost < (float) $minPrice) {
            return $exclude;
        }
        if ($maxPrice !== null && $maxPrice > 0 && $cost > (float) $maxPrice) {
            return $exclude;
        }

        // 3) Mots-clés exclus dans le nom.
        foreach (($f->exclude_keywords ?? []) as $kw) {
            $kw = trim(mb_strtolower((string) $kw));
            if ($kw !== '' && mb_strpos($name, $kw) !== false) {
                return $exclude;
            }
        }

        // 4) Stock sous le minimum → comportement configuré.
        if ($stock < (int) $minStock) {
            $behaviour = $f->stock_behaviour ?? 'disable';
            if ($behaviour === 'delete') {
                return $exclude;
            }
            if ($behaviour === 'disable') {
                return ['keep' => true, 'active' => false];
            }
            // 'keep' → gardé tel quel.
        }

        return ['keep' => true, 'active' => true];
    }
}
