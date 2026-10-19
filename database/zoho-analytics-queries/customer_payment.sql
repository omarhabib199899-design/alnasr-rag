SELECT
		 CP."Payment ID" AS "Payment ID",
		 CP."Payment Number" AS "Payment Number",
		 CP."Payment Date" AS "Payment Date",
		 CP."Payment Mode" AS "Payment Mode",
		 CP."Customer ID" AS "Customer ID",
		 CP."Amount (BCY)" AS "Total Payment Amount",
		 CP."Unused Amount (BCY)" AS "Unused Payment Amount",
		 IP."Invoice Payment ID" AS "Invoice Payment ID",
		 IP."Amount (BCY)" AS "Applied Amount",
		 INV."Invoice ID" AS "Invoice ID",
		 INV."Invoice Number" AS "Invoice Number",
		 INV."Invoice Date" AS "Invoice Date",
		 INV."Due Date" AS "Invoice Due Date",
		 INV."Invoice Status" AS "Invoice Status",
		 INV."Total (BCY)" AS "Invoice Total",
		 INV."Balance (BCY)" AS "Invoice Current Balance",
		 
			CASE
				 WHEN DATEDIFF(CP."Payment Date", INV."Invoice Date")  < 0 THEN 0
				 ELSE DATEDIFF(CP."Payment Date", INV."Invoice Date")
			 END AS "Days to Payment",
		 
			CASE
				 WHEN DATEDIFF(CP."Payment Date", INV."Due Date")  < 0 THEN 0
				 ELSE DATEDIFF(CP."Payment Date", INV."Due Date")
			 END AS "Days vs Due Date",
		 ROUND(IP."Amount (BCY)" * 100.0 / NULLIF(INV."Total (BCY)", 0), 2) AS "Invoice Payment Coverage %"
FROM  "Invoice Payments" IP
INNER JOIN "Customer Payments" CP ON IP."Payment ID"  = CP."Payment ID" 
INNER JOIN "Invoices" INV ON IP."Invoice ID"  = INV."Invoice ID"  
ORDER BY CP."Payment Date" DESC,
	 CP."Payment Number",
	 INV."Invoice Date"
