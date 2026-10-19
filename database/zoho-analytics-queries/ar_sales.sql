SELECT
		 MAX(CASE
				 WHEN AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  ) THEN AT."Transaction Date"
				 ELSE NULL
			 END) AS "Transaction Date",
		 MAX(CASE
				 WHEN AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  ) THEN AT."Transaction Number"
				 ELSE NULL
			 END) AS "Transaction Number",
		 MAX(CASE
				 WHEN AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  ) THEN AT."Entity Type"
				 ELSE NULL
			 END) AS "Entity Type",
		 
			/* =========================================================
		    CATEGORY
		 ========================================================= */
			CASE
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  ) THEN 1
						 ELSE 0
					 END)  = 1 THEN 'SALES'
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  IN ( 'customer_payment'  , 'invoice_payment'  , 'payment_refund'  , 'creditnote_refund'  ) THEN 1
						 ELSE 0
					 END)  = 1 THEN 'COLLECTION'
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  = 'customer_ob_invoice' THEN 1
						 ELSE 0
					 END)  = 1 THEN 'OPENING BALANCE'
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  = 'journal' THEN 1
						 ELSE 0
					 END)  = 1 THEN 'JOURNAL'
				 ELSE 'OTHER'
			 END AS "Category",
		 AT."Entity ID" AS "Entity ID",
		 MAX(CASE
				 WHEN AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  ) THEN AT."Transaction ID"
				 ELSE NULL
			 END) AS "Transaction ID",
		 MAX(AT."Customer ID") AS "Customer ID",
		 MAX(C."Customer Number") AS "Customer Number",
		 MAX(C."Customer Name") AS "Customer Name",
		 MAX(CASE
				 WHEN A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  ) THEN A."Account Name"
				 ELSE NULL
			 END) AS "AR Account Name",
		 MAX(CASE
				 WHEN A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  ) THEN A."Account Type"
				 ELSE NULL
			 END) AS "Account Type",
		 MAX(CASE
				 WHEN AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  ) THEN AT."Reference No."
				 ELSE NULL
			 END) AS "Reference No.",
		 MAX(CASE
				 WHEN AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  ) THEN AT."Debit or Credit"
				 ELSE NULL
			 END) AS "Debit or Credit",
		 /* =========================================================
		    STATEMENT AMOUNT
		 ========================================================= */ ROUND(SUM(CASE
				 WHEN AT."Entity Type"  = 'invoice'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'creditnote'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'customer_payment'
				 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
				 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'invoice_payment'
				 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
				 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'payment_refund'
				 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
				 AND	AT."Debit or Credit"  = 'credit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'creditnote_refund'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'customer_ob_invoice'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'journal'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'journal'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
				 ELSE 0
			 END), 2) AS "Statement Amount",
		 
			/* =========================================================
		    OPEN BALANCE (signed)
		    invoice / OB invoice  = open balance (+)
		    credit note           = unused credit (-)
		    customer payment      = unused amount (-)
		    journals are covered by the UNAPPLIED line in part 2
		 ========================================================= */
			CASE
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  = 'invoice' THEN 1
						 ELSE 0
					 END)  = 1 THEN MAX(INV."Balance (BCY)")
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  = 'creditnote' THEN 1
						 ELSE 0
					 END)  = 1 THEN NULLIF(-MAX(CN."Balance (BCY)"), 0)
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  = 'customer_ob_invoice' THEN 1
						 ELSE 0
					 END)  = 1 THEN ROUND(SUM(CASE
						 WHEN AT."Entity Type"  = 'customer_ob_invoice'
						 AND	A."Account Type"  = 'Accounts Receivable'
						 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END) -IFNULL(MAX(OBP."Paid"), 0) -IFNULL(MAX(OBC."Credited"), 0), 2)
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  IN ( 'customer_payment'  , 'invoice_payment'  ) THEN 1
						 ELSE 0
					 END)  = 1 THEN NULLIF(-MAX(CP."Unused Amount (BCY)"), 0)
				 ELSE NULL
			 END AS "Balance",
		 
			/* =========================================================
		    INVOICE BALANCE DUE DATE  (invoice + OB invoice)
		 ========================================================= */
			CASE
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  = 'invoice' THEN 1
						 ELSE 0
					 END)  = 1
				 AND	MAX(INV."Balance (BCY)")  > 0 THEN adddate(MAX(INV."Invoice Date"), MAX(CASE
						 WHEN C."Payments Due"  = 0
						 OR	C."Payments Due"  IS NULL THEN 30
						 ELSE C."Payments Due"
					 END))
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  = 'customer_ob_invoice' THEN 1
						 ELSE 0
					 END)  = 1
				 AND	ROUND(SUM(CASE
						 WHEN AT."Entity Type"  = 'customer_ob_invoice'
						 AND	A."Account Type"  = 'Accounts Receivable'
						 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END) -IFNULL(MAX(OBP."Paid"), 0) -IFNULL(MAX(OBC."Credited"), 0), 2)  > 0 THEN adddate(MAX(AT."Transaction Date"), MAX(CASE
						 WHEN C."Payments Due"  = 0
						 OR	C."Payments Due"  IS NULL THEN 30
						 ELSE C."Payments Due"
					 END))
				 ELSE NULL
			 END AS "Inv Balance Due Date",
		 /* =========================================================
		    SALES EXCLUDING VAT
		 ========================================================= */ ROUND(SUM(CASE
				 WHEN AT."Entity Type"  = 'invoice'
				 AND	A."Account Name"  = 'Sales المبيعات'
				 AND	AT."Debit or Credit"  = 'credit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'invoice'
				 AND	A."Account Name"  = 'Discount'
				 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'creditnote'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'creditnote'
				 AND	A."Account Name"  = 'Output VAT'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 ELSE 0
			 END), 2) AS "Sales Exc Vat",
		 /* =========================================================
		    COGS
		 ========================================================= */ ROUND(SUM(CASE
				 WHEN AT."Entity Type"  = 'inventory_valuation_for_invoice'
				 AND	A."Account Type"  = 'Cost Of Goods Sold'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'inventory_valuation_for_credit_notes'
				 AND	A."Account Type"  = 'Cost Of Goods Sold'
				 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
				 ELSE 0
			 END), 2) AS "COGS",
		 /* =========================================================
		    GROSS PROFIT
		 ========================================================= */ ROUND(CASE
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  = 'invoice' THEN 1
						 ELSE 0
					 END)  = 1 THEN SUM(CASE
						 WHEN AT."Entity Type"  = 'invoice'
						 AND	A."Account Name"  = 'Sales المبيعات'
						 AND	AT."Debit or Credit"  = 'credit' THEN ABS(AT."Transaction Amount (BCY)")
						 WHEN AT."Entity Type"  = 'invoice'
						 AND	A."Account Name"  = 'Discount'
						 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END) -SUM(CASE
						 WHEN AT."Entity Type"  = 'inventory_valuation_for_invoice'
						 AND	A."Account Type"  = 'Cost Of Goods Sold'
						 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END)
				 ELSE 0
			 END, 2) AS "Gross Profit",
		 /* =========================================================
		    GP %
		 ========================================================= */ ROUND(CASE
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  = 'invoice' THEN 1
						 ELSE 0
					 END)  = 1
				 AND	SUM(CASE
						 WHEN AT."Entity Type"  = 'invoice'
						 AND	A."Account Name"  = 'Sales المبيعات'
						 AND	AT."Debit or Credit"  = 'credit' THEN ABS(AT."Transaction Amount (BCY)")
						 WHEN AT."Entity Type"  = 'invoice'
						 AND	A."Account Name"  = 'Discount'
						 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END)  <> 0 THEN ((SUM(CASE
						 WHEN AT."Entity Type"  = 'invoice'
						 AND	A."Account Name"  = 'Sales المبيعات'
						 AND	AT."Debit or Credit"  = 'credit' THEN ABS(AT."Transaction Amount (BCY)")
						 WHEN AT."Entity Type"  = 'invoice'
						 AND	A."Account Name"  = 'Discount'
						 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END) -SUM(CASE
						 WHEN AT."Entity Type"  = 'inventory_valuation_for_invoice'
						 AND	A."Account Type"  = 'Cost Of Goods Sold'
						 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END)) / SUM(CASE
						 WHEN AT."Entity Type"  = 'invoice'
						 AND	A."Account Name"  = 'Sales المبيعات'
						 AND	AT."Debit or Credit"  = 'credit' THEN ABS(AT."Transaction Amount (BCY)")
						 WHEN AT."Entity Type"  = 'invoice'
						 AND	A."Account Name"  = 'Discount'
						 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END)) * 100
				 ELSE 0
			 END, 2) AS "GP %",
		 
			/* =========================================================
		    KPI AMOUNT
		 ========================================================= */
			CASE
				 WHEN MAX(CASE
						 WHEN AT."Entity Type"  IN ( 'journal'  , 'customer_ob_invoice'  ) THEN 1
						 ELSE 0
					 END)  = 1 THEN NULL
				 ELSE ROUND(SUM(CASE
						 WHEN AT."Entity Type"  = 'invoice'
						 AND	A."Account Type"  = 'Accounts Receivable'
						 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
						 WHEN AT."Entity Type"  = 'creditnote'
						 AND	A."Account Type"  = 'Accounts Receivable'
						 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
						 WHEN AT."Entity Type"  = 'customer_payment'
						 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
						 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
						 WHEN AT."Entity Type"  = 'invoice_payment'
						 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
						 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
						 WHEN AT."Entity Type"  = 'creditnote_refund'
						 AND	A."Account Type"  = 'Accounts Receivable'
						 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
						 WHEN AT."Entity Type"  = 'payment_refund'
						 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
						 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END), 2)
			 END AS "KPI Amount",
		 
			/* =========================================================
		    BALANCE AGING
		    كل مستند له رصيد مفتوح (+ أو -)
		    الفواتير حسب تاريخ الفاتورة، وباقي الأنواع حسب تاريخ الحركة
		 ========================================================= */
			CASE
				 WHEN (MAX(CASE
						 WHEN AT."Entity Type"  = 'invoice' THEN 1
						 ELSE 0
					 END)  = 1
				 AND	MAX(INV."Balance (BCY)")  > 0)
				 OR	(MAX(CASE
						 WHEN AT."Entity Type"  = 'creditnote' THEN 1
						 ELSE 0
					 END)  = 1
				 AND	MAX(CN."Balance (BCY)")  > 0)
				 OR	(MAX(CASE
						 WHEN AT."Entity Type"  = 'customer_ob_invoice' THEN 1
						 ELSE 0
					 END)  = 1
				 AND	ROUND(SUM(CASE
						 WHEN AT."Entity Type"  = 'customer_ob_invoice'
						 AND	A."Account Type"  = 'Accounts Receivable'
						 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
						 ELSE 0
					 END) -IFNULL(MAX(OBP."Paid"), 0) -IFNULL(MAX(OBC."Credited"), 0), 2)  <> 0)
				 OR	(MAX(CASE
						 WHEN AT."Entity Type"  IN ( 'customer_payment'  , 'invoice_payment'  ) THEN 1
						 ELSE 0
					 END)  = 1
				 AND	MAX(CP."Unused Amount (BCY)")  > 0) THEN 
					CASE
						 WHEN DATEDIFF(CURDATE(), CASE
								 WHEN MAX(CASE
										 WHEN AT."Entity Type"  = 'invoice' THEN 1
										 ELSE 0
									 END)  = 1 THEN MAX(INV."Invoice Date")
								 ELSE MAX(AT."Transaction Date")
							 END)  <= 30 THEN '1. 0-30'
						 WHEN DATEDIFF(CURDATE(), CASE
								 WHEN MAX(CASE
										 WHEN AT."Entity Type"  = 'invoice' THEN 1
										 ELSE 0
									 END)  = 1 THEN MAX(INV."Invoice Date")
								 ELSE MAX(AT."Transaction Date")
							 END)  <= 60 THEN '2. 31-60'
						 WHEN DATEDIFF(CURDATE(), CASE
								 WHEN MAX(CASE
										 WHEN AT."Entity Type"  = 'invoice' THEN 1
										 ELSE 0
									 END)  = 1 THEN MAX(INV."Invoice Date")
								 ELSE MAX(AT."Transaction Date")
							 END)  <= 90 THEN '3. 61-90'
						 WHEN DATEDIFF(CURDATE(), CASE
								 WHEN MAX(CASE
										 WHEN AT."Entity Type"  = 'invoice' THEN 1
										 ELSE 0
									 END)  = 1 THEN MAX(INV."Invoice Date")
								 ELSE MAX(AT."Transaction Date")
							 END)  <= 120 THEN '4. 91-120'
						 WHEN DATEDIFF(CURDATE(), CASE
								 WHEN MAX(CASE
										 WHEN AT."Entity Type"  = 'invoice' THEN 1
										 ELSE 0
									 END)  = 1 THEN MAX(INV."Invoice Date")
								 ELSE MAX(AT."Transaction Date")
							 END)  <= 150 THEN '5. 121-150'
						 WHEN DATEDIFF(CURDATE(), CASE
								 WHEN MAX(CASE
										 WHEN AT."Entity Type"  = 'invoice' THEN 1
										 ELSE 0
									 END)  = 1 THEN MAX(INV."Invoice Date")
								 ELSE MAX(AT."Transaction Date")
							 END)  <= 180 THEN '6. 151-180'
						 ELSE '7. >180'
					 END
				 ELSE NULL
			 END AS "BALANCE AGING"
