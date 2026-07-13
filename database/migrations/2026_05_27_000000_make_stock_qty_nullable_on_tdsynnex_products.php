<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tdsynnex_products', function (Blueprint $table) {
            $table->integer('stock_qty')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tdsynnex_products', function (Blueprint $table) {
            $table->integer('stock_qty')->default(0)->change();
        });
    }
};
