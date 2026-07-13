<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TDSCategory extends Model
{
    protected $table = 'tds_categories';

    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'active' => 'boolean',
    ];
}