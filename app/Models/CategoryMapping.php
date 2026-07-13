<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryMapping extends Model
{
    protected $table = 'category_mappings';

    protected $fillable = [
        'tds_category',
        'tds_category_code',
        'ps_category_id',
        'margin_override',
        'min_stock_override',
        'active',
        'ignored',
    ];

    protected $casts = [
        'active'  => 'boolean',
        'ignored' => 'boolean',
    ];
}
