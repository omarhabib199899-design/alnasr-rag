<?php

namespace App\Http\Controllers;

/**
 * Sales KPIs documentation — "Sales KPIs" sidebar item under Sales Data.
 * Per the manager's explicit ask (2026-10-13 voice note): a table listing
 * every KPI shown on the Sales tab (Customer / Sales Rep / Data Entry /
 * Sales Owner pages all share the same formulas) with how each one is
 * actually calculated — matching the real formulas implemented in
 * CustomerController::buildSalesKpiTable() (and its per-page siblings)
 * exactly, not a separate description that could drift from the code.
 * Static/read-only — no DB query needed, this is documentation of the
 * computation, not computed data itself.
 *
 * Rebuilt 2026-10-18 as a searchable/filterable KPI directory (one row per
 * KPI, expandable to show Formula/Rules/Applicable Periods/Data Source) to
 * match the reference "Lovable" portal's design — replacing the earlier
 * flat per-tab tables that dumped raw SQL per row. Every "X / Y / Z"
 * combined label from that earlier version is split into its own entry
 * here (e.g. "Payment Count / Average Payment Value / Largest Payment
 * Value" -> 3 separate KPIs) since the reference design is one row per
 * single KPI concept — no new KPIs invented, this is the exact same real
 * content, just split and restructured.
 */
class SalesKpisController extends Controller
{
    public function index()
    {
        return view('sales-kpis.index', ['kpis' => $this->kpis()]);
    }

