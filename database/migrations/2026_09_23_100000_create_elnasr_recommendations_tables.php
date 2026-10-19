<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables backing the "Elnasr Recommendations" tab — imported from a set of
 * Zoho Analytics Query Tables the manager exported directly (not synced via
 * the live API), since these are computed/derived tables (customer
 * segmentation + recommendations) built by Analytics formulas, not raw Zoho
 * records. See each importer command for the exact source file/columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per customer — the final, combined output of Zoho's
        // "Customer Sales Behavior & Decision Engine": all five segment
        // classifications plus the synthesized recommendation. Source:
        // Final_Sales_Decision_Engine.csv.
        Schema::create('customer_sales_recommendations', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_customer_id')->unique();
            $table->string('customer_name');
            $table->string('sales_growth_segment')->nullable();
            $table->string('buying_rhythm_segment')->nullable();
            $table->string('aov_growth_segment')->nullable();
            $table->string('sku_growth_segment')->nullable();
            $table->string('portfolio_behavior_segment')->nullable();
            $table->date('last_purchase_date')->nullable();
            $table->integer('days_since_last_purchase')->nullable();
            $table->string('priority_level')->nullable();
            $table->string('overall_sales_status')->nullable();
            $table->text('primary_recommendation')->nullable();
            $table->text('secondary_recommendation')->nullable();
            $table->text('final_why')->nullable();
            $table->text('recommended_sales_rep_action')->nullable();
            $table->text('key_risk')->nullable();
            $table->text('key_opportunity')->nullable();
            $table->string('confidence')->nullable();
            // Filled in by a follow-up command from Sales_Item_Monthly_
            // Performance.csv, not part of the source export itself.
            $table->decimal('total_revenue', 14, 2)->nullable();
            $table->integer('revenue_rank')->nullable();
            $table->integer('sales_rank')->nullable();
            $table->timestamps();
        });

        // Source: CREDIT_LIMIT_USAGE.csv.
        Schema::create('customer_credit_usage', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_customer_id')->unique();
            $table->string('customer_number')->nullable();
            $table->string('customer_name');
            $table->string('management')->nullable();
            $table->string('rep_name')->nullable();
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->decimal('used_credit', 14, 2)->nullable();
            $table->decimal('credit_utilization_pct', 6, 2)->nullable();
            $table->decimal('remaining_credit', 14, 2)->nullable();
            $table->decimal('available_credit_pct', 6, 2)->nullable();
            $table->string('credit_status')->nullable();
            $table->timestamps();
        });

        // Source: Customer_Sales_Behavior_Classification.csv — mainly used
        // here for historical_first_purchase (not present anywhere else in
        // this project) and the per-dimension "Why" explanations the
        // combined decision-engine table only summarizes into one Final Why.
        Schema::create('customer_sales_behavior', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_customer_id')->unique();
            $table->string('customer_name');
            $table->date('historical_first_purchase')->nullable();
            $table->date('historical_last_purchase')->nullable();
            $table->text('sales_growth_why')->nullable();
            $table->text('buying_rhythm_why')->nullable();
            $table->text('aov_growth_why')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
        });

        // Source: Customer_Document_Insights.xlsx — many rows per customer
        // (one per missing/needed document or data-quality action), unlike
        // the tables above which are one row per customer.
        Schema::create('customer_document_insights', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_customer_id');
            $table->string('customer_name');
            $table->string('rep_name')->nullable();
            $table->text('action');
            $table->text('why')->nullable();
            $table->timestamps();

            $table->index('zoho_customer_id');
        });

        // Raw monthly sales-by-item-by-customer, source: Sales_Item_Monthly_
        // Performance.csv (612k rows) — kept as real data (not just an
        // aggregate) since it's genuinely useful for future trend/SKU
        // reporting, not only today's revenue/sales rank computation.
        Schema::create('sales_item_monthly_performance', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->string('zoho_customer_id');
            $table->string('zoho_item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->string('zoho_location_id')->nullable();
            $table->integer('qty')->nullable();
            $table->decimal('discount_amount', 14, 2)->nullable();
            $table->decimal('cogs', 14, 2)->nullable();
            $table->decimal('item_total', 14, 2)->nullable();
            $table->decimal('item_gross_profit', 14, 2)->nullable();
            // Wider than a plain percentage range — the source export has occasional
            // garbage values (e.g. -36650) that would otherwise overflow decimal(6,2).
            $table->decimal('discount_percent', 10, 2)->nullable();
            $table->timestamps();

            $table->index('zoho_customer_id');
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_item_monthly_performance');
        Schema::dropIfExists('customer_document_insights');
        Schema::dropIfExists('customer_sales_behavior');
        Schema::dropIfExists('customer_credit_usage');
        Schema::dropIfExists('customer_sales_recommendations');
    }
};
