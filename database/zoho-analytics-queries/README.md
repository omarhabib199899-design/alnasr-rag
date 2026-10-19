# Zoho Analytics query definitions (Dr. Ahmed)

Verbatim copies of the Zoho Analytics Query Tables that the Al-Nasr Lovable
portal reads. Manager directive (2026-10-19): these queries are stored and run
in our system; from Zoho we only pull the raw source tables, so a change made
inside Zoho Analytics can never silently change our numbers.

Do not edit these files to "fix" anything. They are the reference text, and
every port to MySQL must reproduce them exactly. If Dr. Ahmed changes a query,
replace the file with his new text.

| File | Zoho Analytics view | Source tables |
|---|---|---|
| ar_sales.sql | AR SALES (100722000005502296) | Accrual Transactions, Accounts, Customers, Invoices, Credit Notes, Customer Payments, Invoice Payments, Creditnotes Invoice |
| sales_orders_not_invoiced.sql | Sales Orders Not Invoiced (100722000005583108) | Sales Order Items, Sales Orders, Customers |
| ar_risk_reconciliation.sql | AR Risk Reconciliation (100722000005577374) | AR SALES |
| ar_risk_classification.sql | AR Risk Classification (100722000005577188) | AR SALES, Customers, AR Risk Reconciliation |
| sales_item_performance.sql | Sales Item Performance (100722000003888393) | Invoice Items, Invoices, Customers, Items, Batch Number Out, Batch Number IN, Stock Out Flow Table, FIFO Mapping Table, Credit Note Items, Credit Notes |
| sales_classification_staging.sql | Sales_Classification_Staging (100722000005134214) | Customers, Accrual Transactions, Accounts, Invoices, Credit Notes |
| sales_classification.sql | Sales_Classification (100722000005134285) | Sales_Classification_Staging |
| collection_classification_staging.sql | Collection_Classification_Staging (100722000005134302) | Invoices, Customers, Invoice Payments, Customer Payments, Creditnotes Invoice |
| collection_classification.sql | Collection_Classification (100722000005134335) | Collection_Classification_Staging |
| profitability_staging.sql | Profitability_Staging (100722000005134353) | Invoice Items, Invoices, Customers, Stock Out Flow Table, FIFO Mapping Table |
| profitability_classification.sql | Profitability_Classification (100722000005134370) | Profitability_Staging |
| documents_classification.sql | Documents_Classification (100722000005134399) | Customers |

Zoho Analytics SQL dialect notes for the MySQL port: double-quoted identifiers,
`ADDDATE(d, n)`, `MEDIAN()` (no MySQL equivalent, so it must be computed),
`CURDATE()` is Zoho's server date (to port as the Asia/Riyadh date).
