<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoApiThrottle;
use App\Services\Zoho\ZohoContactClient;
use App\Services\Zoho\ZohoContactMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Full periodic sweep against Zoho's live Contacts list — the safety net the
 * hourly ZohoContactSyncJob structurally cannot be, because that job only
 * ever moves forward through pages/watermarks. Two confirmed, real gaps that
 * design leaves open (both caught live 2026-10-05, comparing our 1,481
 * customers against Zoho's real 1,482):
 *
 * 1. A contact that shifts to a LATER page between two sync runs (Zoho
 *    re-sorts every page by last_modified_time on every call — a burst of
 *    edits elsewhere pushes it back) can land on a page this job has already
 *    passed. Since pages only move forward, that contact is never looked at
 *    again — not a brief delay, a permanent skip. Confirmed: CUS-1519 and
 *    CUS-1520 sat missing for 7+ days this way. This is the exact same
 *    failure mode SyncZohoTransactionTypeJob's own docblock documents for
 *    transactions (QT-78368) — contacts share the identical page/watermark
 *    design, so they share the identical blind spot.
 * 2. A contact deleted in Zoho has no "deletion" signal in this sync design
 *    at all — upserts only ever add or update, nothing ever checks whether
 *    a zoho_contact_id we hold is still real. Confirmed: CUS-1477 ("TEST
 *    HAYTHAM") stayed in our customers table indefinitely after being
 *    deleted in Zoho.
 *
 * This command fixes both by comparing full ID lists rather than trusting
 * any incremental position: cheap (list-page calls only, ~8 for the whole
 * org) to build BOTH sides, then only the actual deltas cost a real API
 * call — fetchDetail() for a newly-found id, nothing at all for a missing
 * one (flagged via zoho_missing_since, never deleted — see that column's
 * migration docblock for why).
 *
 * A third case (added 2026-10-05, same request that asked "add/edit/delete
 * all need to stay fast and accurate"): an EDIT to a contact we already
 * have can hit this exact same page-shift skip — the id never goes
 * missing, so the two checks above wouldn't catch it, but the hourly job
 * still never re-fetched it, leaving stale data sitting under a real id
 * indefinitely. fetchPage()'s summary rows already carry last_modified_time
 * at no extra API cost (same field the hourly job's own watermark already
 * reads) — comparing it against our stored zoho_last_modified_time for
 * every id present on both sides catches this for free during the same
 * sweep, re-fetching detail only for the actual mismatches.
 *
 * Throttle-aware (added 2026-10-05, same "continuity" request — "what
 * happens if the API limit runs out"): every job that calls Zoho checks
 * ZohoApiThrottle first except this one did not, which was a real gap on
 * two counts — it could blow through the shared 500/hour budget invisibly
 * (nothing here was counted against it, starving the hourly contacts/
 * transaction jobs that DO check it), and if Zoho's real quota ran out
 * mid-run, every remaining fetchDetail() in the backfill/stale-edit loops
 * would fail one by one for nothing rather than stopping cleanly. Now hits
 * the same shared counter on every call and bails out of a loop the
 * moment the budget is gone, reporting how many were skipped — the
 * schedule's next run (at most 4 hours later) picks up exactly where this
 * one stopped, since nothing here is destructive or order-dependent.
 */
class ReconcileCustomers extends Command
{
    protected $signature = 'reports:reconcile-customers';

    protected $description = 'Compare our customers table against Zoho\'s full live Contacts list; backfill anything missing, catch up anything edited but silently skipped, flag anything no longer in Zoho';

    private const THROTTLE_WAIT_DELAY_SECONDS = 60;

    // Shorter than ReconcileTransactions' 10 (2026-10-17) — this sweep
    // only needs ~8 list calls for the whole org (see class docblock), so
    // it has a much better chance of finding a brief gap in a shared
    // budget than a type needing ~190. No need to hold a cron slot for 10
    // minutes chasing room this cheap a job rarely needs that long to find.
    private const THROTTLE_WAIT_MAX_ATTEMPTS = 4;

    public function handle(ZohoContactClient $client, ZohoContactMapper $mapper, ZohoApiThrottle $throttle): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        // Retries instead of a single check-and-bail (2026-10-17) —
        // confirmed live this exact command had been silently failing on
        // its own 4-hour schedule repeatedly (00:15, 04:15, 08:15, 12:15
        // the same day) purely because the single throttle check kept
        // losing the same timing lottery ReconcileTransactions' own
        // docblock already describes for its :30 mark. Same safe-to-wait
        // reasoning as that class: this runs directly off cron's
        // schedule:run, not the host's time-capped queue:work.
        for ($attempt = 1; $throttle->tooManyAttempts($connection); $attempt++) {
            if ($attempt > self::THROTTLE_WAIT_MAX_ATTEMPTS) {
                $message = 'reports:reconcile-customers: Zoho API budget still exhausted after waiting — skipping this run entirely. Next scheduled run will retry.';
                $this->warn($message);
                Log::warning($message); // scheduled runs pipe console output to /dev/null — see routes/console.php
                return self::FAILURE;
            }
            $this->warn('Zoho API budget exhausted — waiting '.self::THROTTLE_WAIT_DELAY_SECONDS."s for room (attempt {$attempt}/".self::THROTTLE_WAIT_MAX_ATTEMPTS.')...');
            sleep(self::THROTTLE_WAIT_DELAY_SECONDS);
        }

        $this->info('Fetching full live contact list from Zoho...');
        $zohoLastModified = []; // contact_id => last_modified_time (raw string, Zoho's own +0300 offset)
        $budgetRanOutDuringList = false;

        try {
            $page = 1;
            $hasMore = true;
            while ($hasMore) {
                if ($throttle->tooManyAttempts($connection)) {
                    $budgetRanOutDuringList = true;
                    break;
                }
                $throttle->hit($connection);
                $result = $client->fetchPage($connection, $page);
                foreach ($result['contacts'] as $c) {
                    $id = (string) ($c['contact_id'] ?? '');
                    if ($id !== '') {
                        $zohoLastModified[$id] = $c['last_modified_time'] ?? null;
                    }
                }
                $hasMore = $result['has_more'];
                $page++;
            }
        } catch (Throwable $e) {
            $this->error('Failed to fetch live contact list: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($budgetRanOutDuringList) {
            // Partial list is actively dangerous here, not just incomplete —
            // every id on a page never reached would look "missing from
            // Zoho" and get flagged, when really this run just never looked.
            // Bail out before any comparison happens at all.
            $message = 'reports:reconcile-customers: Zoho API budget ran out while still fetching the contact list — aborting before any comparison (a partial list would wrongly flag real customers as missing). Next scheduled run will retry.';
            $this->warn($message);
            Log::warning($message);

            return self::FAILURE;
        }

        $zohoIds = array_keys($zohoLastModified);
        $this->info('Zoho live contacts: '.count($zohoIds));

        $ourRows = Customer::query()->pluck('zoho_last_modified_time', 'zoho_contact_id');
        $ourIds = $ourRows->keys()->map(fn ($v) => (string) $v)->all();
        $this->info('Our customers: '.count($ourIds));

        $missingFromUs = array_diff($zohoIds, $ourIds);
        $missingFromZoho = array_diff($ourIds, $zohoIds);

        // Present on both sides — compare timestamps to catch an edit the
        // hourly job's own watermark silently skipped (see class docblock).
        // ->utc() on both sides is load-bearing, same reason
        // SyncZohoTransactionTypeJob's watermark commit needs it: Zoho's
        // last_modified_time carries a +0300 offset untouched, and nothing
        // here normalizes it unless done explicitly.
        $presentInBoth = array_intersect($zohoIds, $ourIds);
        $staleEdits = [];
        foreach ($presentInBoth as $contactId) {
            $zohoTime = $zohoLastModified[$contactId] ?? null;
            $ourTime = $ourRows[$contactId] ?? null;
            if ($zohoTime === null) {
                continue;
            }
            try {
                $zohoParsed = Carbon::parse($zohoTime)->utc();
                $ourParsed = $ourTime !== null ? Carbon::parse($ourTime)->utc() : null;
                if ($ourParsed === null || $zohoParsed->gt($ourParsed)) {
                    $staleEdits[] = $contactId;
                }
            } catch (Throwable) {
                // Unparseable timestamp on either side — skip rather than
                // guess; this id just waits for the hourly job or the next
                // sweep instead of risking a wrong comparison.
            }
        }

        $now = now();
        $inserted = 0;
        $skippedBackfills = 0;
        foreach ($missingFromUs as $contactId) {
            if ($throttle->tooManyAttempts($connection)) {
                $skippedBackfills = count($missingFromUs) - $inserted;
                $this->warn("  budget ran out — {$skippedBackfills} new customer(s) left for the next run");
                break;
            }
            try {
                $throttle->hit($connection);
                $detail = $client->fetchDetail($connection, $contactId);
                $attributes = $mapper->mapToCustomerAttributes($detail, $now);
                Customer::query()->updateOrCreate(['zoho_contact_id' => $contactId], $attributes);
                $inserted++;
                $this->line("  + backfilled {$attributes['customer_number']} - {$attributes['customer_name']}");
            } catch (Throwable $e) {
                $this->warn("  failed to backfill {$contactId}: ".$e->getMessage());
            }
        }

        $updated = 0;
        $skippedStaleEdits = 0;
        foreach ($staleEdits as $contactId) {
            if ($throttle->tooManyAttempts($connection)) {
                $skippedStaleEdits = count($staleEdits) - $updated;
                $this->warn("  budget ran out — {$skippedStaleEdits} stale edit(s) left for the next run");
                break;
            }
            try {
                $throttle->hit($connection);
                $detail = $client->fetchDetail($connection, $contactId);
                $attributes = $mapper->mapToCustomerAttributes($detail, $now);
                Customer::query()->where('zoho_contact_id', $contactId)->update($attributes);
                $updated++;
                $this->line("  ~ caught up stale edit: {$attributes['customer_number']} - {$attributes['customer_name']}");
            } catch (Throwable $e) {
                $this->warn("  failed to refresh {$contactId}: ".$e->getMessage());
            }
        }

        // Flag, never delete (standing practice — see zoho_missing_since's
        // migration docblock). Only touches rows not already flagged, so a
        // customer that's been missing across several sweeps keeps its
        // ORIGINAL missing-since date, not the latest sweep's date.
        $newlyFlagged = Customer::query()
            ->whereIn('zoho_contact_id', $missingFromZoho)
            ->whereNull('zoho_missing_since')
            ->update(['zoho_missing_since' => $now]);

        // A previously-flagged row that reappeared in this sweep is real
        // again (e.g. was undeleted in Zoho, or an earlier sweep's
        // transient API hiccup wrongly flagged it) — clear the flag.
        $unflagged = Customer::query()
            ->whereIn('zoho_contact_id', $zohoIds)
            ->whereNotNull('zoho_missing_since')
            ->update(['zoho_missing_since' => null]);

        $this->info("Backfilled (new): {$inserted}".($skippedBackfills > 0 ? " ({$skippedBackfills} skipped — budget, retried next run)" : ''));
        $this->info("Caught up (stale edit): {$updated}".($skippedStaleEdits > 0 ? " ({$skippedStaleEdits} skipped — budget, retried next run)" : ''));
        $this->info('Newly flagged as missing from Zoho: '.$newlyFlagged);
        $this->info('Un-flagged (reappeared): '.$unflagged);
        $this->info('Total customers now: '.Customer::count());

        return self::SUCCESS;
    }
}
