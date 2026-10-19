<?php

namespace App\Console\Commands;

use App\Jobs\PushPurchaseRecommendationsJob;
use App\Models\PurchaseRecommendation;
use App\Models\PurchaseRecommendationZohoLink;
use App\Models\ZohoConnection;
use App\Services\Reports\PurchaseRecommendationBuilder;
use App\Services\Reports\PurchaseRecommendationPusher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Computes the Purchase Recommendation report and, unless --dry-run is
 * passed, pushes it into Zoho's `Purchase Recommendation` custom module.
 *
 * --push-one=SKU pushes a single record synchronously with full
 * request/response detail printed — use this to verify field names/module
 * changes before trusting a real run.
 *
 * --push dispatches PushPurchaseRecommendationsJob to push everything that
 * changed since the last successful push, in small chunks via the existing
 * queue infrastructure — NOT run synchronously in this command, since a
 * single long-running foreground push of thousands of rows previously got
 * silently killed by this host's CPU/time limit.
 */
class BuildPurchaseRecommendations extends Command
{
    protected $signature = 'reports:purchase-recommendations
        {--dry-run : Compute and preview only, write nothing to Zoho}
        {--push-one= : Push a single SKU to Zoho synchronously with full request/response detail, for verifying field names}
        {--push : Queue a push of every row that changed since the last successful push}';

    protected $description = 'Compute stock status, suggested purchase quantity, and vendor comparison per SKU, and push to Zoho';

    public function handle(PurchaseRecommendationBuilder $builder, PurchaseRecommendationPusher $pusher): int
    {
        // PurchaseRecommendationBuilder now pulls every invoice line in the
        // 6-month window into memory to find each SKU's top order (233k+
        // rows after tonight's Stock Movement backfill) — comfortably past
        // this host's 512M default and confirmed live to fatal-error here.
        // Raised rather than optimizing the query under time pressure right
        // now; worth revisiting as a SQL-side top-N-per-group query if the
        // dataset keeps growing.
        ini_set('memory_limit', '2048M');

        $connection = ZohoConnection::where('status', 'connected')->first();

        if ($connection === null) {
            $this->error('No connected Zoho account found.');

            return self::FAILURE;
        }

        $rows = $builder->build($connection->user_id);

        if ($rows->isEmpty()) {
            $this->warn('No inventory data to build recommendations from — has a Zoho inventory sync completed?');

            return self::FAILURE;
        }

        $this->info("Computed {$rows->count()} recommendations.");

        // Keyed by SKU => the hash of fields last confirmed pushed to Zoho
        // (null if never successfully pushed). Deliberately NOT the row's
        // current field values — persistLocally() below overwrites those
        // unconditionally, success or failure, so comparing against them
        // would wrongly treat "computed but never sent" as "unchanged".
        $previousHashes = PurchaseRecommendation::pluck('zoho_pushed_hash', 'sku');

        $this->persistLocally($rows);

        $byStatus = $rows->countBy('stock_status');
        $this->table(['Status', 'Count'], $byStatus->map(fn ($count, $status) => [$status, $count])->values()->all());

        $this->line('');
        $this->line('Sample rows:');
        $this->table(
            ['SKU', 'Stock', 'Pending PO', 'Status', 'Suggested', 'Most Purchased Vendor', 'Lowest Price Vendor'],
            $rows->take(15)->map(fn (array $r) => [
                $r['sku'], $r['current_stock'], $r['pending_po'], $r['stock_status'], $r['suggested_purchase'],
                $r['most_purchased_vendor'], $r['lowest_price_vendor'],
            ])->all(),
        );

        if ($pushOneSku = $this->option('push-one')) {
            return $this->pushOne($pusher, $connection, $rows, $pushOneSku);
        }

        if (! $this->option('push')) {
            $this->line('');
            $this->info('Dry run — nothing written to Zoho. Use --push-one=SKU to test a single record, or --push to queue the real push.');

            return self::SUCCESS;
        }

        if ($this->pushAlreadyQueued()) {
            $this->warn('A push is already queued/running from an earlier run — not starting a duplicate. Let it finish (check the Reports page or the jobs table) before running --push again.');

            return self::SUCCESS;
        }

        $changedRows = $this->filterChangedRows($rows, $previousHashes, $pusher);
        $this->info("{$changedRows->count()} of {$rows->count()} rows changed since last push.");

        if ($changedRows->isEmpty()) {
            $this->info('Nothing to push.');

            return self::SUCCESS;
        }

        $orderedSkus = $this->neverPushedFirst($changedRows);

        PushPurchaseRecommendationsJob::dispatch($connection, $orderedSkus);
        $this->info('Queued — will process in the background in small batches via the existing queue worker. Never-pushed SKUs go first, so Zoho\'s record count climbs steadily rather than spending early batches re-updating ones already there. Check the imports/queue as usual, or just wait and re-check the Reports page.');

        return self::SUCCESS;
    }

