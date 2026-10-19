<?php

namespace App\Services\Stats;

use App\Models\Import;

class CurrentInventory
{
    /**
     * This system serves exactly one company (Al Nasr Modern Trading) —
     * confirmed directly with the manager, 2026-10-14, specifically because
     * a brand-new user signing up saw an empty "Never synced — upload a
     * file" dashboard despite thousands of real, live-synced company
     * records already existing: every query in this class was scoped to
     * "this exact user's own imports", so anyone who hadn't personally
     * triggered a sync saw nothing.
     *
     * It isn't safe to simply drop user-scoping back to a plain org-wide
     * query, though (that was the pre-2026-09-30 design, reverted for a
     * real cross-tenant leak — see the old currentInventoryImportId()
     * docblock, kept below). This user_id still has 3,339 Import rows
     * sitting in this same table from a SECOND, unrelated Zoho
     * organization (934352151, zohoapis.com — a different country's Zoho
     * domain even, not just a different org id, vs. Al Nasr's
     * 150000054021 on zohoapis.sa) — that connection's status is "error"
     * or (not actively syncing), but its historical rows never got deleted.
     * An org-wide query would silently resurface that other company's old
     * numbers mixed into Al Nasr's dashboard for every viewer, which is
     * worse than the empty state it would replace (confidently wrong
     * instead of obviously empty).
     *
     * So: excluded by user_id, not scoped TO one — every OTHER user
     * (current and future Al Nasr staff, whichever of their own accounts
     * actually ran a sync or upload) sees the shared company data normally.
     * This is a one-company stopgap, not a real tenant system — once this
     * app is ever sold to a second company, this whole class needs a real
     * Company/tenant model instead (per the longstanding feedback memory
     * on this), and this constant should be the first thing revisited.
     */
    private const EXCLUDED_FOREIGN_TENANT_USER_ID = 15;

    /**
     * Exposed so other company-wide-but-not-quite-tenant-safe queries
     * (e.g. DashboardController's currency lookup) can exclude the same
     * foreign account without duplicating the magic user id in a second
     * place.
     */
    public static function excludedForeignTenantUserId(): int
    {
        return self::EXCLUDED_FOREIGN_TENANT_USER_ID;
    }

    /**
     * Zoho sync jobs have accumulated more than one Import `type` string
     * per subject over time (an original type plus a newer "_recent"
     * incremental variant, and — for sales specifically — an older
     * "sales_orders" name from before it was renamed) — confirmed live
     * 2026-09-30 against the imports table. relevantImportIds() must match
     * all of them, or real synced data silently disappears from the
     * dashboard depending on which variant name a given run used.
     */
    private const TYPE_VARIANTS = [
        'sales' => ['sales', 'sales_recent', 'sales_orders'],
        'purchase_orders' => ['purchase_orders', 'purchase_orders_recent'],
        'returns' => ['returns', 'returns_recent'],
        'inventory' => ['inventory'],
    ];

    /**
     * "Current" data for stats purposes is whatever the most recent
     * completed import of that type produced. Historical/versioned views
     * are out of scope for this MVP.
     *
     * $userId is unused now — see EXCLUDED_FOREIGN_TENANT_USER_ID's
     * docblock — kept in the signature only so every existing caller
     * (DashboardController, PurchaseRecommendationBuilder,
     * ChatAnswerService) keeps working unchanged; it's company-wide
     * (minus the one excluded foreign account) for whoever calls this now.
     */
    public function latestImportId(int $userId, string $type = 'inventory'): ?int
    {
        return Import::where('user_id', '!=', self::EXCLUDED_FOREIGN_TENANT_USER_ID)
            ->where('type', $type)
            ->where('status', 'completed')
            ->latest()
            ->value('id');
    }

    /**
     * The current inventory snapshot every Al Nasr user should see — the
     * latest completed import company-wide (see
     * EXCLUDED_FOREIGN_TENANT_USER_ID's docblock for why "company-wide"
     * here still means "minus one specific excluded account", not a plain
     * org-wide query).
     */
    public function currentInventoryImportId(int $userId): ?int
    {
        return $this->latestImportId($userId, 'inventory');
    }

    /**
     * Import ids whose rows together make up "current" data for a type.
     *
     * A Zoho sync run is not a full snapshot the way an Excel upload is —
     * it's one incremental slice of whatever changed since the last run,
     * split across many small Import rows over time (including ones still
     * "processing" mid-backfill, which have already persisted real rows via
     * persistIncremental). So for Zoho-sourced transaction types, "current"
     * means every Zoho Import row for this type, not just the latest one —
     * otherwise a run that happened to find zero new records makes the
     * dashboard/chat report zero for everything, even with real data
     * sitting under an earlier Import id. Confirmed live: Returns showed 0
     * units returned while 1 real Zoho return existed under an older
     * Import id, because the newest sync run found nothing new.
     *
     * Inventory stays single-import: Zoho inventory sync replaces the whole
     * snapshot each run (not incremental), so "latest" already is the full
     * current picture — same as the Excel path.
     *
     * 'zoho_export' (manual report exports, e.g. Purchase Orders by Item —
     * used to backfill history without competing with the account-wide API
     * rate limit) counts as Zoho-origin data here too: it's still one
     * incremental slice of the same underlying transaction history, just
     * fetched via a file instead of the live API.
     *
     * Company-wide (minus the one excluded foreign account — see
     * EXCLUDED_FOREIGN_TENANT_USER_ID), not scoped to $userId — same
     * reasoning as latestImportId(). Still matches every known
     * type-name variant (TYPE_VARIANTS), which is the part of the original
     * 2026-09-30 fix that was never about tenant isolation in the first
     * place and stays correct either way.
     */
    public function relevantImportIds(int $userId, string $type): array
    {
        if ($type === 'inventory') {
            $id = $this->currentInventoryImportId($userId);

            return $id !== null ? [$id] : [];
        }

        $variants = self::TYPE_VARIANTS[$type] ?? [$type];
        $zohoIds = Import::where('user_id', '!=', self::EXCLUDED_FOREIGN_TENANT_USER_ID)
            ->whereIn('type', $variants)
            ->whereIn('source', ['zoho', 'zoho_export'])
            ->pluck('id')
            ->all();

        if (! empty($zohoIds)) {
            return $zohoIds;
        }

        $id = $this->latestImportId($userId, $type);

        return $id !== null ? [$id] : [];
    }
}
