<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Invoices — "Invoices" sidebar item under Sales Data. Replaces the old
 * per-SKU "Invoices" report (reports.transactions 'invoices', one row per
 * line item) as the nav destination — per the manager's explicit feedback
 * (2026-10-14, relayed live while comparing against Zoho's own invoice
 * list): "الفواتير هنا محطوطة بالصنف... مش بالفاتورة نفسها" (the invoices
 * here are laid out by item, not by the invoice itself) — he wants one row
 * per invoice, matching Zoho's own All Invoices screen (Date, Invoice#,
 * Customer Name, Status, Amount), not one row per SKU line.
 *
 * Sourced from invoice_documents (one row per invoice already — same
 * table Sales Operations uses) joined to customers for the name. The old
 * per-SKU report is left fully intact and still reachable directly
 * (/reports/invoices) — only the nav link moved, same hold pattern as
 * Customer Planning/the old Returns link.
 */
class InvoicesController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $sortableColumns = [
            'invoice_date' => 'invoice_documents.invoice_date',
            'invoice_number' => 'invoice_documents.invoice_number',
            'customer_name' => 'customers.customer_name',
            'status' => 'invoice_documents.status',
            'total' => 'invoice_documents.total',
            'updated_at' => 'invoice_documents.updated_at',
        ];
        $sort = array_key_exists($request->query('sort'), $sortableColumns) ? $sortableColumns[$request->query('sort')] : null;
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $applyFilters = function ($query) use ($search, $dateFrom, $dateTo) {
            if ($search !== null && $search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_documents.invoice_number', 'like', "%{$search}%")
                        ->orWhere('customers.customer_name', 'like', "%{$search}%");
                });
            }

            if ($dateFrom !== null && $dateFrom !== '') {
                $query->whereDate('invoice_documents.invoice_date', '>=', $dateFrom);
            }

            if ($dateTo !== null && $dateTo !== '') {
                $query->whereDate('invoice_documents.invoice_date', '<=', $dateTo);
            }

            return $query;
        };

        $baseQuery = fn () => $applyFilters(
            DB::table('invoice_documents')
                ->leftJoin('customers', 'customers.zoho_contact_id', '=', 'invoice_documents.zoho_customer_id')
        );

        $invoices = $baseQuery()
            ->select(
                'invoice_documents.invoice_number',
                'invoice_documents.invoice_date',
                'invoice_documents.status',
                'invoice_documents.total',
                'invoice_documents.balance',
                'invoice_documents.updated_at',
                'invoice_documents.zoho_created_time',
                'customers.customer_name'
            )
            // Secondary sort was invoice_documents.id DESC (our own row
            // insert order) — confirmed live 2026-10-15 this looked
            // "unsorted" within a single day once invoices started arriving
            // from TWO parallel lanes (Inventory + Books) out of real
            // creation order: our row id reflects whichever lane happened
            // to insert it first, not Zoho's own chronology. invoice_date
            // alone is day-only precision, so it can't break same-day ties
            // either. zoho_created_time carries full date+time straight
            // from Zoho, independent of which lane or what order we synced
            // it in — sorting by that instead gives a consistent, real
            // chronological order no matter how the two lanes interleave.
            ->when($sort !== null, fn ($query) => $query->orderBy($sort, $direction))
            ->orderByDesc('invoice_documents.invoice_date')
            ->orderByDesc('invoice_documents.zoho_created_time')
            ->paginate(50)
            ->withQueryString();

        $totalCount = $baseQuery()->count();
        $lastUpdated = DB::table('invoice_documents')->max('updated_at');

        return view('invoices.index', [
            'invoices' => $invoices,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'totalCount' => $totalCount,
            'lastUpdated' => $lastUpdated,
        ]);
    }
}
