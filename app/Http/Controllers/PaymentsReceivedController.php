<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payments Received — "Payments Received" sidebar item under Sales Data.
 *
 * Rebuilt 2026-10-14 to source from customer_payments (synced directly
 * from Zoho's own Customer Payments module — CustomerPaymentSyncJob),
 * replacing ar_ledger_entries per the manager's direction to stop
 * depending on the Zoho Analytics "AR Ledger Master" view for anything.
 * Seeded the same day from a direct Zoho export (15,397 real payments);
 * CustomerPaymentSyncJob keeps it current going forward via the hourly
 * ZohoSyncService cycle.
 *
 * Every real column from the export/API has its own filter field here —
 * per explicit user request, not just a single combined search box.
 */
class PaymentsReceivedController extends Controller
{
    public function index(Request $request)
    {
        $filterKeys = ['payment_number', 'customer_number', 'customer_name', 'reference_number', 'payment_mode', 'account_name', 'description', 'invoice_number'];
        $filters = array_merge(array_fill_keys($filterKeys, ''), $request->only($filterKeys));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $sortableColumns = [
            'payment_number' => 'customer_payments.payment_number',
            'payment_date' => 'customer_payments.payment_date',
            'customer_number' => 'customers.customer_number',
            'customer_name' => 'customer_payments.customer_name',
            'reference_number' => 'customer_payments.reference_number',
            'payment_mode' => 'customer_payments.payment_mode',
            'account_name' => 'customer_payments.account_name',
            'description' => 'customer_payments.description',
            'invoice_numbers' => 'customer_payments.invoice_numbers',
            'amount' => 'customer_payments.amount',
            'unused_amount' => 'customer_payments.unused_amount',
            'currency_code' => 'customer_payments.currency_code',
        ];
        $sort = array_key_exists($request->query('sort'), $sortableColumns) ? $sortableColumns[$request->query('sort')] : null;
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $applyFilters = function ($query) use ($filters, $dateFrom, $dateTo) {
            if (($filters['payment_number'] ?? '') !== '') {
                $query->where('customer_payments.payment_number', 'like', '%'.$filters['payment_number'].'%');
            }
            if (($filters['customer_number'] ?? '') !== '') {
                $query->where('customers.customer_number', 'like', '%'.$filters['customer_number'].'%');
            }
            if (($filters['customer_name'] ?? '') !== '') {
                $query->where('customer_payments.customer_name', 'like', '%'.$filters['customer_name'].'%');
            }
            if (($filters['reference_number'] ?? '') !== '') {
                $query->where('customer_payments.reference_number', 'like', '%'.$filters['reference_number'].'%');
            }
            if (($filters['payment_mode'] ?? '') !== '') {
                $query->where('customer_payments.payment_mode', 'like', '%'.$filters['payment_mode'].'%');
            }
            if (($filters['account_name'] ?? '') !== '') {
                $query->where('customer_payments.account_name', 'like', '%'.$filters['account_name'].'%');
            }
            if (($filters['description'] ?? '') !== '') {
                $query->where('customer_payments.description', 'like', '%'.$filters['description'].'%');
            }
            if (($filters['invoice_number'] ?? '') !== '') {
                $query->where('customer_payments.invoice_numbers', 'like', '%'.$filters['invoice_number'].'%');
            }

            if ($dateFrom !== null && $dateFrom !== '') {
                $query->whereDate('customer_payments.payment_date', '>=', $dateFrom);
            }

            if ($dateTo !== null && $dateTo !== '') {
                $query->whereDate('customer_payments.payment_date', '<=', $dateTo);
            }

            return $query;
        };

        $baseQuery = fn () => $applyFilters(
            DB::table('customer_payments')
                ->leftJoin('customers', 'customers.zoho_contact_id', '=', 'customer_payments.zoho_customer_id')
        );

        $payments = $baseQuery()
            ->select(
                'customer_payments.payment_number',
                'customer_payments.payment_date',
                'customer_payments.customer_name',
                'customer_payments.reference_number',
                'customer_payments.amount',
                'customer_payments.unused_amount',
                'customer_payments.payment_mode',
                'customer_payments.account_name',
                'customer_payments.description',
                'customer_payments.invoice_numbers',
                'customer_payments.currency_code',
                'customer_payments.has_attachment',
                'customers.customer_number'
            )
            ->when($sort !== null, fn ($query) => $query->orderBy($sort, $direction))
            ->orderByDesc('customer_payments.payment_date')
            ->orderByDesc('customer_payments.id')
            ->paginate(50)
            ->withQueryString();

        $totalCount = $baseQuery()->count();
        $totalAmount = (float) $baseQuery()->sum('customer_payments.amount');
        $lastUpdated = DB::table('customer_payments')->max('updated_at');

        return view('payments-received.index', array_merge([
            'payments' => $payments,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'totalCount' => $totalCount,
            'totalAmount' => $totalAmount,
            'lastUpdated' => $lastUpdated,
        ], $filters));
    }
}
