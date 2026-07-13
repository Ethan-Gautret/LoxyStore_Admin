<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'role')) {
                $table->enum('role', ['admin', 'operator', 'readonly'])->default('operator')->after('password');
            }
        });

        Schema::create('tdsynnex_products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 100)->unique();
            $table->string('manufacturer', 150)->nullable();
            $table->string('category_tds', 200)->nullable();
            $table->string('name', 500);
            $table->string('ean', 20)->nullable();
            $table->decimal('cost_price', 10, 2)->default(0.00);
            $table->integer('stock_qty')->default(0);
            $table->decimal('weight', 8, 3)->nullable();
            $table->longText('description')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('hash', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->index('sku', 'idx_tdsynnex_products_sku');
            $table->index('manufacturer', 'idx_tdsynnex_products_manufacturer');
            $table->index('category_tds', 'idx_tdsynnex_products_category');
            $table->index('is_active', 'idx_tdsynnex_products_active');
            $table->index('hash', 'idx_tdsynnex_products_hash');
        });

        Schema::create('category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('tds_category', 200);
            $table->string('tds_category_code', 100)->nullable();
            $table->unsignedBigInteger('ps_category_id')->nullable();
            $table->decimal('margin_override', 5, 2)->nullable()->comment('Override marge % pour cette catégorie');
            $table->integer('min_stock_override')->nullable()->comment('Stock minimum spécifique à cette catégorie');
            $table->decimal('min_price_override', 10, 2)->nullable();
            $table->decimal('max_price_override', 10, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('ignored')->default(false);
            $table->timestamps();

            $table->index('tds_category', 'idx_category_mappings_tds');
            $table->index('ps_category_id', 'idx_category_mappings_ps');
        });

        Schema::create('brand_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('tds_manufacturer', 150);
            $table->unsignedBigInteger('ps_manufacturer_id')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('blacklisted')->default(false);
            $table->string('blacklist_reason', 255)->nullable();
            $table->timestamps();

            $table->index('tds_manufacturer', 'idx_brand_mappings_tds');
            $table->index('blacklisted', 'idx_brand_mappings_blacklisted');
        });

        Schema::create('margin_rules', function (Blueprint $table): void {
            $table->id();
            $table->enum('scope', ['global', 'category', 'brand', 'sku'])->default('global');
            $table->unsignedBigInteger('scope_id')->nullable()->comment('ID catégorie, marque ou produit selon scope');
            $table->string('scope_label', 255)->nullable()->comment('Nom lisible du scope pour affichage');
            $table->enum('margin_type', ['percent', 'fixed'])->default('percent');
            $table->decimal('margin_value', 10, 2)->default(0.00);
            $table->decimal('min_price_floor', 10, 2)->nullable()->comment('Prix vente HT minimum absolu');
            $table->decimal('max_price_ceiling', 10, 2)->nullable()->comment('Prix vente HT maximum absolu');
            $table->integer('priority')->default(0)->comment('Plus petit = priorité plus haute');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['scope', 'scope_id'], 'idx_margin_rules_scope');
            $table->index('priority', 'idx_margin_rules_priority');
            $table->index('active', 'idx_margin_rules_active');
        });

        Schema::create('import_filters', function (Blueprint $table): void {
            $table->id();
            $table->integer('min_stock')->default(1);
            $table->decimal('min_price', 10, 2)->nullable();
            $table->decimal('max_price', 10, 2)->nullable();
            $table->json('exclude_keywords')->nullable()->comment('Mots-clés à exclure du nom/description');
            $table->json('required_attributes')->nullable()->comment('Attributs obligatoires : ean, weight, description, image');
            $table->enum('stock_behaviour', ['disable', 'keep', 'delete'])->default('disable');
            $table->boolean('apply_vat')->default(true);
            $table->decimal('vat_rate', 5, 2)->default(20.00);
            $table->enum('price_rounding', ['none', 'psychological', 'round'])->default('psychological');
            $table->timestamps();
        });

        Schema::create('sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['prices_stock', 'full_catalog', 'manual']);
            $table->enum('triggered_by', ['scheduler', 'manual'])->default('scheduler');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->enum('status', ['running', 'success', 'partial', 'failed'])->default('running');
            $table->integer('products_created')->default(0);
            $table->integer('products_updated')->default(0);
            $table->integer('products_disabled')->default(0);
            $table->integer('products_skipped')->default(0);
            $table->integer('errors_count')->default(0);
            $table->json('report')->nullable()->comment('Détail complet : SKUs en erreur, raisons, stats');
            $table->timestamps();

            $table->index('status', 'idx_sync_logs_status');
            $table->index('type', 'idx_sync_logs_type');
            $table->index('started_at', 'idx_sync_logs_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('import_filters');
        Schema::dropIfExists('margin_rules');
        Schema::dropIfExists('brand_mappings');
        Schema::dropIfExists('category_mappings');
        Schema::dropIfExists('tdsynnex_products');

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};