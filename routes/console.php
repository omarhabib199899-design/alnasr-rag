<?php

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// No persistent queue worker runs on this host, so this executes inline
// (Schedule::call, not Schedule::job) when cron fires `schedule:run`.
//
// withoutOverlapping() takes an expiry in minutes and DEFAULTS TO 1440 (24
// HOURS) if not given — confirmed as the real cause of a full-day production
// freeze: this host's CloudLinux LVE can SIGKILL a running process with zero
// warning and zero chance to clean up, and Laravel's overlap lock is a plain
// cache key with no "is the PID that set this still alive" check. A run
// killed mid-execution leaves that key set, so every following minute's
// schedule:run sees "already running" and silently skips — forever, or until
// someone manually runs `artisan cache:clear`. Giving each an explicit
// expiry well above its real runtime (each is a fast dispatch, not the work
// itself) means a stuck lock self-heals within minutes instead of requiring
// a human to notice a mysteriously frozen app and clear it by hand.
// Guarded by a cache flag (not removed from the schedule) so it can be
// paused/resumed instantly from a one-line script — no file redeploy needed
// — for exactly the situation this was added for (2026-09-15): staff about
// to start their workday with the shared daily API budget already partly
// consumed by an overnight historical re-crawl, needing the sync silenced
// for a few hours without losing the resumable cursor state.
Schedule::call(function (ZohoSyncService $syncService) {
    if (Cache::get('zoho_sync_paused', false)) {
        return;
    }

    ZohoConnection::where('status', 'connected')->each(function (ZohoConnection $connection) use ($syncService) {
        try {
            $syncService->sync($connection);
        } catch (\Throwable $e) {
            report($e);
        }
    });
})->hourly()->name('zoho-inventory-sync')->withoutOverlapping(30);

// The two Books-API lanes (invoices, sales), polled on their own tighter
// cadence (2026-10-17) — confirmed live the same day that hourly alone left
// too wide a gap: the Inventory-budget "sales"/"sales_recent" lane fell
// ~2 hours behind under heavy shared-budget contention from the invoices
// initial backfill, and since the Books lane only checks "what changed
// since last watermark" once per invocation, the hourly cadence above meant
// up to an hour of exposure before it even got a chance to close a gap the
// Inventory lane fell into. Every 15 minutes costs little — both jobs no-op
// fast when nothing's actually behind (confirmed live: the 12:00 run that
// day completed in 6 seconds with 0 new rows) — and closes that exposure
// window to ~15 minutes regardless of how badly the Inventory lane is
// doing. See ZohoSyncService::syncBooksLanes()'s own docblock for why this
// is a separate method/call rather than just running the hourly sync()
// itself more often: the other five lanes already have their own "_recent"
// catch-up cursor and don't need (or want) 4x the Inventory-budget
// pressure for no benefit.
Schedule::call(function (ZohoSyncService $syncService) {
    if (Cache::get('zoho_sync_paused', false)) {
        return;
    }

    $skuByItemId = $syncService->skuByItemIdFromDatabase();

    ZohoConnection::where('status', 'connected')->each(function (ZohoConnection $connection) use ($syncService, $skuByItemId) {
        try {
            $syncService->syncBooksLanes($connection, $skuByItemId);
        } catch (\Throwable $e) {
            report($e);
        }
    });
})->everyFifteenMinutes()->name('zoho-books-sync')->withoutOverlapping(10);

// Same no-persistent-worker constraint as above: drain the queue on the same
// cron-triggered tick rather than relying on a `queue:work` process that has
// nowhere to run. --stop-when-empty means this is a no-op most minutes.
// --max-time=50 bounds a real run to under a minute, so a lock still held
// after 5 minutes is unambiguously stuck, never a legitimate overlap.
Schedule::command('queue:work --queue=default --stop-when-empty --max-time=50')
    ->everyMinute()
    ->name('process-queued-imports')
    ->withoutOverlapping(5);

// No built-in ->everyTwoDays() helper in Laravel's scheduler, hence the raw
// cron expression — fires at 3am on odd days of the month (1st, 3rd, 5th...),
// which is "every 2 days" with one acceptable imprecision: a 1-day gap
// crossing some month boundaries (e.g. the 31st to the 1st) rather than a
// strict 48-hour interval. Fine for a report based on 6-month sales
// velocity, which doesn't meaningfully change hour to hour.
Schedule::command('reports:purchase-recommendations --push')
    ->cron('0 3 */2 * *')
    ->name('purchase-recommendations-push')
    ->withoutOverlapping(60);

