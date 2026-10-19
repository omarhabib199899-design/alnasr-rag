SELECT
		 inv."Invoice ID" AS "Transaction ID",
		 inv."Invoice Number" AS "Transaction Number",
		 'Invoice' AS "Transaction Type",
		 inv."Invoice Date" AS "Transaction Date",
		 inv."Customer ID" AS "Customer ID",
		 c."Customer Name" AS "Customer Name",
		 i."Item ID" AS "Item ID",
		 i."Item Name" AS "Item Name",
		 ii."Location ID" AS "Location ID",
		 bno."Quantity Out" AS "QTY",
		 bnIN."Batch Number" AS "BATCH",
		 bnIN."Batch Expiry Date" AS "EXPIRY",
		 ii."Item Price" AS "Item Price",
		 ROUND(ii."Discount Amount (BCY)" * bno."Quantity Out" / NULLIF(SUM(bno."Quantity Out") OVER(PARTITION BY ii."Item ID"  ), 0), 2) AS "Discount Amount",
		 ROUND(cogs_fifo."Total COGS" * bno."Quantity Out" / NULLIF(SUM(bno."Quantity Out") OVER(PARTITION BY ii."Item ID"  ), 0), 2) AS "COGS",
		 ROUND(bno."Quantity Out" * ii."Item Price" -ii."Discount Amount (BCY)" * bno."Quantity Out" / NULLIF(SUM(bno."Quantity Out") OVER(PARTITION BY ii."Item ID"  ), 0), 2) AS "ITEM TOTAL",
		 ROUND((bno."Quantity Out" * ii."Item Price" -ii."Discount Amount (BCY)" * bno."Quantity Out" / NULLIF(SUM(bno."Quantity Out") OVER(PARTITION BY ii."Item ID"  ), 0)) -cogs_fifo."Total COGS" * bno."Quantity Out" / NULLIF(SUM(bno."Quantity Out") OVER(PARTITION BY ii."Item ID"  ), 0), 2) AS "ITEM GROSS PROFIT",
		 ROUND((ii."Discount Amount (BCY)" * bno."Quantity Out" / NULLIF(SUM(bno."Quantity Out") OVER(PARTITION BY ii."Item ID"  ), 0)) / NULLIF(bno."Quantity Out" * ii."Item Price", 0) * 100, 2) AS "DISCOUNT %"
FROM  "Invoice Items" ii
LEFT JOIN "Invoices" inv ON ii."Invoice ID"  = inv."Invoice ID" 
LEFT JOIN "Customers" c ON inv."Customer ID"  = c."Customer ID" 
LEFT JOIN "Items" i ON ii."Product ID"  = i."Item ID" 
LEFT JOIN "Batch Number Out" bno ON bno."Invoice Item ID"  = ii."Item ID" 
LEFT JOIN "Batch Number IN" bnIN ON bnIN."Batch In ID"  = bno."Batch In ID" 
LEFT JOIN(	SELECT
			 sof."Invoice Item ID" AS "Invoice Item ID",
			 SUM(fifo."Total (BCY)") AS "Total COGS"
	FROM  "Stock Out Flow Table" sof
LEFT JOIN "FIFO Mapping Table" fifo ON fifo."Stock Out Flow ID"  = sof."Stock Out Flow ID"  
	GROUP BY  sof."Invoice Item ID" 
) AS  cogs_fifo ON cogs_fifo."Invoice Item ID"  = ii."Item ID"  
WHERE	 i."Item ID"  IS NOT NULL
UNION ALL
 SELECT
		 cn."CreditNotes ID" AS "Transaction ID",
		 cn."Credit Note Number" AS "Transaction Number",
		 'Credit Note' AS "Transaction Type",
		 cn."Credit Note Date" AS "Transaction Date",
		 cn."Customer ID" AS "Customer ID",
		 c."Customer Name" AS "Customer Name",
		 i."Item ID" AS "Item ID",
		 i."Item Name" AS "Item Name",
		 cni."Location ID" AS "Location ID",
		 -bn."Quantity In" AS "QTY",
		 bn."Batch Number" AS "BATCH",
		 bn."Batch Expiry Date" AS "EXPIRY",
		 cni."Item Price" AS "Item Price",
		 ROUND(cni."Discount Amount (BCY)" * bn."Quantity In" / NULLIF(SUM(bn."Quantity In") OVER(PARTITION BY cni."Item ID"  ), 0), 2) AS "Discount Amount",
		 NULL AS "COGS",
		 ROUND(-(bn."Quantity In" * cni."Item Price" -cni."Discount Amount (BCY)" * bn."Quantity In" / NULLIF(SUM(bn."Quantity In") OVER(PARTITION BY cni."Item ID"  ), 0)), 2) AS "ITEM TOTAL",
		 NULL AS "ITEM GROSS PROFIT",
		 ROUND((cni."Discount Amount (BCY)" * bn."Quantity In" / NULLIF(SUM(bn."Quantity In") OVER(PARTITION BY cni."Item ID"  ), 0)) / NULLIF(bn."Quantity In" * cni."Item Price", 0) * 100, 2) AS "DISCOUNT %"
FROM  "Credit Note Items" cni
LEFT JOIN "Credit Notes" cn ON cni."CreditNotes ID"  = cn."CreditNotes ID" 
LEFT JOIN "Customers" c ON cn."Customer ID"  = c."Customer ID" 
LEFT JOIN "Items" i ON cni."Product ID"  = i."Item ID" 
LEFT JOIN "Batch Number IN" bn ON bn."CreditNotes ID"  = cni."CreditNotes ID"
	 AND	bn."Credit Notes Item ID"  = cni."Item ID"  
WHERE	 i."Item ID"  IS NOT NULL
