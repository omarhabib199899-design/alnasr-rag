SELECT
		 C."Customer ID" AS "Customer ID",
		 C."Customer Number" AS "Customer Number",
		 C."Customer Name" AS "Customer Name",
		 INV."Invoice ID" AS "Invoice ID",
		 INV."Invoice Number" AS "Invoice Number",
		 INV."Invoice Date" AS "Invoice Date",
		 INV."Due Date" AS "Due Date",
		 INV."Total (BCY)" AS "Invoice Amount (BCY)",
		 INV."Balance (BCY)" AS "Invoice Balance (BCY)",
		 INV."Invoice Status" AS "Invoice Status",
		 INV."Write Off Amount (BCY)" AS "Write Off Amount (BCY)",
		 IFNULL(P."Total Applied Payments", 0) AS "Total Applied Payments",
		 P."Last Payment Date" AS "Last Payment Date",
		 IFNULL(CR."Total Applied Credit Notes", 0) AS "Total Applied Credit Notes",
		 CR."Last Credit Note Date" AS "Last Credit Note Applied Date",
		 
			CASE
				 WHEN IFNULL(INV."Write Off Amount (BCY)", 0)  <> 0 THEN 'REVIEW - WRITE OFF'
				 WHEN P."Last Payment Date"  IS NULL
				 AND	CR."Last Credit Note Date"  IS NULL THEN 'REVIEW - NO PAYMENT/CREDIT LINK'
				 WHEN P."Last Payment Date"  IS NOT NULL
				 AND	CR."Last Credit Note Date"  IS NULL THEN 'CLOSED BY PAYMENT'
				 WHEN P."Last Payment Date"  IS NULL
				 AND	CR."Last Credit Note Date"  IS NOT NULL THEN 'CLOSED BY CREDIT NOTE'
				 WHEN P."Last Payment Date"  >= CR."Last Credit Note Date" THEN 'CLOSED BY PAYMENT'
				 WHEN CR."Last Credit Note Date"  > P."Last Payment Date" THEN 'CLOSED BY CREDIT NOTE'
				 ELSE 'REVIEW'
			 END AS "Closing Method",
		 
			CASE
				 WHEN IFNULL(INV."Write Off Amount (BCY)", 0)  = 0
				 AND	P."Last Payment Date"  IS NOT NULL
				 AND	(CR."Last Credit Note Date"  IS NULL
				 OR	P."Last Payment Date"  >= CR."Last Credit Note Date") THEN P."Last Payment Date"
				 ELSE NULL
			 END AS "Settlement Date",
		 
			CASE
				 WHEN IFNULL(INV."Write Off Amount (BCY)", 0)  = 0
				 AND	P."Last Payment Date"  IS NOT NULL
				 AND	(CR."Last Credit Note Date"  IS NULL
				 OR	P."Last Payment Date"  >= CR."Last Credit Note Date") THEN DATEDIFF(P."Last Payment Date", INV."Due Date")
				 ELSE NULL
			 END AS "Settlement Delay Days"
FROM  "Invoices" INV
LEFT JOIN "Customers" C ON INV."Customer ID"  = C."Customer ID" 
LEFT JOIN(	SELECT
			 IP."Invoice ID" AS "Invoice ID",
			 ROUND(SUM(IP."Amount (BCY)"), 2) AS "Total Applied Payments",
			 MAX(CP."Payment Date") AS "Last Payment Date"
	FROM  "Invoice Payments" IP
LEFT JOIN "Customer Payments" CP ON IP."Payment ID"  = CP."Payment ID"  
	GROUP BY  IP."Invoice ID" 
) P ON INV."Invoice ID"  = P."Invoice ID" 
LEFT JOIN(	SELECT
			 CI."Invoice ID" AS "Invoice ID",
			 ROUND(SUM(CI."Amount (BCY)"), 2) AS "Total Applied Credit Notes",
			 MAX(CI."Date") AS "Last Credit Note Date"
	FROM  "Creditnotes Invoice" CI 
	GROUP BY  CI."Invoice ID" 
) CR ON INV."Invoice ID"  = CR."Invoice ID"  
WHERE	 INV."Balance (BCY)"  = 0
 AND	C."Customer ID"  IS NOT NULL
 AND	C."Customer ID"  <> ''
