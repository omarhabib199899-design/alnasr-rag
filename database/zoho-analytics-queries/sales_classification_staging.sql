SELECT
		 C."Customer ID" AS "Customer ID",
		 C."Customer Number" AS "Customer Number",
		 C."Customer Name" AS "Customer Name",
		 AT."Entity ID" AS "Entity ID",
		 AT."Entity Type" AS "Entity Type",
		 AT."Transaction ID" AS "Transaction ID",
		 AT."Transaction Number" AS "Transaction Number",
		 AT."Transaction Date" AS "Transaction Date",
		 AT."Account ID" AS "Account ID",
		 A."Account Name" AS "Account Name",
		 A."Account Type" AS "Account Type",
		 AT."Debit or Credit" AS "Debit or Credit",
		 AT."Transaction Amount (BCY)" AS "Transaction Amount (BCY)",
		 INV."Invoice Date" AS "Invoice Date",
		 INV."Balance (BCY)" AS "Invoice Balance",
		 CN."Balance (BCY)" AS "Credit Note Balance"
FROM  "Customers" C
LEFT JOIN "Accrual Transactions" AT ON C."Customer ID"  = AT."Customer ID"
	 AND	AT."Entity Type"  IN ( 'invoice'  , 'creditnote'  ) 
LEFT JOIN "Accounts" A ON AT."Account ID"  = A."Account ID" 
LEFT JOIN "Invoices" INV ON AT."Entity Type"  = 'invoice'
	 AND	AT."Entity ID"  = INV."Invoice ID" 
LEFT JOIN "Credit Notes" CN ON AT."Entity Type"  = 'creditnote'
	 AND	AT."Entity ID"  = CN."CreditNotes ID"  
WHERE	 (AT."Customer ID"  IS NULL
 OR	(A."Account Type"  = 'Accounts Receivable'
 AND	((AT."Entity Type"  = 'invoice'
 AND	AT."Debit or Credit"  = 'debit')
 OR	(AT."Entity Type"  = 'creditnote'
 AND	AT."Debit or Credit"  = 'credit'))))
 AND	NOT (((((((AT."Entity Type"  = 'creditnote'
 AND	AT."Transaction ID"  IN
	(
 	SELECT DISTINCT X."Transaction ID"
	FROM  "Accrual Transactions" X
LEFT JOIN "Accounts" AX ON X."Account ID"  = AX."Account ID"  
	WHERE	 X."Entity Type"  = 'creditnote'
	 AND	AX."Account Name"  = 'Dimension Adjustments'
	))))))))
ORDER BY C."Customer ID",
	 AT."Transaction Date",
	 AT."Transaction Number"
