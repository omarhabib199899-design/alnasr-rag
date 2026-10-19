SELECT
		 INV."Invoice ID" AS "Invoice ID",
		 INV."Invoice Number" AS "Invoice Number",
		 INV."Invoice Date" AS "Invoice Date",
		 INV."Invoice Status" AS "Invoice Status",
		 INV."Customer ID" AS "Customer ID",
		 INV."Type" AS "Type",
		 INV."Total (BCY)" AS "Total (BCY)",
		 PL."PriceList Name" AS "PriceList Name",
		 ITEMS."City" AS "Warehause",
		 INV."e-Invoice Status." AS "e-Invoice Status.",
		 INV."PREPARED BY" AS "PREPARED BY",
		 INV."REVIEW BY" AS "REVIEW BY",
		 INV."DELIVERY BY" AS "DELIVERY BY",
		 INV."INVOICE ATTACHED BY" AS "INVOICE ATTACHED BY",
		 INV."Total Margin" AS "Total Margin",
		 INV."EXIT DATE TIME" AS "EXIT DATE TIME",
		 INV."DELIVERY DATE TIME" AS "DELIVERY DATE TIME",
		 INV."CUSTOMER CHECK" AS "CUSTOMER CHECK",
		 INV."NO OF ERRORS AFTER DELIVERY" AS "NO OF ERRORS AFTER DELIVERY",
		 INV."RASD" AS "RASD",
		 INV."DATA ENTRY" AS "Invoice DATA ENTRY",
		 INV."INVOICE ATTACHMENT STATUS" AS "INVOICE ATTACHMENT STATUS",
		 INV."CARTON NO" AS "CARTON NO",
		 INV."ICEBOX NO" AS "ICEBOX NO",
		 INV."Pallets No" AS "Pallets No",
		 SO."Sales Order#" AS "Sales Order No",
		 SO."DATA ENTRY" AS "Sales Order Data Entry",
		 ITEMS."Invoice Item Count" AS "Invoice Item Count",
		 ITEMS."Invoice Item Qty" AS "Invoice Item Qty"
FROM  "Invoices" INV
LEFT JOIN "Price lists" PL ON INV."PriceList ID"  = PL."PriceList ID" 
LEFT JOIN "Sales Order Invoice" SOI ON INV."Invoice ID"  = SOI."Invoice ID" 
LEFT JOIN "Sales Orders" SO ON SOI."Sales order ID"  = SO."Sales order ID" 
LEFT JOIN(	SELECT
			 II."Invoice ID" AS "Invoice ID",
			 COUNT(DISTINCT II."Product ID") AS "Invoice Item Count",
			 SUM(II."Quantity") AS "Invoice Item Qty",
			 
				CASE
					 WHEN COUNT(DISTINCT LOC."City")  = 1 THEN MAX(LOC."City")
					 WHEN COUNT(DISTINCT LOC."City")  > 1 THEN 'MULTIPLE CITIES'
					 ELSE NULL
				 END AS "City"
	FROM  "Invoice Items" II
LEFT JOIN "Locations" LOC ON II."Location ID"  = LOC."Location ID"  
	GROUP BY  II."Invoice ID" 
) ITEMS ON INV."Invoice ID"  = ITEMS."Invoice ID"  
WHERE	 LOWER(INV."Invoice Status")  NOT IN ( 'draft'  , 'void'  )
ORDER BY INV."Invoice Date" DESC 