FROM  "Accrual Transactions" AT
LEFT JOIN "Accounts" A ON AT."Account ID"  = A."Account ID" 
LEFT JOIN "Customers" C ON AT."Customer ID"  = C."Customer ID" 
LEFT JOIN "Invoices" INV ON AT."Entity ID"  = INV."Invoice ID" 
LEFT JOIN "Credit Notes" CN ON AT."Entity ID"  = CN."CreditNotes ID" 
LEFT JOIN "Customer Payments" CP ON AT."Entity ID"  = CP."Payment ID" 
LEFT JOIN(	SELECT
			 "Invoice ID",
			 SUM("Amount (BCY)") AS "Paid"
	FROM  "Invoice Payments" 
	GROUP BY  "Invoice ID" 
) OBP ON AT."Entity ID"  = OBP."Invoice ID" 
LEFT JOIN(	SELECT
			 "Invoice ID",
			 SUM("Amount (BCY)") AS "Credited"
	FROM  "Creditnotes Invoice" 
	GROUP BY  "Invoice ID" 
) OBC ON AT."Entity ID"  = OBC."Invoice ID"  
WHERE	 AT."Customer ID"  IS NOT NULL
 AND	AT."Customer ID"  <> ''
 AND	AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  , 'inventory_valuation_for_invoice'  , 'inventory_valuation_for_credit_notes'  )
 AND	NOT (AT."Entity Type"  = 'creditnote'
 AND	AT."Transaction ID"  IN
	(
 	SELECT DISTINCT X."Transaction ID"
	FROM  "Accrual Transactions" X
LEFT JOIN "Accounts" AX ON X."Account ID"  = AX."Account ID"  
	WHERE	 X."Entity Type"  = 'creditnote'
	 AND	AX."Account Name"  = 'Dimension Adjustments'
	))
 AND	(AT."Entity Type"  <> 'invoice_payment'
 OR	AT."Transaction ID"  IN
	(
 	SELECT DISTINCT IP."Transaction ID"
	FROM  "Accrual Transactions" IP
LEFT JOIN "Accounts" IA ON IP."Account ID"  = IA."Account ID"  
	WHERE	 IP."Entity Type"  = 'invoice_payment'
	 AND	IA."Account Type"  IN ( 'Bank'  , 'Cash'  )
	 AND	IP."Debit or Credit"  = 'debit'
	))