// Just enqueues the first SfdaPriceCheckJob batch (near-instant), so this
// entry's own overlap window only needs to cover the dispatch call, not the
// whole scan — the scan itself runs through the queue in small batches, well
// after this command has already returned.
Schedule::command('reports:check-sfda-prices')
    ->dailyAt('04:00')
    ->name('sfda-price-check')
    ->withoutOverlapping(10);

// Separate from the above — that one only ever checks items in OUR OWN
// inventory, compared against OUR rsp price. This pulls SFDA's ENTIRE drug
// database (~9,165 items, not just what we stock) and diffs it day-over-day
// against itself, independent of our own prices — direct manager request
// (voice note, 2026-10-07): "what changed in SFDA since yesterday", not
// "what's different from our price". Scheduled 2 hours earlier (02:00) so
// the two don't compete for the same upstream SFDA server at once — this
// one takes longer (459 pages vs ~4,115/15≈275 batches, similar order of
// magnitude, but a fresh run each day rather than resuming where the other
// leaves off).
Schedule::command('reports:check-sfda-market-snapshot')
    ->dailyAt('02:00')
    ->name('sfda-market-snapshot')
    ->withoutOverlapping(10);

// Elnasr Recommendations module — replaces what used to be manual CSV/XLSX
// uploads from Zoho Analytics (see project_sales_item_performance_sync
// memory for the full incident history and why each safety pattern was
// chosen). Staggered ~5 min apart so they don't all hit the shared 300/hr
// Zoho API throttle or the host's CPU limits at once; sales_item_performance
// goes first and gets the longest gap before the recompute step since it's
// the only one of these still queued+chunked (this entry only dispatches —
// near-instant — the real ~766k-row sync finishes later via the
// already-scheduled queue:work ticks above, same pattern as
// purchase-recommendations-push).
// Stopped 2026-10-19 (user-approved): sales_item_performance is now filled locally from zoho_sales_item_performance by
// zoho:feed-legacy-tables at the end of every zoho:refresh — same 806k rows, no ~800k-row export (~2,400 API units) a day.
// Schedule::command('reports:sync-sales-item-performance')->dailyAt('01:00')->name('sync-sales-item-performance')->withoutOverlapping(10);

// Raised daily -> hourly (Ahmed Mohsen, 2026-10-02): same class of gap as
// ar_ledger_entries's own daily->hourly fix above — Credit Limit tab's Used
// Credit read 38,164.48 while the real post-payment balance was 27,164.48,
// an exact 11,000 SAR gap traced to CPAY-15532 (posted 2026-09-30) that this
// job's once-a-day 01:15 run hadn't picked up yet. Same safe Zoho Analytics
// Bulk API pattern as sync-ar-ledger (~1,472 rows, finishes in well under a
// minute — see this command's own docblock), so hourly carries the same
// low/no added API-budget risk already reasoned through there.
Schedule::command('reports:sync-customer-credit-usage')
    ->hourly()
    ->name('sync-customer-credit-usage')
    ->withoutOverlapping(15);

Schedule::command('reports:sync-customer-sales-behavior')
    ->dailyAt('01:20')
    ->name('sync-customer-sales-behavior')
    ->withoutOverlapping(15);

Schedule::command('reports:sync-customer-sales-recommendations')
    ->dailyAt('01:25')
    ->name('sync-customer-sales-recommendations')
    ->withoutOverlapping(15);

Schedule::command('reports:sync-customer-document-insights')
    ->dailyAt('01:30')
    ->name('sync-customer-document-insights')
    ->withoutOverlapping(15);

