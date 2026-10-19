SELECT
		 X."Customer ID" AS "Customer ID",
		 MAX(X."Customer Number") AS "Customer Number",
		 MAX(X."Customer Name") AS "Customer Name",
		 MAX(X."Payment Terms Days") AS "Payment Terms Days",
		 ROUND(MAX(X."Total Balance AR"), 2) AS "Net Customer AR",
		 ROUND(MAX(X."Open Invoice AR"), 2) AS "Open Invoice AR",
		 ROUND(MAX(X."Available Credit for Offset"), 2) AS "Available Credit for Offset",
		 SUM(X."Qualifying Invoice") AS "Risk Invoice Count",
		 ROUND(SUM(X."Risk Invoice Balance"), 2) AS "Risk Invoice AR",
		 MAX(X."Overdue Days") AS "Maximum Overdue Days",
		 
			/* ORIGINAL INVOICE RISK */
			CASE
				 WHEN MAX(X."Risk Rank")  = 3 THEN 'High'
				 WHEN MAX(X."Risk Rank")  = 2 THEN 'Medium'
				 WHEN MAX(X."Risk Rank")  = 1 THEN 'Low'
				 ELSE 'NO'
			 END AS "AR Risk Classification",
		 
			/* REVIEW STATUS */
			CASE
				 WHEN MAX(ABS(IFNULL(X."Reconciliation Difference", 0)))  > 0.01 THEN 'RECONCILIATION ERROR'
				 WHEN MAX(X."Missing Due Date")  = 1 THEN 'REVIEW DUE DATE'
				 WHEN MAX(IFNULL(X."Available Credit for Offset", 0))  >= 1000 THEN 'REVIEW CREDIT'
				 WHEN MAX(X."Review Unapplied")  = 1 THEN 'REVIEW UNAPPLIED'
				 ELSE 'READY'
			 END AS "Risk Review Status",
		 
			/* FINAL RISK */
			CASE
				 WHEN MAX(ABS(IFNULL(X."Reconciliation Difference", 0)))  > 0.01
				 OR	MAX(X."Missing Due Date")  = 1
				 OR	MAX(IFNULL(X."Available Credit for Offset", 0))  >= 1000
				 OR	MAX(X."Review Unapplied")  = 1 THEN 'RISK REVIEW'
				 WHEN MAX(X."Risk Rank")  = 3 THEN 'High'
				 WHEN MAX(X."Risk Rank")  = 2 THEN 'Medium'
				 WHEN MAX(X."Risk Rank")  = 1 THEN 'Low'
				 ELSE 'NO'
			 END AS "Final Risk"
FROM (	SELECT
			 A."Customer ID" AS "Customer ID",
			 A."Customer Number" AS "Customer Number",
			 A."Customer Name" AS "Customer Name",
			 IFNULL(C."Payments Due", 30) AS "Payment Terms Days",
			 R."Total Balance AR" AS "Total Balance AR",
			 R."Open Invoice AR" AS "Open Invoice AR",
			 R."Available Credit for Offset" AS "Available Credit for Offset",
			 R."Reconciliation Difference" AS "Reconciliation Difference",
			 
				/* NEW: UNAPPLIED REVIEW FROM Q1 */
				CASE
					 WHEN R."Risk Readiness"  = 'REVIEW UNAPPLIED' THEN 1
					 ELSE 0
				 END AS "Review Unapplied",
			 
				/* QUALIFYING INVOICE */
				CASE
					 WHEN A."Entity Type"  IN ( 'invoice'  , 'customer_ob_invoice'  )
					 AND	IFNULL(A."Balance", 0)  >= 1000 THEN 1
					 ELSE 0
				 END AS "Qualifying Invoice",
			 
				/* QUALIFYING BALANCE */
				CASE
					 WHEN A."Entity Type"  IN ( 'invoice'  , 'customer_ob_invoice'  )
					 AND	IFNULL(A."Balance", 0)  >= 1000 THEN A."Balance"
					 ELSE 0
				 END AS "Risk Invoice Balance",
			 
				/* OVERDUE DAYS */
				CASE
					 WHEN A."Entity Type"  IN ( 'invoice'  , 'customer_ob_invoice'  )
					 AND	IFNULL(A."Balance", 0)  >= 1000
					 AND	A."Inv Balance Due Date"  IS NOT NULL
					 AND	DATEDIFF(CURDATE(), A."Inv Balance Due Date")  > 0 THEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")
					 ELSE 0
				 END AS "Overdue Days",
			 
				/* MISSING DUE DATE */
				CASE
					 WHEN A."Entity Type"  IN ( 'invoice'  , 'customer_ob_invoice'  )
					 AND	IFNULL(A."Balance", 0)  >= 1000
					 AND	A."Inv Balance Due Date"  IS NULL THEN 1
					 ELSE 0
				 END AS "Missing Due Date",
			 
				/* RISK RANK */
				CASE
					 WHEN A."Entity Type"  NOT IN ( 'invoice'  , 'customer_ob_invoice'  )
					 OR	IFNULL(A."Balance", 0)  < 1000
					 OR	A."Inv Balance Due Date"  IS NULL THEN 0 /* TERMS 0-30 */
					 WHEN IFNULL(C."Payments Due", 30)  <= 30 THEN 
						CASE
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 10 THEN 0
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 15 THEN 1
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 30 THEN 2
							 ELSE 3
						 END/* TERMS 45 */
					 WHEN C."Payments Due"  <= 45 THEN 
						CASE
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 15 THEN 0
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 30 THEN 1
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 45 THEN 2
							 ELSE 3
						 END/* TERMS 60 */
					 WHEN C."Payments Due"  <= 60 THEN 
						CASE
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 30 THEN 0
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 45 THEN 1
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 60 THEN 2
							 ELSE 3
						 END/* TERMS 90 */
					 WHEN C."Payments Due"  <= 90 THEN 
						CASE
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 45 THEN 0
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 60 THEN 1
							 ELSE 3
						 END/* TERMS 120+ */
					 ELSE 
						CASE
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 30 THEN 0
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 45 THEN 1
							 WHEN DATEDIFF(CURDATE(), A."Inv Balance Due Date")  <= 60 THEN 2
							 ELSE 3
						 END
				 END AS "Risk Rank"
	FROM  "AR SALES" A
LEFT JOIN "Customers" C ON A."Customer ID"  = C."Customer ID" 
LEFT JOIN "AR Risk Reconciliation" R ON A."Customer ID"  = R."Customer ID"  
	WHERE	 A."Customer ID"  IS NOT NULL
) X 
GROUP BY  X."Customer ID" 
ORDER BY "Final Risk",
	 "Net Customer AR" DESC