GROUP BY AT."Entity ID",
	  AT."Customer ID" 
UNION ALL
 /* =====================================================================
   PART 2 : UNAPPLIED line — one per customer
   = customer ledger balance − sum of open document balances
   (journal credits/debits and anything not applied to a document)
   Statement Amount = 0 so ledger totals are unchanged
===================================================================== */
SELECT
		 IFNULL(MAX(CASE
				 WHEN AT."Entity Type"  = 'journal' THEN AT."Transaction Date"
				 ELSE NULL
			 END), MAX(AT."Transaction Date")) AS "Transaction Date",
		 'UNAPPLIED' AS "Transaction Number",
		 'unapplied_balance' AS "Entity Type",
		 'UNAPPLIED' AS "Category",
		 0 AS "Entity ID",
		 0 AS "Transaction ID",
		 AT."Customer ID" AS "Customer ID",
		 MAX(C."Customer Number") AS "Customer Number",
		 MAX(C."Customer Name") AS "Customer Name",
		 NULL AS "AR Account Name",
		 'Accounts Receivable' AS "Account Type",
		 NULL AS "Reference No.",
		 NULL AS "Debit or Credit",
		 0 AS "Statement Amount",
		 ROUND(SUM(CASE
				 WHEN AT."Entity Type"  = 'invoice'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'creditnote'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'customer_payment'
				 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
				 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'invoice_payment'
				 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
				 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'payment_refund'
				 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
				 AND	AT."Debit or Credit"  = 'credit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'creditnote_refund'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'customer_ob_invoice'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'journal'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
				 WHEN AT."Entity Type"  = 'journal'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
				 ELSE 0
			 END) -SUM(CASE
				 WHEN AT."Entity Type"  = 'invoice'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN IFNULL(INV."Balance (BCY)", 0)
				 WHEN AT."Entity Type"  = 'creditnote'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'credit' THEN -IFNULL(CN."Balance (BCY)", 0)
				 WHEN AT."Entity Type"  = 'customer_ob_invoice'
				 AND	A."Account Type"  = 'Accounts Receivable'
				 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)") -IFNULL(OBP."Paid", 0) -IFNULL(OBC."Credited", 0)
				 WHEN AT."Entity Type"  IN ( 'customer_payment'  , 'invoice_payment'  )
				 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
				 AND	AT."Debit or Credit"  = 'debit' THEN -IFNULL(CP."Unused Amount (BCY)", 0)
				 ELSE 0
			 END), 2) AS "Balance",
		 NULL AS "Inv Balance Due Date",
		 NULL AS "Sales Exc Vat",
		 NULL AS "COGS",
		 NULL AS "Gross Profit",
		 NULL AS "GP %",
		 NULL AS "KPI Amount",
		 
			CASE
				 WHEN DATEDIFF(CURDATE(), IFNULL(MAX(CASE
						 WHEN AT."Entity Type"  = 'journal' THEN AT."Transaction Date"
						 ELSE NULL
					 END), MAX(AT."Transaction Date")))  <= 30 THEN '1. 0-30'
				 WHEN DATEDIFF(CURDATE(), IFNULL(MAX(CASE
						 WHEN AT."Entity Type"  = 'journal' THEN AT."Transaction Date"
						 ELSE NULL
					 END), MAX(AT."Transaction Date")))  <= 60 THEN '2. 31-60'
				 WHEN DATEDIFF(CURDATE(), IFNULL(MAX(CASE
						 WHEN AT."Entity Type"  = 'journal' THEN AT."Transaction Date"
						 ELSE NULL
					 END), MAX(AT."Transaction Date")))  <= 90 THEN '3. 61-90'
				 WHEN DATEDIFF(CURDATE(), IFNULL(MAX(CASE
						 WHEN AT."Entity Type"  = 'journal' THEN AT."Transaction Date"
						 ELSE NULL
					 END), MAX(AT."Transaction Date")))  <= 120 THEN '4. 91-120'
				 WHEN DATEDIFF(CURDATE(), IFNULL(MAX(CASE
						 WHEN AT."Entity Type"  = 'journal' THEN AT."Transaction Date"
						 ELSE NULL
					 END), MAX(AT."Transaction Date")))  <= 150 THEN '5. 121-150'
				 WHEN DATEDIFF(CURDATE(), IFNULL(MAX(CASE
						 WHEN AT."Entity Type"  = 'journal' THEN AT."Transaction Date"
						 ELSE NULL
					 END), MAX(AT."Transaction Date")))  <= 180 THEN '6. 151-180'
				 ELSE '7. >180'
			 END AS "BALANCE AGING"
