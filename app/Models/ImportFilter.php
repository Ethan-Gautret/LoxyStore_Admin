<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Filtres d'import (réglages globaux, une seule ligne = singleton).
 *
 * Décide quels produits TD SYNNEX sont importés / gardés actifs :
 *   - stock minimum + comportement si en-dessous (désactiver / garder / supprimer),
 *   - fourchette de prix d'achat (min / max) + exclusion des produits sans prix,
 *   - exclusion par mots-clés dans le nom,
 *   - attributs obligatoires (ean, weight, description, image).
 */
class ImportFilter extends Model
{
    protected $table = 'import_filters';

    protected $fillable = [
        'min_stock',
        'min_price',
        'max_price',
        'exclude_keywords',
        'required_attributes',
        'stock_behaviour',
        'apply_vat',
        'vat_rate',
        'price_rounding',
    ];

    protected $casts = [
        'min_stock'           => 'integer',
        'min_price'           => 'float',
        'max_price'           => 'float',
        'exclude_keywords'    => 'array',
        'required_attributes' => 'array',
        'apply_vat'           => 'boolean',
        'vat_rate'            => 'float',
    ];

    /** Valeurs par défaut quand aucune ligne n'existe encore. */
    public const DEFAULTS = [
        'min_stock'           => 1,
        'min_price'           => null,
        'max_price'           => null,
        'exclude_keywords'    => [],
        'required_attributes' => [],
        'stock_behaviour'     => 'disable',
        'apply_vat'           => true,
        'vat_rate'            => 20.0,
        'price_rounding'      => 'none',
    ];

    /**
     * La ligne unique de réglages (créée avec les défauts au premier appel).
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], self::DEFAULTS);
    }
}
