<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Credit Notes — "Credit Notes" sidebar item under Sales Data.
 * Per the manager's explicit instruction (2026-10-13 voice note): "الـ
 * Return... هنستخدم الـ Credit Note وتشيل الـ Return ده خالص" (we'll use
 * Credit Note and remove the old Return concept entirely) — "ال return =
 * credit note". This replaces the old "Returns" report (reports.transactions
 * 'returns', sourced from return_items — quantity/SKU only, no reliable
 * monetary value, same limitation documented on CustomerController's old
 * Return tab before it was rebuilt 2026-09-30) with a real, SAR-based list
 * sourced from ar_ledger_entries (category=SALES, entity_type=creditnote)
 * — same source the Customer/Sales Rep/Data Entry/Sales Owner pages'
 * "Credit Note" tab already uses. The old "Returns" nav link is hidden
 * (not deleted — same hold pattern as Customer Planning) per "تشيل الـ
 * Return خالص".
 */
class CreditNotesController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $sortableColumns = ['transaction_date', 'transaction_number', 'customer_name', 'statement_amount', 'updated_at'];
        $sort = in_array($request->query('sort'), $sortableColumns, true) ? $request->query('sort') : null;
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $applyFilters = function ($query) use ($search, $dateFrom, $dateTo) {
            $query->where('entity_type', 'creditnote')->where('category', 'SALES');

            if ($search !== null && $search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('transaction_number', 'like', "%{$search}%")
                        ->orWhere('customer_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%");
                });
            }

            if ($dateFrom !== null && $dateFrom !== '') {
                $query->whereDate('transaction_date', '>=', $dateFrom);
            }

            if ($dateTo !== null && $dateTo !== '') {
                $query->whereDate('transaction_date', '<=', $dateTo);
            }

            return $query;
        };

        $creditNotes = $applyFilters(DB::table('ar_ledger_entries'))
            ->select('transaction_number', 'transaction_date', 'customer_number', 'customer_name', 'statement_amount', 'updated_at')
            ->when($sort !== null, fn ($query) => $query->orderBy($sort, $direction))
            ->orderByDesc('transaction_date')
            ->paginate(50)
            ->withQueryString();

        $totalCount = $applyFilters(DB::table('ar_ledger_entries'))->count();
        $totalAmount = abs((float) $applyFilters(DB::table('ar_ledger_entries'))->sum('statement_amount'));
        $lastUpdated = DB::table('ar_ledger_entries')->where('entity_type', 'creditnote')->max('updated_at');

        // credit_note_items.document_number — confirmed live 2026-10-15 to
        // match ar_ledger_entries.transaction_number exactly (both "CN-xxxxx")
        // — used below to show a "View" link only for numbers we actually
        // have line-item detail for (reports:sync-credit-note-items keeps
        // this current; a credit note not yet synced there just shows no link).
        $hasItems = DB::table('credit_note_items')
            ->whereIn('document_number', $creditNotes->pluck('transaction_number')->filter())
            ->distinct()
            ->pluck('document_number')
            ->flip();

        return view('credit-notes.index', [
            'creditNotes' => $creditNotes,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'totalCount' => $totalCount,
            'totalAmount' => $totalAmount,
            'lastUpdated' => $lastUpdated,
            'hasItems' => $hasItems,
        ]);
    }

    /**
     * Line-item drill-down — sourced from credit_note_items (Zoho Analytics
     * "Credit Note Items" + "Credit Notes" views, joined by
     * reports:sync-credit-note-items), not ar_ledger_entries (document-level
     * only, no SKUs). Added 2026-10-15 per the manager's explicit ask:
     * Credit Notes needed the same "see what items are in it" access
     * Invoices already has.
     */
    public function show(string $documentNumber)
    {
        $items = DB::table('credit_note_items')
            ->where('document_number', $documentNumber)
            ->orderBy('sku')
            ->get();

        if ($items->isEmpty()) {
            abort(404);
        }

        $first = $items->first();

        return view('credit-notes.show', [
            'documentNumber' => $documentNumber,
            'items' => $items,
            'customerName' => $first->customer_name,
            'date' => $first->transaction_date,
            'status' => $first->status,
        ]);
    }
}
