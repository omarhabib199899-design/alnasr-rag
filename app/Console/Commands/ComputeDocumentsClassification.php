<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replaces Zoho Analytics' "Documents Classification" Query Table (see
 * SyncCustomerClassifications, type=documents) with our own computation —
 * same request as Sales/Profitability (2026-10-06), full SQL pasted by the
 * user matches this file's logic exactly.
 *
 * Unlike every other classification, this one needs ZERO transaction data —
 * it's a pure compliance check over fields already live on the customers
 * row itself (fixed 2026-10-05 to stay current via ZohoContactSyncJob/
 * ReconcileCustomers). That means it can run far more often than the
 * Zoho-sourced daily batch ever did, and never has the "customer missing
 * entirely" gap Sales Classification had — every customer has a customers
 * row by definition.
 *
 * Two tiers, first match wins (Critical outranks Attention Required):
 * Critical  — Finance Dept Recommendation set, CR expiring <=30 days,
 *             reconciliation >60 days stale, missing Tax Registration
 *             Number, missing CR Number.
 * Attention — missing GLN Number, MOH SFDA License expiring <=30 days,
 *             missing Branch 1 City/Location, missing Warehouse City.
 * Else PASS.
 */
class ComputeDocumentsClassification extends Command
{
    protected $signature = 'reports:compute-documents-classification';

    protected $description = 'Compute Documents Classification ourselves from the customers table (replaces the Zoho Analytics "documents" Query Table import)';

    public function handle(): int
    {
        $today = Carbon::today();
        $customers = Customer::all();
        $this->info('Checking documents compliance for '.$customers->count().' customers...');

        $now = now();
        $batch = [];

        foreach ($customers as $c) {
            $extra = $c->extra ?? [];
            $financeRec = $extra['finance_dept_recommendation'] ?? null;
            $branch1City = $extra['branches'][0]['city'] ?? null;
            $branch1Location = $extra['branches'][0]['location'] ?? null;

            // Date comparisons done as lte()/lt() against an explicit
            // shifted boundary rather than a signed diffInDays() — Carbon's
            // sign convention for diffInDays($other, false) is easy to get
            // backwards, and getting it backwards here would silently
            // invert every Critical/Attention flag.
            $crExpiringSoon = $c->cr_expiry_date !== null
                && Carbon::parse($c->cr_expiry_date)->startOfDay()->lte($today->copy()->addDays(30));

            $reconciliationStale = $c->last_reconciliation_date !== null
                && Carbon::parse($c->last_reconciliation_date)->startOfDay()->lt($today->copy()->subDays(60));

            $mohExpiringSoon = $c->moh_sfda_license_expiry_date !== null
                && Carbon::parse($c->moh_sfda_license_expiry_date)->startOfDay()->lte($today->copy()->addDays(30));

            $isCritical = ($financeRec !== null && $financeRec !== '')
                || $crExpiringSoon
                || $reconciliationStale
                || ($c->tax_registration_number === null || $c->tax_registration_number === '')
                || ($c->cr_no === null || $c->cr_no === '');

            $isAttention = ($c->gln_no === null || $c->gln_no === '')
                || $mohExpiringSoon
                || ($branch1City === null || $branch1City === '')
                || ($branch1Location === null || $branch1Location === '')
                || ($c->warehouse_city === null || $c->warehouse_city === '');

            $classification = $isCritical ? 'Critical' : ($isAttention ? 'Attention Required' : 'PASS');

            $batch[] = [
                'customer_number' => $c->customer_number,
                'customer_name' => $c->customer_name,
                'type' => 'documents',
                'classification' => $classification,
                'data' => json_encode([
                    'finance_recommendation_status' => ($financeRec !== null && $financeRec !== '') ? 'Critical' : 'PASS',
                    'cr_expiry_status' => $crExpiringSoon ? 'Critical' : 'PASS',
                    'reconciliation_status' => $reconciliationStale ? 'Critical' : 'PASS',
                    'tax_registration_status' => ($c->tax_registration_number === null || $c->tax_registration_number === '') ? 'Critical' : 'PASS',
                    'cr_number_status' => ($c->cr_no === null || $c->cr_no === '') ? 'Critical' : 'PASS',
                    'gln_status' => ($c->gln_no === null || $c->gln_no === '') ? 'Attention Required' : 'PASS',
                    'moh_sfda_license_status' => $mohExpiringSoon ? 'Attention Required' : 'PASS',
                    'branch_1_city_status' => ($branch1City === null || $branch1City === '') ? 'Attention Required' : 'PASS',
                    'location_branch_1_status' => ($branch1Location === null || $branch1Location === '') ? 'Attention Required' : 'PASS',
                    'wh_city_status' => ($c->warehouse_city === null || $c->warehouse_city === '') ? 'Attention Required' : 'PASS',
                ]),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($batch, 500) as $chunk) {
            DB::table('customer_classifications')->upsert(
                $chunk,
                ['customer_number', 'type'],
                ['customer_name', 'classification', 'data', 'synced_at', 'updated_at']
            );
        }

        $this->info('Done — '.count($batch).' documents classifications computed and written.');

        return self::SUCCESS;
    }
}