FROM  "Accrual Transactions" AT
LEFT JOIN "Accounts" A ON AT."Account ID"  = A."Account ID" 
LEFT JOIN "Customers" C ON AT."Customer ID"  = C."Customer ID" 
LEFT JOIN "Invoices" INV ON AT."Entity ID"  = INV."Invoice ID" 
LEFT JOIN "Credit Notes" CN ON AT."Entity ID"  = CN."CreditNotes ID" 
LEFT JOIN "Customer Payments" CP ON AT."Entity ID"  = CP."Payment ID" 
LEFT JOIN(	SELECT
			 "Invoice ID",
			 SUM("Amount (BCY)") AS "Paid"
	FROM  "Invoice Payments" 
	GROUP BY  "Invoice ID" 
) OBP ON AT."Entity ID"  = OBP."Invoice ID" 
LEFT JOIN(	SELECT
			 "Invoice ID",
			 SUM("Amount (BCY)") AS "Credited"
	FROM  "Creditnotes Invoice" 
	GROUP BY  "Invoice ID" 
) OBC ON AT."Entity ID"  = OBC."Invoice ID"  
WHERE	 AT."Customer ID"  IS NOT NULL
 AND	AT."Customer ID"  <> ''
 AND	AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  , 'customer_payment'  , 'invoice_payment'  , 'customer_ob_invoice'  , 'journal'  , 'creditnote_refund'  , 'payment_refund'  , 'inventory_valuation_for_invoice'  , 'inventory_valuation_for_credit_notes'  )
 AND	NOT (AT."Entity Type"  = 'creditnote'
 AND	AT."Transaction ID"  IN
	(
 	SELECT DISTINCT X."Transaction ID"
	FROM  "Accrual Transactions" X
LEFT JOIN "Accounts" AX ON X."Account ID"  = AX."Account ID"  
	WHERE	 X."Entity Type"  = 'creditnote'
	 AND	AX."Account Name"  = 'Dimension Adjustments'
	))
 AND	(AT."Entity Type"  <> 'invoice_payment'
 OR	AT."Transaction ID"  IN
	(
 	SELECT DISTINCT IP."Transaction ID"
	FROM  "Accrual Transactions" IP
LEFT JOIN "Accounts" IA ON IP."Account ID"  = IA."Account ID"  
	WHERE	 IP."Entity Type"  = 'invoice_payment'
	 AND	IA."Account Type"  IN ( 'Bank'  , 'Cash'  )
	 AND	IP."Debit or Credit"  = 'debit'
	))
