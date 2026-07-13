<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TDSynexProduct extends Model
{
    protected $table = 'tdsynnex_products';

    protected $fillable = [
        'sku',
        'manufacturer',
        'category_tds',
        'name',
        'ean',
        'cost_price',
        'stock_qty',
        'weight',
        'description',
        'raw_payload',
        'hash',
        'is_active',
        'fetched_at',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'weight' => 'decimal:3',
        'is_active' => 'boolean',
        'raw_payload' => 'json',
        'fetched_at' => 'datetime',
    ];
}
