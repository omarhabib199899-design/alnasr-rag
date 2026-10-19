<?php

namespace App\Http\Controllers;

use App\Models\Import;
use App\Models\ZohoConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Configuration" — a single place listing every Zoho-backed data source in
 * the system and when it was last refreshed, per an explicit ask (manager's
 * voice note 5): a sidebar item showing "كل الليستا اللي احنا عاملينها"
 * (every sync/list we've built). Read-only status page, no controls to
 * trigger a sync from here — every source already has its own artisan
 * command for that (reports:sync-*).
 */
class ConfigurationController extends Controller
{
    /**
     * type => [table it lands in, human label, timestamp column to read].
     * The first group is tracked the classic way via `imports`; the second
     * group is synced straight into its own dedicated table with no
     * `imports` row at all.
     */
    private const IMPORT_TRACKED_TYPES = [
        'inventory' => 'Inventory Items',
        'sales' => 'Sales Orders',
        'purchase_orders' => 'Purchase Orders',
        'returns' => 'Sales Returns',
        'invoices' => 'Invoices',
        'contacts' => 'Customers (Contacts)',
    ];

    private const STANDALONE_TABLES = [
        'customer_sales_recommendations' => ['label' => 'Customer Sales Recommendations', 'timestamp' => 'updated_at'],
        'customer_credit_usage' => ['label' => 'Customer Credit Usage', 'timestamp' => 'updated_at'],
        'customer_sales_behavior' => ['label' => 'Customer Sales Behavior', 'timestamp' => 'updated_at'],
        'customer_document_insights' => ['label' => 'Customer Document Insights', 'timestamp' => 'updated_at'],
        'ar_ledger_entries' => ['label' => 'AR Ledger Entries', 'timestamp' => 'updated_at'],
        'sales_item_performance' => ['label' => 'Sales Item Performance', 'timestamp' => 'updated_at'],
        'sales_item_monthly_performance' => ['label' => 'Sales Item Monthly Performance', 'timestamp' => 'updated_at'],
        'invoice_documents' => ['label' => 'Invoice Documents (Ops)', 'timestamp' => 'updated_at'],
        'customer_classifications' => ['label' => 'Customer Classifications (Sales/Collection/Profitability/Documents)', 'timestamp' => 'synced_at'],
    ];

    public function index()
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        $importSources = [];
        foreach (self::IMPORT_TRACKED_TYPES as $type => $label) {
            $latest = Import::where('type', $type)->where('source', 'zoho')->latest('created_at')->first();
            $importSources[] = [
                'label' => $label,
                'status' => $latest?->status,
                'row_count' => $latest?->row_count,
                'last_synced' => $latest?->created_at,
                'error' => $latest?->error_message,
            ];
        }

        $standaloneSources = [];
        foreach (self::STANDALONE_TABLES as $table => $meta) {
            if (! Schema::hasTable($table)) {
                $standaloneSources[] = ['label' => $meta['label'], 'row_count' => null, 'last_synced' => null, 'missing' => true];

                continue;
            }

            $lastSynced = DB::table($table)->max($meta['timestamp']);
            $standaloneSources[] = [
                'label' => $meta['label'],
                'row_count' => DB::table($table)->count(),
                'last_synced' => $lastSynced,
                'missing' => false,
            ];
        }

        return view('configuration.index', [
            'connection' => $connection,
            'importSources' => $importSources,
            'standaloneSources' => $standaloneSources,
        ]);
    }
}