// rep_name/sales_owner/data_entry only, from the same Analytics workspace's
// "Customers" view — see SyncCustomerAssignments' own docblock for why
// these 3 fields specifically don't need to wait on the live, throttle-
// shared Contacts sync. Every 4 hours matches Analytics' own observed
// ~2-hour lag behind live Zoho (confirmed 2026-10-17 against the Credit
// Notes view) — running tighter than that wouldn't see newer data, only
// spend more of this view's own export quota for nothing. Offset to :10 so
// it never collides with reconcile-customers (:15) or reconcile-purchase-
// orders (:30).
Schedule::command('reports:sync-customer-assignments')
    ->cron('10 */4 * * *')
    ->name('sync-customer-assignments')
    ->withoutOverlapping(15);

// Raised daily -> hourly (Ahmed Mohsen, 2026-10-01): a same-day Zoho payment
// could sit unreflected in Collection/Credit Limit (both keyed off this
// table) for up to ~24h until the next 01:35 run, while invoice_documents
// (live hourly transaction sync) already had it — confirmed live via CUS-8
// invoice 76705 (238k SAR gap, traced to one payment posted mid-day).
// Does NOT draw from ZohoApiThrottle's 500/hr budget — that throttle only
// guards www.zohoapis.<tld> (Inventory/Books); this hits a separate host
// (analyticsapi.zoho.<tld>, see ZohoAnalyticsClient) with no shared budget.
// withoutOverlapping(25) still protects against a slow run (up to 10 min of
// polling) colliding with the next hourly tick.
// Stopped 2026-10-19 (user-approved): ar_ledger_entries is now filled locally from zoho_ar_sales (verified identical to
// Zoho's AR Ledger Master) by zoho:feed-legacy-tables at the end of every zoho:refresh — no ~66k-row export every hour
// (~4,800 API units a day).
// Schedule::command('reports:sync-ar-ledger')->hourly()->name('sync-ar-ledger')->withoutOverlapping(25);

// Feeds the customer detail page's contact-persons list (CustomerController)
// — unrelated to the Elnasr Recommendations tables above, just added the
// same evening using the same live-sync pattern.
Schedule::command('reports:sync-customer-contact-persons')
    ->dailyAt('01:40')
    ->name('sync-customer-contact-persons')
    ->withoutOverlapping(15);

// Feeds the new Credit Notes "View Details" line-item drill-down (manager's
// explicit ask, 2026-10-15 — Credit Notes needed the same item-level access
// Invoices already has). Analytics-sourced full-replace, not REST-crawled,
// so freshness here isn't bottlenecked by the shared Inventory/Books
// throttle the way transaction syncs are — every 4 hours is comfortably
// fresh for a "what's in this credit note" lookup. Offset to :50 so it
// never collides with reconcile-customers (:15), reconcile-purchase-orders
// (:30), or reconcile-invoices (:45).
Schedule::command('reports:sync-credit-note-items')
    ->cron('50 */4 * * *')
    ->name('sync-credit-note-items')
    ->withoutOverlapping(30);

// Feeds invoice_documents (Customer 360's Outstanding Balance / Accounts
// Receivable / Due Ages / DSO — see CustomerController) — was never on this
// schedule before (confirmed live 2026-09-27: the command existed but
// nothing triggered it automatically, so the table's freshness depended on
// whoever remembered to run it by hand).
Schedule::command('reports:sync-invoice-documents')
    ->dailyAt('01:45')
    ->name('sync-invoice-documents')
    ->withoutOverlapping(20);

// 'sales', 'profitability', and 'documents' were replaced one at a time
// 2026-10-06 by our own computations below, once the user supplied each
// type's exact SQL — 'collection' stayed on the Zoho-sourced daily batch
// because its "Settlement Date"/"Closing Method" per-invoice data isn't
// derivable from ar_ledger_entries (confirmed live: only 7% of settled
// invoices had a matching invoice_payment row to trace a real settlement
// date from). The fix wasn't deriving it ourselves — it's pulling Zoho
// Analytics' own "Collection_Classification_Staging" view directly (same
// Bulk API export-job pattern as sync-ar-ledger; its viewId was already
// configured in config/zoho.php, just never wired to a sync command), then
// running the user's exact ranking/median SQL ourselves against our own
// copy of it. Same reasoning as the hourly cadence below for the other
// three: no longer waiting on Zoho Analytics' own once-daily Query Table
// output, we control the freshness ourselves now.
// Stopped 2026-10-19 (user-approved): only the /legacy pages read its result; the current pages use zoho_collection_classification,
// computed from the raw tables by zoho:refresh --nightly. Saved a ~38k-row export every hour (~2,700 API units a day).
// Schedule::command('reports:sync-collection-classification-staging')->hourly()->name('sync-collection-classification-staging')->withoutOverlapping(30);