    /**
     * A second --push while the first chain is still going would queue an
     * overlapping duplicate — same class of problem as the Zoho sync
     * overlap bug, just here instead of there.
     */
    private function pushAlreadyQueued(): bool
    {
        return DB::table('jobs')->where('payload', 'like', '%PushPurchaseRecommendationsJob%')->exists();
    }

    /**
     * SKUs with no PurchaseRecommendationZohoLink row have never been
     * pushed at all — putting those first means Zoho's own record count
     * climbs steadily from the start of a run, instead of early batches
     * being spent re-updating SKUs that are already there (which happens
     * when background data changes make an already-pushed SKU's hash
     * mismatch again before the run ever reaches the genuinely new ones).
     *
     * @param  Collection<int, array<string, mixed>>  $changedRows
     * @return array<int, string>
     */
    private function neverPushedFirst(Collection $changedRows): array
    {
        $linkedSkus = PurchaseRecommendationZohoLink::pluck('sku')->flip();

        return $changedRows
            ->sortBy(fn (array $row) => $linkedSkus->has($row['sku']) ? 1 : 0)
            ->pluck('sku')
            ->values()
            ->all();
    }

    /**
     * Saves the full computed set to our own database every time this
     * command runs, regardless of --dry-run/--push — this is what the
     * site's own Reports page reads, independent of whether/how much of
     * this run also got pushed to Zoho.
     */
    private function persistLocally($rows): void
    {
        $now = now();
        $records = $rows->map(fn (array $row) => array_merge($row, [
            'created_at' => $now,
            'updated_at' => $now,
        ]))->all();

        $updateColumns = array_diff(array_keys($records[0]), ['sku', 'created_at']);

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('purchase_recommendations')->upsert($chunk, ['sku'], $updateColumns);
        }
    }

    /**
     * A SKU needs pushing if it was never successfully pushed before, or if
     * the fields we'd send now differ from the hash of what was last
     * successfully pushed. Hashing the exact payload sidesteps
     * float-vs-decimal-cast string mismatches (5.00 vs "5.00") automatically,
     * since the same values always hash the same way regardless of PHP type.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<string, ?string>  $previousHashes  sku => zoho_pushed_hash
     * @return Collection<int, array<string, mixed>>
     */
    private function filterChangedRows(Collection $rows, Collection $previousHashes, PurchaseRecommendationPusher $pusher): Collection
    {
        return $rows->filter(fn (array $row) => $pusher->hashRow($row) !== $previousHashes->get($row['sku']))->values();
    }

    private function pushOne(PurchaseRecommendationPusher $pusher, ZohoConnection $connection, $rows, string $sku): int
    {
        $row = $rows->firstWhere('sku', $sku);

        if ($row === null) {
            $this->error("SKU '{$sku}' not found in computed rows.");

            return self::FAILURE;
        }

        $this->line('');
        $this->line('Fields being sent: '.json_encode($pusher->mapFields($row), JSON_PRETTY_PRINT));

        try {
            $result = $pusher->push($connection, $row);
            $this->info("Success — Zoho record {$result['record_id']} for SKU {$sku}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
