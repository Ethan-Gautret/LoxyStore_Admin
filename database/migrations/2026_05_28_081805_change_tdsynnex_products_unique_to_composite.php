<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tdsynnex_products', function (Blueprint $table) {
            // Drop the existing single-column unique index on sku
            $table->dropUnique(['sku']);

            // Add composite unique index: same SKU can exist in multiple categories
            $table->unique(['sku', 'category_tds'], 'tdsynnex_products_sku_category_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tdsynnex_products', function (Blueprint $table) {
            $table->dropUnique('tdsynnex_products_sku_category_unique');
            $table->unique('sku');
        });
    }
};
