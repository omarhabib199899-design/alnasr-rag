SELECT
		 C."Customer ID" AS "Customer ID",
		 C."Customer Number" AS "Customer Number",
		 C."Customer Name" AS "Customer Name",
		 INV."Invoice ID" AS "Invoice ID",
		 INV."Invoice Number" AS "Invoice Number",
		 INV."Invoice Date" AS "Invoice Date",
		 ROUND(SUM(II."Quantity" * II."Item Price" -IFNULL(II."Discount Amount (BCY)", 0)), 2) AS "Invoice Sales",
		 ROUND(SUM(IFNULL(FIFO."Invoice Item COGS", 0)), 2) AS "Invoice COGS",
		 ROUND(SUM(II."Quantity" * II."Item Price" -IFNULL(II."Discount Amount (BCY)", 0)) -SUM(IFNULL(FIFO."Invoice Item COGS", 0)), 2) AS "Invoice Gross Profit",
		 
			CASE
				 WHEN SUM(II."Quantity" * II."Item Price" -IFNULL(II."Discount Amount (BCY)", 0))  <> 0 THEN ROUND(((SUM(II."Quantity" * II."Item Price" -IFNULL(II."Discount Amount (BCY)", 0)) -SUM(IFNULL(FIFO."Invoice Item COGS", 0))) / SUM(II."Quantity" * II."Item Price" -IFNULL(II."Discount Amount (BCY)", 0))) * 100, 2)
				 ELSE NULL
			 END AS "Invoice Gross Margin %"
FROM  "Invoice Items" II
LEFT JOIN "Invoices" INV ON II."Invoice ID"  = INV."Invoice ID" 
LEFT JOIN "Customers" C ON INV."Customer ID"  = C."Customer ID" 
LEFT JOIN(	SELECT
			 SOF."Invoice Item ID" AS "Invoice Item ID",
			 SUM(IFNULL(FM."Total (BCY)", 0)) AS "Invoice Item COGS"
	FROM  "Stock Out Flow Table" SOF
LEFT JOIN "FIFO Mapping Table" FM ON SOF."Stock Out Flow ID"  = FM."Stock Out Flow ID"  
	WHERE	 SOF."Invoice Item ID"  IS NOT NULL
	GROUP BY  SOF."Invoice Item ID" 
) FIFO ON II."Item ID"  = FIFO."Invoice Item ID"  
WHERE	 C."Customer ID"  IS NOT NULL
 AND	C."Customer ID"  <> ''
GROUP BY C."Customer ID",
	 C."Customer Number",
	 C."Customer Name",
	 INV."Invoice ID",
	 INV."Invoice Number",
	  INV."Invoice Date" 
ORDER BY C."Customer Number",
	 INV."Invoice Date",
	 INV."Invoice Number"