Schedule::command('reports:compute-collection-classification')
    ->hourlyAt(10)
    ->name('compute-collection-classification')
    ->withoutOverlapping(15);

// Both pure local aggregates (profitability over ar_ledger_entries,
// documents over the customers table itself) — zero Zoho API calls, same
// reasoning as compute-sales-classification below for running hourly
// instead of once daily.
Schedule::command('reports:compute-profitability-classification')
    ->hourly()
    ->name('compute-profitability-classification')
    ->withoutOverlapping(15);

Schedule::command('reports:compute-documents-classification')
    ->hourly()
    ->name('compute-documents-classification')
    ->withoutOverlapping(15);

// Raised daily -> hourly (user request, 2026-10-06): unlike every other
// classification type, this one makes zero Zoho API calls — it's a pure
// local aggregate over ar_ledger_entries (already live/hourly itself), so
// there's no API-budget cost and no upstream-lag ceiling to respect.
// Running it once a day was just copying the other 3 types' cadence out of
// habit, not an actual constraint — a brand-new customer created mid-day
// used to show "Not synced yet" until the next 01:52 run; now at most an
// hour behind, same as the contacts/transactions sync.
Schedule::command('reports:compute-sales-classification')
    ->hourly()
    ->name('compute-sales-classification')
    ->withoutOverlapping(15);

// Runs last, at 02:15 — a full hour after sales_item_performance's dispatch
// at 01:00, giving its queued/chunked background sync (via the
// process-queued-imports ticks above) plenty of room to actually finish
// before this aggregates from it. Pure local SQL (no Zoho call), so this
// step itself is fast; also recomputes customer revenue/sales ranks, which
// depend on this table.
Schedule::command('reports:recompute-sales-item-monthly-performance')
    ->dailyAt('02:15')
    ->name('recompute-sales-item-monthly-performance')
    ->withoutOverlapping(10);

// Added 2026-10-05 after a manual audit found our 1,481 customers vs Zoho's
// real 1,482 — 3 genuinely missing (one sat 7+ days unsynced, a page-shift
// skip the hourly ZohoContactSyncJob can't self-correct — see
// ReconcileCustomers' own docblock) and 2 stale (a vendor that leaked in via
// the old one-time Excel import, a contact deleted in Zoho that nothing
// ever pruned). Raised from once-daily to every 4 hours the same day
// (manager request: add/edit/delete all need to stay "as fast and accurate
// as possible") once the command also started catching silently-skipped
// EDITS, not just missing/extra ids — that worst case otherwise waits a
// full day for however long the hourly job keeps skipping it. Still cheap:
// list-page calls only unless it finds a real delta (~8 calls per run most
// of the time), so 6x/day costs little. Offset 15 minutes past the hour so
// it never collides with the hourly ZohoSyncService tick itself.
Schedule::command('reports:reconcile-customers')
    ->cron('15 */4 * * *')
    ->name('reconcile-customers')
    ->withoutOverlapping(20);

// Same ReconcileCustomers pattern, applied to purchase orders (2026-10-14):
// SyncZohoTransactionTypeJob's incremental upsert can never notice a
// document deleted in Zoho (see ReconcileTransactions' own docblock) —
// confirmed live the same day this matters for real, not hypothetically:
// the first manual run found 45 purchase orders we still carried that no
// longer exist in Zoho. Purchase orders only for now (not sales/returns/
// invoices too) — explicit priority call the same day, since this is the
// type the manager specifically flagged as editable/deletable months after
// the fact (unlike sales, typically voided via a status change the
// incremental sync already catches). Cheap at this type's current size
// (~5,000 documents => ~25 list-page calls most runs), so every 4 hours
// costs little — offset 30 minutes past the hour so it doesn't collide
// with reconcile-customers (15 min) or the hourly ZohoSyncService tick.
Schedule::command('reports:reconcile-transactions purchase_orders')
    ->cron('30 */4 * * *')
    ->name('reconcile-purchase-orders')
    ->withoutOverlapping(20);

