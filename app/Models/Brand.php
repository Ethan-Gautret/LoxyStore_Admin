<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $table = 'brand_mappings';

    protected $fillable = [
        'tds_manufacturer',
        'ps_manufacturer_id',
        'active',
        'blacklisted',
        'blacklist_reason',
    ];

    protected $casts = [
        'active' => 'boolean',
        'blacklisted' => 'boolean',
    ];

    /**
     * Get the count of products for this brand
     */
    public function getProductCountAttribute()
    {
        return TDSynexProduct::where('manufacturer', $this->tds_manufacturer)->count();
    }

    /**
     * Scope to get active brands
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope to get non-blacklisted brands
     */
    public function scopeNotBlacklisted($query)
    {
        return $query->where('blacklisted', false);
    }
}