    /**
     * @return array<int, array{
     *     name: string, category: string, summary: string, formula: string,
     *     portfolio_formula: ?string, rules: array<int, string>,
     *     periods: array<int, string>, status: string,
     *     data_source: array{table: string, columns: array<int, string>}
     * }>
     */
    private function kpis(): array
    {
        $standardPeriods = ['Lifetime', 'Previous Year', 'YTD', 'Previous Month', 'MTD', 'Yesterday', 'Today'];
        $avgSeriesPeriods = ['Lifetime', 'Previous Year', 'YTD', 'Previous Month', 'MTD'];
        $liveSnapshot = ['Live Snapshot'];
        $approved = 'Approved & Implemented';

        return [
            // ---- Sales (ar_ledger_entries, Lifetime = this customer/portfolio's own first sale) ----
            [
                'name' => 'Net Sales', 'category' => 'Sales', 'status' => $approved,
                'summary' => 'Total sales for the period, net of credit notes/returns.',
                'formula' => "Net Sales = SUM(statement_amount) of invoice and credit note rows with transaction_date in the period",
                'portfolio_formula' => "Portfolio Net Sales = SUM(statement_amount) over every eligible row of the portfolio's customers (one aggregate, not an average of customer values)",
                'rules' => [
                    "Eligible rows: category = 'SALES' AND entity_type IN ('invoice', 'creditnote').",
                    'Invoices contribute their positive statement_amount; credit note amounts are stored negative, so the sum nets automatically — not subtracted a second time.',
                    'Only rows whose transaction_date falls within the selected period are included.',
                    "Scope: Customer page = one zoho_customer_id. Sales Rep / Data Entry / Sales Owner page = every customer whose rep_name / data_entry / sales_owner matches exactly, summed as one portfolio total.",
                ],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Gross Sales', 'category' => 'Sales', 'status' => $approved,
                'summary' => 'Total sales before deducting any credit notes/returns.',
                'formula' => 'Gross Sales = SUM(statement_amount) of invoice rows only',
                'portfolio_formula' => null,
                'rules' => [
                    "Eligible rows: category = 'SALES' AND entity_type = 'invoice' — credit notes are excluded entirely, not just netted.",
                    'Only rows whose transaction_date falls within the selected period are included.',
                ],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Sales Average', 'category' => 'Sales', 'status' => $approved,
                'summary' => 'The typical amount of sales per month or per day across the period.',
                'formula' => 'Sales Average = AVG(of the zero-filled monthly or daily Net Sales series for the period)',
                'portfolio_formula' => null,
                'rules' => [
                    'Net Sales is split into one value per calendar month (Lifetime / Previous Year / YTD) or per calendar day (Previous Month / MTD), including months/days with zero sales.',
                    'Yesterday and Today cover a single day each, so no average applies there.',
                ],
                'periods' => $avgSeriesPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Sales Median', 'category' => 'Sales', 'status' => $approved,
                'summary' => "The \"middle\" month/day in the same series used for Sales Average — less skewed by one unusually big or small month than the average.",
                'formula' => 'Sales Median = median(of the same monthly/daily Net Sales series as Sales Average)',
                'portfolio_formula' => null,
                'rules' => ['Sort the series ascending and take the middle value, or the average of the two middle values if the count is even.'],
                'periods' => $avgSeriesPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Revenue Volatility %', 'category' => 'Sales', 'status' => $approved,
                'summary' => 'How irregular buying is from month to month (or day to day). Higher = more unpredictable; lower = more steady.',
                'formula' => 'Revenue Volatility % = (sample standard deviation of the series ÷ average of the series) × 100',
                'portfolio_formula' => null,
                'rules' => ['Uses the same monthly/daily Net Sales series as Sales Average.', 'Sample standard deviation divides by (N-1), not N.'],
                'periods' => $avgSeriesPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Revenue Contribution %', 'category' => 'Sales', 'status' => $approved,
                'summary' => "What share of the whole company's Net Sales this customer/rep/owner/data-entry person accounts for, in the same period.",
                'formula' => "Revenue Contribution % = (this row's own Net Sales ÷ company-wide Net Sales for the same period) × 100",
                'portfolio_formula' => null,
                'rules' => ['Company-wide denominator has no zoho_customer_id filter — every eligible SALES row company-wide in the same period.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date']],
            ],
            [
                'name' => 'Revenue Rank - Company', 'category' => 'Sales', 'status' => $approved,
                'summary' => 'Where this row ranks by Net Sales among everyone else, for the same period.',
                'formula' => "Rank = this row's position when everyone with positive Net Sales in the period is sorted highest-to-lowest",
                'portfolio_formula' => null,
                'rules' => ['Only rows with positive Net Sales in the period are ranked at all.', 'The total shown alongside the rank is how many had positive Net Sales in that period.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Invoice Count', 'category' => 'Sales', 'status' => $approved,
                'summary' => 'How many invoices were issued in the period (credit notes are not counted here).',
                'formula' => 'Invoice Count = COUNT(invoice rows) in the period',
                'portfolio_formula' => null,
                'rules' => ["Only entity_type = 'invoice' rows are counted; credit notes are excluded."],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Average Invoice Value', 'category' => 'Sales', 'status' => $approved,
                'summary' => 'The typical size of a single invoice in the period.',
                'formula' => 'Average Invoice Value = Gross Sales ÷ Invoice Count',
                'portfolio_formula' => null,
                'rules' => ['Uses the Gross Sales and Invoice Count figures above, for the same period.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Largest Invoice', 'category' => 'Sales', 'status' => $approved,
                'summary' => 'The single biggest invoice issued in the period.',
                'formula' => 'Largest Invoice = MAX(statement_amount) of invoice rows in the period',
                'portfolio_formula' => null,
                'rules' => ["Only entity_type = 'invoice' rows are considered."],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],

            // ---- Profitability (ar_ledger_entries, Lifetime is company-wide unbounded — NOT capped to first sale) ----
            [
                'name' => 'Gross Profit', 'category' => 'Profitability', 'status' => $approved,
                'summary' => 'Total profit for the period, net of credit notes.',
                'formula' => 'Gross Profit = SUM(sales_exc_vat) − SUM(cogs) across invoice + credit note rows',
                'portfolio_formula' => null,
                'rules' => [
                    'NOT the stored gross_profit column — that column is zeroed on every credit note at the Zoho source, so it is recomputed here instead.',
                    'Only rows with sales_exc_vat not null are included.',
                    "Lifetime here is company-wide unbounded, not capped to this customer's first sale — a different Lifetime convention than the Sales tab's.",
                ],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['sales_exc_vat', 'cogs', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Gross Margin %', 'category' => 'Profitability', 'status' => $approved,
                'summary' => 'Gross Profit as a percentage of sales.',
                'formula' => 'Gross Margin % = (Gross Profit ÷ Sales) × 100',
                'portfolio_formula' => null,
                'rules' => ['Uses the Gross Profit and Sales figures from the row above, for the same period.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['sales_exc_vat', 'cogs', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'GP Contribution %', 'category' => 'Profitability', 'status' => $approved,
                'summary' => "This customer's share of the whole company's Gross Profit in the period.",
                'formula' => "GP Contribution % = (this row's own Gross Profit ÷ company-wide Gross Profit) × 100",
                'portfolio_formula' => null,
                'rules' => ['Company-wide denominator has no zoho_customer_id filter.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['sales_exc_vat', 'cogs', 'entity_type', 'transaction_date']],
            ],
            [
                'name' => 'GP Rank - Company', 'category' => 'Profitability', 'status' => $approved,
                'summary' => 'Where this customer ranks by Gross Profit among everyone else.',
                'formula' => 'Rank = this row\'s position when everyone with positive Gross Profit in the period is sorted highest-to-lowest',
                'portfolio_formula' => null,
                'rules' => ['Only rows with positive Gross Profit in the period are ranked.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['sales_exc_vat', 'cogs', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Average GP% per Invoice', 'category' => 'Profitability', 'status' => $approved,
                'summary' => 'The typical profit margin of a single invoice, rather than the margin on total sales.',
                'formula' => 'Average GP% per Invoice = AVG(per-invoice Gross Profit ÷ Sales × 100)',
                'portfolio_formula' => null,
                'rules' => [
                    'Uses the stored gross_profit column, invoice rows only — credit notes are excluded here, unlike the Gross Profit KPI above.',
                    'Computed per invoice (grouped by transaction_number), then averaged across invoices in the period.',
                ],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['sales_exc_vat', 'gross_profit', 'transaction_number', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],

            // ---- Collection (ar_ledger_entries category=COLLECTION, Lifetime = own first collection) ----
            [
                'name' => 'Collection', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'Total amount collected from this customer in the period.',
                'formula' => 'Collection = SUM(kpi_amount) across every COLLECTION-category row in the period',
                'portfolio_formula' => null,
                'rules' => ['kpi_amount is always positive, so payments and refunds net automatically within the sum.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'category', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Collection Ratio vs Sales', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'How much was collected compared to how much was sold in the period.',
                'formula' => 'Collection Ratio vs Sales = (Collection ÷ Net Sales) × 100',
                'portfolio_formula' => null,
                'rules' => ["Net Sales denominator uses the same definition as the Sales tab's Net Sales KPI, for the same period."],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Collection Average', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'The typical amount collected per month or per day across the period — same idea as Sales Average, applied to collections.',
                'formula' => 'Collection Average = AVG(of the zero-filled monthly/daily Collection series)',
                'portfolio_formula' => null,
                'rules' => ['Built the same way as Sales Average, from a monthly (Lifetime/Previous Year/YTD) or daily (Previous Month/MTD) Collection series.'],
                'periods' => $avgSeriesPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'category', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Collection Median', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'The "middle" month/day in the same series used for Collection Average.',
                'formula' => 'Collection Median = median(of the same monthly/daily Collection series as Collection Average)',
                'portfolio_formula' => null,
                'rules' => ['Sort the series ascending and take the middle value, or the average of the two middle values if the count is even.'],
                'periods' => $avgSeriesPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'category', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Collection Volatility %', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'How irregular collections are from month to month (or day to day).',
                'formula' => 'Collection Volatility % = (sample standard deviation of the series ÷ average of the series) × 100',
                'portfolio_formula' => null,
                'rules' => ['Same sample-standard-deviation convention as Revenue Volatility %, applied to the Collection series.'],
                'periods' => $avgSeriesPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'category', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Collection Contribution %', 'category' => 'Collection', 'status' => $approved,
                'summary' => "This customer's share of the whole company's collections in the period.",
                'formula' => "Collection Contribution % = (this customer's Collection ÷ company-wide Collection) × 100",
                'portfolio_formula' => null,
                'rules' => ['Company-wide denominator has no zoho_customer_id filter.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'category', 'transaction_date']],
            ],
            [
                'name' => 'Collection Rank - Company', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'Where this customer ranks by amount collected among everyone else.',
                'formula' => 'Rank = this row\'s position when everyone with positive Collection in the period is sorted highest-to-lowest',
                'portfolio_formula' => null,
                'rules' => ['Only rows with positive Collection in the period are ranked.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'category', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Payment Count', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'How many real payments came in — excludes credit note refunds.',
                'formula' => 'Payment Count = COUNT(payment rows) in the period',
                'portfolio_formula' => null,
                'rules' => ["Restricted to entity_type IN ('customer_payment', 'invoice_payment') — real payment rows only."],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Average Payment Value', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'The average size of a single payment in the period.',
                'formula' => 'Average Payment Value = AVG(kpi_amount) across payment rows in the period',
                'portfolio_formula' => null,
                'rules' => ['Same payment-row restriction as Payment Count.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Largest Payment Value', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'The biggest single payment in the period.',
                'formula' => 'Largest Payment Value = MAX(kpi_amount) across payment rows in the period',
                'portfolio_formula' => null,
                'rules' => ['Same payment-row restriction as Payment Count.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['kpi_amount', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Average Payment Interval', 'category' => 'Collection', 'status' => $approved,
                'summary' => 'The typical number of days between one payment and the next.',
                'formula' => 'Average Payment Interval = AVG(day-gaps between consecutive distinct payment dates in the period)',
                'portfolio_formula' => null,
                'rules' => [
                    'Built from DISTINCT payment transaction_date values, ordered chronologically.',
                    'Needs at least 2 distinct payment dates in the period to compute — otherwise shown as —.',
                ],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['transaction_date', 'entity_type', 'zoho_customer_id']],
            ],

            // ---- Accounts Receivable (invoice_documents, standard periods, draft excluded) ----
            [
                'name' => 'Outstanding AR', 'category' => 'Accounts Receivable', 'status' => $approved,
                'summary' => "Total unpaid balance across this customer's invoices.",
                'formula' => 'Outstanding AR = SUM(balance) across non-draft invoices in the period',
                'portfolio_formula' => null,
                'rules' => [
                    "For \"Lifetime\" specifically, uses the same corrected Outstanding Balance shown elsewhere on the customer page (ar_ledger_entries-based), not a raw sum of invoice_documents.balance.",
                    "status != 'draft' always applies.",
                ],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'invoice_documents', 'columns' => ['balance', 'status', 'invoice_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Outstanding Invoices Count', 'category' => 'Accounts Receivable', 'status' => $approved,
                'summary' => 'How many invoices still have a balance owed.',
                'formula' => 'Outstanding Invoices Count = COUNT(non-draft invoices with balance > 0) in the period',
                'portfolio_formula' => null,
                'rules' => ["status != 'draft' AND balance > 0."],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'invoice_documents', 'columns' => ['balance', 'status', 'invoice_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Outstanding Contribution % (Company)', 'category' => 'Accounts Receivable', 'status' => $approved,
                'summary' => "This customer's share of the whole company's outstanding AR.",
                'formula' => "Outstanding Contribution % = (this customer's Outstanding AR ÷ company-wide Outstanding AR) × 100",
                'portfolio_formula' => null,
                'rules' => ['Company-wide denominator has no zoho_customer_id filter.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'invoice_documents', 'columns' => ['balance', 'status', 'invoice_date']],
            ],
            [
                'name' => 'Overdue Amount', 'category' => 'Accounts Receivable', 'status' => $approved,
                'summary' => 'How much is both unpaid AND already past its due date.',
                'formula' => 'Overdue Amount = SUM(balance) where balance > 0 AND due_date < today',
                'portfolio_formula' => null,
                'rules' => ['Same non-draft + balance > 0 base as Outstanding AR, plus an overdue-date filter.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'invoice_documents', 'columns' => ['balance', 'status', 'due_date', 'invoice_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Overdue Count', 'category' => 'Accounts Receivable', 'status' => $approved,
                'summary' => 'How many invoices are both unpaid and past due.',
                'formula' => 'Overdue Count = COUNT(invoices) where balance > 0 AND due_date < today',
                'portfolio_formula' => null,
                'rules' => ['Same filter as Overdue Amount, counted instead of summed.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'invoice_documents', 'columns' => ['balance', 'status', 'due_date', 'invoice_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Overdue Ratio %', 'category' => 'Accounts Receivable', 'status' => $approved,
                'summary' => 'What share of the Outstanding AR is overdue.',
                'formula' => 'Overdue Ratio % = (Overdue Amount ÷ Outstanding AR) × 100',
                'portfolio_formula' => null,
                'rules' => ['Uses the Overdue Amount and Outstanding AR figures above, same period.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'invoice_documents', 'columns' => ['balance', 'status', 'due_date', 'invoice_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Overdue Contribution %', 'category' => 'Accounts Receivable', 'status' => $approved,
                'summary' => "This customer's share of the whole company's overdue balance.",
                'formula' => "Overdue Contribution % = (this customer's Overdue Amount ÷ company-wide Overdue Amount) × 100",
                'portfolio_formula' => null,
                'rules' => ['Company-wide denominator has no zoho_customer_id filter.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'invoice_documents', 'columns' => ['balance', 'status', 'due_date', 'invoice_date']],
            ],
            [
                'name' => 'Due This Month', 'category' => 'Accounts Receivable', 'status' => $approved,
                'summary' => 'Everything owed with a due date on or before the end of this month — includes already-overdue balances too, by explicit management direction.',
                'formula' => 'Due This Month = SUM(balance) where due_date <= end of current calendar month',
                'portfolio_formula' => null,
                'rules' => ['Not restricted to the selected reporting period — always evaluated against the current calendar month, regardless of which period tab is open.'],
                'periods' => $liveSnapshot,
                'data_source' => ['table' => 'invoice_documents', 'columns' => ['balance', 'status', 'due_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Due This Month Ratio %', 'category' => 'Accounts Receivable', 'status' => $approved,
                'summary' => 'Due This Month as a share of total Outstanding AR.',
                'formula' => 'Due This Month Ratio % = (Due This Month ÷ Outstanding AR) × 100',
                'portfolio_formula' => null,
                'rules' => ['Uses the live Outstanding AR figure, not period-scoped.'],
                'periods' => $liveSnapshot,
                'data_source' => ['table' => 'invoice_documents', 'columns' => ['balance', 'status', 'due_date', 'invoice_date', 'zoho_customer_id']],
            ],

            // ---- Due Ages (ar_ledger_entries, live snapshot bucketed by Zoho's own aging label) ----
            [
                'name' => 'Aging Buckets (0-30 / 31-60 / 61-90 / 91-120 / 121-150 / 151-180 / >180)', 'category' => 'Due Ages', 'status' => $approved,
                'summary' => 'How much unpaid balance falls into each "how many days overdue" bucket, and how many invoices.',
                'formula' => 'Bucket Total = SUM(balance) grouped by balance_aging label; Bucket Count = COUNT(invoice rows) in that same bucket',
                'portfolio_formula' => null,
                'rules' => [
                    "Every unpaid row's own Zoho-provided aging bucket label (balance_aging) decides which bucket it falls into — not recomputed from due_date here.",
                    'Total sums balance across both invoice and credit note rows; Count only counts invoice rows.',
                    'Only rows with balance IS NOT NULL AND balance != 0 AND balance_aging IS NOT NULL are included.',
                ],
                'periods' => $liveSnapshot,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['entity_type', 'balance', 'balance_aging', 'category', 'zoho_customer_id']],
            ],

            // ---- Credit Limit (customers + ar_ledger_entries, live snapshot + fixed DSO windows) ----
            [
                'name' => 'Credit Limit', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => "The customer's approved credit limit.",
                'formula' => 'Credit Limit = customers.credit_limit (live snapshot)',
                'portfolio_formula' => null,
                'rules' => ['Read directly from the customer record, not computed.'],
                'periods' => $liveSnapshot,
                'data_source' => ['table' => 'customers', 'columns' => ['credit_limit']],
            ],
            [
                'name' => 'Used Credit', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => 'How much of the credit limit is currently used.',
                'formula' => 'Used Credit = SUM(statement_amount) across ALL ar_ledger_entries rows for this customer, every category, since the account began',
                'portfolio_formula' => null,
                'rules' => ['No category/entity_type filter — every ledger movement ever recorded for this customer.'],
                'periods' => $liveSnapshot,
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'zoho_customer_id']],
            ],
            [
                'name' => 'Remaining Credit', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => 'How much of the limit is left to use.',
                'formula' => 'Remaining Credit = Credit Limit − Used Credit',
                'portfolio_formula' => null,
                'rules' => ['Uses the Credit Limit and Used Credit figures above.'],
                'periods' => $liveSnapshot,
                'data_source' => ['table' => 'customers + ar_ledger_entries', 'columns' => ['credit_limit', 'statement_amount']],
            ],
            [
                'name' => 'Credit Utilization %', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => 'How much of the limit is in use, as a percentage.',
                'formula' => 'Credit Utilization % = (Used Credit ÷ Credit Limit) × 100',
                'portfolio_formula' => null,
                'rules' => [],
                'periods' => $liveSnapshot,
                'data_source' => ['table' => 'customers + ar_ledger_entries', 'columns' => ['credit_limit', 'statement_amount']],
            ],
            [
                'name' => 'Available Credit %', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => 'How much of the limit is still available, as a percentage.',
                'formula' => 'Available Credit % = 100 − Credit Utilization %',
                'portfolio_formula' => null,
                'rules' => [],
                'periods' => $liveSnapshot,
                'data_source' => ['table' => 'customers + ar_ledger_entries', 'columns' => ['credit_limit', 'statement_amount']],
            ],
            [
                'name' => 'Credit Status', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => 'A plain-English status label summarizing credit risk.',
                'formula' => 'Status thresholds: <50% Low, <80% Medium, <100% High, =100% Fully Used, >100% Over Limit',
                'portfolio_formula' => null,
                'rules' => ['Overridden to "Block" if the customer is flagged Severe Delay (Collection classification) or Lost (Sales classification), regardless of utilization %.'],
                'periods' => $liveSnapshot,
                'data_source' => ['table' => 'customers + ar_ledger_entries + customer_classifications', 'columns' => ['credit_limit', 'statement_amount', 'classification']],
            ],
            [
                'name' => 'DSO (12M)', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => 'Days Sales Outstanding over the trailing 12 months — roughly how many days of sales the current unpaid balance represents.',
                'formula' => 'DSO (12M) = (Used Credit ÷ credit sales in the trailing-12-month window) × days in that window',
                'portfolio_formula' => null,
                'rules' => ["The window start is capped to this customer's own first sale if shorter than 12 months."],
                'periods' => ['Trailing 12 Months'],
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['sales_exc_vat', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'DSO (6M)', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => 'Same as DSO (12M), over a trailing 6-month window.',
                'formula' => 'DSO (6M) = (Used Credit ÷ credit sales in the trailing-6-month window) × days in that window',
                'portfolio_formula' => null,
                'rules' => ["The window start is capped to this customer's own first sale if shorter than 6 months."],
                'periods' => ['Trailing 6 Months'],
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['sales_exc_vat', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'DSO (3M)', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => 'Same as DSO (12M), over a trailing 3-month window.',
                'formula' => 'DSO (3M) = (Used Credit ÷ credit sales in the trailing-3-month window) × days in that window',
                'portfolio_formula' => null,
                'rules' => ["The window start is capped to this customer's own first sale if shorter than 3 months."],
                'periods' => ['Trailing 3 Months'],
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['sales_exc_vat', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Recommended Credit Limit', 'category' => 'Credit Limit', 'status' => $approved,
                'summary' => "A suggested limit based on this customer's real average monthly sales and payment terms — shows as Blocked (0 SAR) for high-risk customers instead.",
                'formula' => 'Recommended Credit Limit = avg_monthly_net_sales × ((payment_terms_days + 15) ÷ 30) + avg_monthly_net_sales × 0.20',
                'portfolio_formula' => null,
                'rules' => [
                    'avg_monthly_net_sales = trailing-12-month Net Sales ÷ months elapsed.',
                    'The 15-day grace period and 20% safety margin are policy placeholders, not confirmed company figures.',
                ],
                'periods' => ['Trailing 12 Months'],
                'data_source' => ['table' => 'ar_ledger_entries', 'columns' => ['statement_amount', 'category', 'entity_type', 'transaction_date', 'zoho_customer_id']],
            ],

            // ---- Item Performance (sales_item_performance + item_owners, standard periods) ----
            [
                'name' => 'Net Sales (Item Performance)', 'category' => 'Item Performance', 'status' => $approved,
                'summary' => 'How much this customer bought from this item Owner.',
                'formula' => "Net Sales = SUM(item_total) for that Owner's rows in the period",
                'portfolio_formula' => null,
                'rules' => [
                    "Scoped to transaction_type = 'Invoice' rows joined to item_owners by zoho_item_id.",
                    'This is NOT the same Net Sales as the Sales tab — it is item-level, grouped per Owner/Purchaser.',
                ],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'sales_item_performance + item_owners', 'columns' => ['owner', 'transaction_number', 'item_total', 'zoho_item_id', 'transaction_type', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Avg Sales (Item Performance)', 'category' => 'Item Performance', 'status' => $approved,
                'summary' => 'The average sale per invoice from this item Owner.',
                'formula' => 'Avg Sales = Net Sales ÷ distinct invoice count, for that Owner',
                'portfolio_formula' => null,
                'rules' => ['Uses the same rows as the Net Sales (Item Performance) KPI.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'sales_item_performance + item_owners', 'columns' => ['owner', 'transaction_number', 'item_total', 'zoho_item_id', 'transaction_type', 'transaction_date']],
            ],
            [
                'name' => 'Sales Contribution % (Item Performance)', 'category' => 'Item Performance', 'status' => $approved,
                'summary' => "This Owner's share of this customer's total purchases across all Owners.",
                'formula' => "Sales Contribution % = (this Owner's Net Sales ÷ this customer's total Net Sales across all Owners) × 100",
                'portfolio_formula' => null,
                'rules' => ['Contribution is against THIS CUSTOMER\'s own total across all Owners, not company-wide.'],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'sales_item_performance + item_owners', 'columns' => ['owner', 'item_total', 'zoho_customer_id', 'transaction_date']],
            ],
            [
                'name' => 'SKU Count', 'category' => 'Item Performance', 'status' => $approved,
                'summary' => 'How many distinct products this customer bought from that Owner.',
                'formula' => "SKU Count = COUNT(DISTINCT zoho_item_id) for that Owner's rows",
                'portfolio_formula' => null,
                'rules' => [],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'sales_item_performance + item_owners', 'columns' => ['owner', 'zoho_item_id', 'transaction_date']],
            ],
            [
                'name' => 'SKU Avg Sales', 'category' => 'Item Performance', 'status' => $approved,
                'summary' => 'The average sales per distinct product bought from that Owner.',
                'formula' => 'SKU Avg Sales = Net Sales ÷ SKU Count, for that Owner',
                'portfolio_formula' => null,
                'rules' => [],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'sales_item_performance + item_owners', 'columns' => ['owner', 'item_total', 'zoho_item_id', 'transaction_date']],
            ],
            [
                'name' => 'Profit %', 'category' => 'Item Performance', 'status' => $approved,
                'summary' => 'The profit margin on this customer\'s purchases from that Owner.',
                'formula' => "Profit % = (SUM(item_gross_profit) ÷ SUM(item_total)) × 100, for that Owner's rows",
                'portfolio_formula' => null,
                'rules' => [],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'sales_item_performance + item_owners', 'columns' => ['item_gross_profit', 'item_total', 'owner', 'transaction_date', 'zoho_customer_id']],
            ],
            [
                'name' => 'Profit Contribution % (Item Performance)', 'category' => 'Item Performance', 'status' => $approved,
                'summary' => "This Owner's share of this customer's total profit across all Owners.",
                'formula' => "Profit Contribution % = (this Owner's profit ÷ this customer's total profit across all Owners) × 100",
                'portfolio_formula' => null,
                'rules' => ["Contribution is against this customer's own total profit across all Owners, not company-wide."],
                'periods' => $standardPeriods,
                'data_source' => ['table' => 'sales_item_performance + item_owners', 'columns' => ['item_gross_profit', 'item_total', 'owner', 'transaction_date', 'zoho_customer_id']],
            ],
        ];
    }
}