GROUP BY  AT."Customer ID" 
HAVING ABS(ROUND(SUM(CASE
		 WHEN AT."Entity Type"  = 'invoice'
		 AND	A."Account Type"  = 'Accounts Receivable'
		 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
		 WHEN AT."Entity Type"  = 'creditnote'
		 AND	A."Account Type"  = 'Accounts Receivable'
		 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
		 WHEN AT."Entity Type"  = 'customer_payment'
		 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
		 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
		 WHEN AT."Entity Type"  = 'invoice_payment'
		 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
		 AND	AT."Debit or Credit"  = 'debit' THEN -ABS(AT."Transaction Amount (BCY)")
		 WHEN AT."Entity Type"  = 'payment_refund'
		 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
		 AND	AT."Debit or Credit"  = 'credit' THEN ABS(AT."Transaction Amount (BCY)")
		 WHEN AT."Entity Type"  = 'creditnote_refund'
		 AND	A."Account Type"  = 'Accounts Receivable'
		 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
		 WHEN AT."Entity Type"  = 'customer_ob_invoice'
		 AND	A."Account Type"  = 'Accounts Receivable'
		 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
		 WHEN AT."Entity Type"  = 'journal'
		 AND	A."Account Type"  = 'Accounts Receivable'
		 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)")
		 WHEN AT."Entity Type"  = 'journal'
		 AND	A."Account Type"  = 'Accounts Receivable'
		 AND	AT."Debit or Credit"  = 'credit' THEN -ABS(AT."Transaction Amount (BCY)")
		 ELSE 0
	 END) -SUM(CASE
		 WHEN AT."Entity Type"  = 'invoice'
		 AND	A."Account Type"  = 'Accounts Receivable'
		 AND	AT."Debit or Credit"  = 'debit' THEN IFNULL(INV."Balance (BCY)", 0)
		 WHEN AT."Entity Type"  = 'creditnote'
		 AND	A."Account Type"  = 'Accounts Receivable'
		 AND	AT."Debit or Credit"  = 'credit' THEN -IFNULL(CN."Balance (BCY)", 0)
		 WHEN AT."Entity Type"  = 'customer_ob_invoice'
		 AND	A."Account Type"  = 'Accounts Receivable'
		 AND	AT."Debit or Credit"  = 'debit' THEN ABS(AT."Transaction Amount (BCY)") -IFNULL(OBP."Paid", 0) -IFNULL(OBC."Credited", 0)
		 WHEN AT."Entity Type"  IN ( 'customer_payment'  , 'invoice_payment'  )
		 AND	A."Account Type"  IN ( 'Bank'  , 'Cash'  )
		 AND	AT."Debit or Credit"  = 'debit' THEN -IFNULL(CP."Unused Amount (BCY)", 0)
		 ELSE 0
	 END), 2))  > 0.005
