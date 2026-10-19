<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entity 360 "Customer Actions" daily snapshot — the Lovable portal's insights schema (generations,
 * action_registry, customer_actions, active_generation, generation_queue), same columns and keys,
 * with the 40 registry rows exactly as exported 2026-10-19. Built by `insights:snapshot`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insights_generations', function (Blueprint $t) {
            $t->bigIncrements('generation_id');
            $t->string('status', 16); // running | complete | failed | retired
            $t->timestamp('started_at')->useCurrent();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->integer('customers_total')->default(0);
            $t->integer('customers_done')->default(0);
            $t->integer('action_rows')->default(0);
            $t->integer('batches')->default(0);
            $t->integer('retries')->default(0);
            $t->json('source_at_start')->nullable();
            $t->json('source_at_finish')->nullable();
            $t->text('error')->nullable();
            $t->index('status');
        });

        Schema::create('insights_action_registry', function (Blueprint $t) {
            $t->string('action_code', 64)->primary();
            $t->string('category', 32);
            $t->integer('category_order');
            $t->integer('action_order');
            $t->string('source_rpc', 100);
            $t->boolean('portfolio_only')->default(false);
            $t->unique(['category_order', 'action_order']);
        });

        Schema::create('insights_customer_actions', function (Blueprint $t) {
            $t->unsignedBigInteger('generation_id');
            $t->string('customer_id', 64);
            $t->string('action_code', 64);
            $t->primary(['generation_id', 'action_code', 'customer_id']);
            $t->index(['generation_id', 'customer_id']);
            $t->foreign('generation_id')->references('generation_id')->on('insights_generations')->cascadeOnDelete();
            $t->foreign('action_code')->references('action_code')->on('insights_action_registry');
        });

        Schema::create('insights_active_generation', function (Blueprint $t) {
            $t->boolean('singleton')->default(true)->primary();
            $t->unsignedBigInteger('generation_id');
            $t->timestamp('published_at');
            $t->foreign('generation_id')->references('generation_id')->on('insights_generations');
        });

        Schema::create('insights_generation_queue', function (Blueprint $t) {
            $t->unsignedBigInteger('generation_id');
            $t->string('customer_id', 64);
            $t->string('status', 16)->default('pending'); // pending | done | failed
            $t->integer('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->primary(['generation_id', 'customer_id']);
            $t->foreign('generation_id')->references('generation_id')->on('insights_generations')->cascadeOnDelete();
        });

        $rows = [
            ['stopped_buying', 'sales', 1, 1, 'customer_sales_insights', false],
            ['not_purchased_recently', 'sales', 1, 2, 'customer_sales_insights', false],
            ['not_buying_long_time', 'sales', 1, 3, 'customer_sales_insights', false],
            ['late_purchase', 'sales', 1, 4, 'customer_sales_insights', false],
            ['sales_dropped', 'sales', 1, 5, 'customer_sales_insights', false],
            ['smaller_orders', 'sales', 1, 6, 'customer_sales_insights', false],
            ['long_inactive', 'sales', 1, 7, 'customer_sales_insights', false],
            ['fewer_items', 'items', 2, 1, 'customer_item_insights', false],
            ['stopped_items', 'items', 2, 2, 'customer_item_insights', false],
            ['qty_dropped', 'items', 2, 3, 'customer_item_insights', false],
            ['different_items', 'items', 2, 4, 'customer_item_insights', false],
            ['products_approaching_expiry', 'items', 2, 5, 'customer_item_expiry_insight', false],
            ['frequent_credit_notes', 'credit_notes', 3, 1, 'customer_credit_note_insights', false],
            ['high_credit_note_value', 'credit_notes', 3, 2, 'customer_credit_note_insights', false],
            ['credit_note_without_purchase', 'credit_notes', 3, 3, 'customer_credit_note_insights', false],
            ['old_draft', 'sales_orders', 4, 1, 'customer_sales_order_insights', false],
            ['partially_invoiced', 'sales_orders', 4, 2, 'customer_sales_order_insights', false],
            ['negative_profitability', 'profitability', 5, 1, 'customer_profitability_insights', false],
            ['very_low_profitability', 'profitability', 5, 2, 'customer_profitability_insights', false],
            ['low_profitability', 'profitability', 5, 3, 'customer_profitability_insights', false],
            ['profitability_declined', 'profitability', 5, 4, 'customer_profitability_insights', false],
            ['monthly_collection_required', 'financial_health', 6, 1, 'customer_financial_health_insights', false],
            ['customer_has_unapplied_credit', 'financial_health', 6, 2, 'customer_financial_health_insights.has_unapplied_credit', true],
            ['finance_recommendation', 'documentation', 7, 1, 'customer_documentation_insights', false],
            ['cr_expired', 'documentation', 7, 2, 'customer_documentation_insights', false],
            ['cr_expiring_soon', 'documentation', 7, 3, 'customer_documentation_insights', false],
            ['cr_expiry_missing', 'documentation', 7, 4, 'customer_documentation_insights', false],
            ['reconciliation_overdue', 'documentation', 7, 5, 'customer_documentation_insights', false],
            ['reconciliation_due_soon', 'documentation', 7, 6, 'customer_documentation_insights', false],
            ['reconciliation_date_missing', 'documentation', 7, 7, 'customer_documentation_insights', false],
            ['reconciliation_date_invalid', 'documentation', 7, 8, 'customer_documentation_insights', false],
            ['tax_number_missing', 'documentation', 7, 9, 'customer_documentation_insights', false],
            ['cr_number_missing', 'documentation', 7, 10, 'customer_documentation_insights', false],
            ['gln_missing', 'documentation', 7, 11, 'customer_documentation_insights', false],
            ['moh_expired', 'documentation', 7, 12, 'customer_documentation_insights', false],
            ['moh_expiring_soon', 'documentation', 7, 13, 'customer_documentation_insights', false],
            ['moh_expiry_missing', 'documentation', 7, 14, 'customer_documentation_insights', false],
            ['delivery_city_missing', 'documentation', 7, 15, 'customer_documentation_insights', false],
            ['delivery_location_missing', 'documentation', 7, 16, 'customer_documentation_insights', false],
            ['dispatch_warehouse_missing', 'documentation', 7, 17, 'customer_documentation_insights', false],
        ];
        DB::table('insights_action_registry')->insert(array_map(fn ($r) => array_combine(
            ['action_code', 'category', 'category_order', 'action_order', 'source_rpc', 'portfolio_only'], $r), $rows));
    }

    public function down(): void
    {
        Schema::dropIfExists('insights_generation_queue');
        Schema::dropIfExists('insights_active_generation');
        Schema::dropIfExists('insights_customer_actions');
        Schema::dropIfExists('insights_action_registry');
        Schema::dropIfExists('insights_generations');
    }
};
