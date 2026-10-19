SELECT
		 A."Customer ID" AS "Customer ID",
		 MAX(A."Customer Number") AS "Customer Number",
		 MAX(A."Customer Name") AS "Customer Name",
		 COUNT(*) AS "Total Transactions",
		 /* 1. CUSTOMER LEDGER */ ROUND(SUM(IFNULL(A."Statement Amount", 0)), 2) AS "Statement Net AR",
		 /* 2. OPEN INVOICES */ ROUND(SUM(CASE
				 WHEN A."Entity Type"  IN ( 'invoice'  , 'customer_ob_invoice'  )
				 AND	A."Balance"  > 0 THEN A."Balance"
				 ELSE 0
			 END), 2) AS "Open Invoice AR",
		 /* 3. UNUSED DOCUMENT CREDITS */ ROUND(SUM(CASE
				 WHEN A."Entity Type"  IN ( 'creditnote'  , 'customer_payment'  , 'invoice_payment'  )
				 AND	A."Balance"  < 0 THEN A."Balance"
				 ELSE 0
			 END), 2) AS "Unused Credits",
		 /* 4. POSITIVE UNAPPLIED */ ROUND(SUM(CASE
				 WHEN A."Entity Type"  = 'unapplied_balance'
				 AND	A."Balance"  > 0 THEN A."Balance"
				 ELSE 0
			 END), 2) AS "Positive Unapplied",
		 /* 5. NEGATIVE UNAPPLIED */ ROUND(SUM(CASE
				 WHEN A."Entity Type"  = 'unapplied_balance'
				 AND	A."Balance"  < 0 THEN A."Balance"
				 ELSE 0
			 END), 2) AS "Negative Unapplied",
		 /* 6. NET UNAPPLIED */ ROUND(SUM(CASE
				 WHEN A."Entity Type"  = 'unapplied_balance' THEN IFNULL(A."Balance", 0)
				 ELSE 0
			 END), 2) AS "Net Unapplied",
		 /* 7. TOTAL CUSTOMER BALANCE */ ROUND(SUM(IFNULL(A."Balance", 0)), 2) AS "Total Balance AR",
		 /* 8. RECONCILIATION DIFFERENCE */ ROUND(SUM(IFNULL(A."Statement Amount", 0)) -SUM(IFNULL(A."Balance", 0)), 2) AS "Reconciliation Difference",
		 /* 9. AVAILABLE CREDIT FOR OFFSET */ ROUND(SUM(CASE
				 WHEN A."Entity Type"  IN ( 'creditnote'  , 'customer_payment'  , 'invoice_payment'  )
				 AND	A."Balance"  < 0 THEN -A."Balance"
				 ELSE 0
			 END) + SUM(CASE
				 WHEN A."Entity Type"  = 'unapplied_balance'
				 AND	A."Balance"  < 0 THEN -A."Balance"
				 ELSE 0
			 END), 2) AS "Available Credit for Offset",
		 /* 10. NET AR AFTER ALL BALANCES */ ROUND(SUM(IFNULL(A."Balance", 0)), 2) AS "Net AR After Adjustments",
		 /* 11. INVOICES >= 1000 */ COUNT(CASE
				 WHEN A."Entity Type"  IN ( 'invoice'  , 'customer_ob_invoice'  )
				 AND	A."Balance"  >= 1000 THEN 1
				 ELSE NULL
			 END) AS "Invoices Above 1000",
		 /* 12. INVOICE AMOUNT >= 1000 */ ROUND(SUM(CASE
				 WHEN A."Entity Type"  IN ( 'invoice'  , 'customer_ob_invoice'  )
				 AND	A."Balance"  >= 1000 THEN A."Balance"
				 ELSE 0
			 END), 2) AS "Invoices Above 1000 AR",
		 
			/* 13. RECONCILIATION STATUS */
			CASE
				 WHEN ABS(ROUND(SUM(IFNULL(A."Statement Amount", 0)) -SUM(IFNULL(A."Balance", 0)), 2))  > 0.01 THEN 'RECONCILIATION ERROR'
				 WHEN SUM(CASE
						 WHEN A."Entity Type"  = 'unapplied_balance'
						 AND	ABS(IFNULL(A."Balance", 0))  > 0.01 THEN 1
						 ELSE 0
					 END)  > 0 THEN 'REVIEW UNAPPLIED'
				 WHEN SUM(CASE
						 WHEN A."Entity Type"  IN ( 'creditnote'  , 'customer_payment'  , 'invoice_payment'  )
						 AND	A."Balance"  < - 0.01 THEN 1
						 ELSE 0
					 END)  > 0 THEN 'REVIEW UNUSED CREDITS'
				 ELSE 'READY FOR RISK'
			 END AS "Risk Readiness"
FROM  "AR SALES" A 
WHERE	 A."Customer ID"  IS NOT NULL
GROUP BY  A."Customer ID" 
ORDER BY "Risk Readiness",
	 "Statement Net AR" DESC
