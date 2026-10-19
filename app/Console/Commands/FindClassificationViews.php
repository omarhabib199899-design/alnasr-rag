<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;

/**
 * Lists every view in the shared Zoho Analytics workspace (see
 * config/zoho.php's `analytics.views` docblock for how the first 9 were
 * discovered the same way) and flags ones whose name looks like it might be
 * one of the 4 customer classification queries (Sales/Collection/
 * Profitability/Documents) or their staging tables — so the viewIds needed
 * to sync them can be found without asking Dr. Ahmed to hand them over
 * manually. Prints every view too (not just matches), since the keyword
 * list is a guess at naming and a real match might not contain any of them.
 */
class FindClassificationViews extends Command
{
    protected $signature = 'zoho:find-classification-views';

    protected $description = 'List Zoho Analytics workspace views and flag likely matches for the 4 customer classification queries';

    private const KEYWORDS = [
        'sales classification', 'sales behavior', 'sales decision',
        'collection', 'settlement', 'payment pattern',
        'profitability', 'gross margin', 'margin',
        'document', 'compliance',
        'accrual', 'ar ledger', 'ledger',
        'recommendation', 'insight',
    ];

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $views = $client->listViews($connection, $workspaceId);

        $this->info('Total views in workspace '.$workspaceId.': '.count($views));
        $this->newLine();

        $matches = [];
        foreach ($views as $view) {
            $name = (string) ($view['viewName'] ?? '');
            $id = (string) ($view['viewId'] ?? '');
            $type = (string) ($view['viewType'] ?? $view['viewTypeName'] ?? '?');
            $lower = mb_strtolower($name);

            foreach (self::KEYWORDS as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $matches[] = ['name' => $name, 'id' => $id, 'type' => $type, 'keyword' => $keyword];
                    break;
                }
            }
        }

        $this->info('=== Likely matches ('.count($matches).') ===');
        foreach ($matches as $m) {
            $this->line("[{$m['id']}] ({$m['type']}) {$m['name']}  (matched: {$m['keyword']})");
        }

        $this->newLine();
        $this->info('=== All views ===');
        foreach ($views as $view) {
            $type = (string) ($view['viewType'] ?? $view['viewTypeName'] ?? '?');
            $this->line('['.($view['viewId'] ?? '?').'] ('.$type.') '.($view['viewName'] ?? '?'));
        }

        return self::SUCCESS;
    }
}
