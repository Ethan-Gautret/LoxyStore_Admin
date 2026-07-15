<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarginRule extends Model
{
    protected $table = 'margin_rules';

    protected $fillable = [
        'scope',
        'scope_id',
        'scope_label',
        'margin_type',
        'margin_value',
        'min_price_floor',
        'max_price_ceiling',
        'priority',
        'active',
    ];

    protected $casts = [
        'margin_value'      => 'float',
        'min_price_floor'   => 'float',
        'max_price_ceiling' => 'float',
        'priority'          => 'integer',
        'active'            => 'boolean',
    ];

    /** Valeur de repli si aucune règle globale n'est encore enregistrée. */
    public const DEFAULT_GLOBAL_MARGIN = 15.0;

    /**
     * Marge globale (%) définie dans « Règles des marges ». Sert de repli quand une
     * catégorie n'a pas de marge propre. Retourne DEFAULT_GLOBAL_MARGIN si aucune
     * règle globale active n'existe.
     */
    public static function globalMargin(): float
    {
        try {
            $value = static::query()
                ->where('scope', 'global')
                ->where('active', true)
                ->orderBy('priority')
                ->value('margin_value');

            return is_numeric($value) ? (float) $value : self::DEFAULT_GLOBAL_MARGIN;
        } catch (\Throwable) {
            return self::DEFAULT_GLOBAL_MARGIN;
        }
    }
}