// Same mechanism, extended to invoices (2026-10-15): the hourly "_recent"
// watermark chain can silently skip an invoice the exact same way
// ReconcileCustomers' own docblock documents for contacts (an edit re-sorts
// the document onto a page the crawl already passed) — confirmed live the
// same day a batch of 29 very recent invoices (created within the last ~2
// hours) were still missing locally under heavy shared-throttle contention,
// with no safety net catching them since this command wasn't covering
// invoices yet. More expensive than purchase_orders at this type's size
// (~38,000 documents => ~190 list-page calls per run, vs ~25 for purchase
// orders), so NOT on the same tight 4-hour cadence — twice a day is enough
// to guarantee same-day convergence without meaningfully competing with the
// hourly transaction syncs' own budget. Offset to :45 so it never collides
// with reconcile-customers (:15) or reconcile-purchase-orders (:30).
Schedule::command('reports:reconcile-transactions invoices')
    ->cron('45 */12 * * *')
    ->name('reconcile-invoices')
    ->withoutOverlapping(30);

// Same mechanism, extended to sales orders (2026-10-16): confirmed live the
// same day that sales had zero reconcile coverage at all (unlike customers/
// purchase_orders/invoices above) — a manual run found 20 sales orders
// genuinely missing and ~160 more with stale (edited-in-Zoho-but-not-here)
// data, all from the pre-fix window before SyncZohoTransactionTypeJob's
// server-side last_modified_time filter went live. Similar scale to
// invoices (~32,000 documents => ~160 list-page calls per run), same twice-
// daily cadence for the same reason. Offset to :05 so it never collides
// with reconcile-customers (:15), reconcile-purchase-orders (:30), or
// reconcile-invoices (:45).
Schedule::command('reports:reconcile-transactions sales')
    ->cron('5 */12 * * *')
    ->name('reconcile-sales')
    ->withoutOverlapping(30);

// Entity 360 "Customer Actions" daily snapshot — the Lovable portal runs it at 06:00 Asia/Riyadh (pg_cron 03:00 UTC).
// A run takes minutes; the 4-hour overlap expiry self-heals a killed run (see the withoutOverlapping() note above).
Schedule::command('insights:snapshot')->dailyAt('06:00')->timezone('Asia/Riyadh')->name('insights-snapshot')->withoutOverlapping(240);

// Zoho Analytics → our copy of the raw tables + Dr. Ahmed's queries (Customer 360 / Entity 360 data), same cadence as the
// Lovable portal: hourly 08:00–19:00 Asia/Riyadh except Friday, plus a nightly run with the classifications. Incremental
// pulls only (--no-full inside zoho:refresh), so no run ever exports a whole table. A cycle takes ~10 minutes.
Schedule::command('zoho:refresh')->hourly()->between('08:00', '19:00')->days([0, 1, 2, 3, 4, 6])
    ->timezone('Asia/Riyadh')->name('zoho-analytics-refresh')->withoutOverlapping(90)->runInBackground();
Schedule::command('zoho:refresh --nightly')->dailyAt('23:00')->timezone('Asia/Riyadh')
    ->name('zoho-analytics-refresh-nightly')->withoutOverlapping(90)->runInBackground();

// Weekly full reload of every raw table, as the Lovable portal does (user: "exactly like them", 2026-10-19), so rows
// deleted in Zoho drop out of our copy — incremental pulls never see a deletion. Split over Friday + Saturday (see
// ZohoRawTables::WEEKLY_FULL); 03:15 Riyadh = just after the daily API-units reset (03:00). Staging + swap: a failed or
// short export leaves the live table untouched. The next zoho:refresh recomputes the queries.
// Disk: a full reload of accrual_transactions needs a second ~1.3 GB copy plus its binlog — MySQL binlog retention was cut
// to 1 day (2026-10-19) to leave room; check free space before adding big tables.
foreach (\App\Services\Zoho\ZohoRawTables::WEEKLY_FULL as $day => $keys) {
    Schedule::command('zoho:sync-raw '.implode(' ', $keys).' --full')->weeklyOn($day, '03:15')->timezone('Asia/Riyadh')
        ->name("zoho-analytics-full-reload-{$day}")->withoutOverlapping(300)->runInBackground();
}
