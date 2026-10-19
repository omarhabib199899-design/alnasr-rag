SELECT
		 SO."Order Date" AS "Order Date",
		 SO."Sales Order#" AS "Sales Order Number",
		 I."Sales order ID" AS "Sales Order ID",
		 SO."Customer ID" AS "Customer ID",
		 C."Customer Name" AS "Customer Name",
		 I."Product ID" AS "Item ID",
		 I."Item Name" AS "Item Name",
		 I."Quantity" AS "Ordered Qty",
		 IFNULL(I."Quantity Invoiced", 0) AS "Invoiced Qty",
		 IFNULL(I."Quantity Cancelled", 0) AS "Cancelled Qty",
		 (IFNULL(I."Quantity", 0) -IFNULL(I."Quantity Invoiced", 0) -IFNULL(I."Quantity Cancelled", 0)) AS "Uninvoiced Qty",
		 I."Item Price (BCY)" AS "Sales Price",
		 ROUND(I."Total (BCY)" / NULLIF(I."Quantity", 0), 2) AS "Unit Net Price",
		 I."Total (BCY)" AS "Item Subtotal",
		 I."Entity Discount Percent" AS "Discount %",
		 ROUND((IFNULL(I."Quantity", 0) -IFNULL(I."Quantity Invoiced", 0) -IFNULL(I."Quantity Cancelled", 0)) * I."Total (BCY)" / NULLIF(I."Quantity", 0), 2) AS "Uninvoiced Sales Value",
		 
			CASE
				 WHEN IFNULL(I."Quantity Invoiced", 0)  = 0 THEN 'Not Invoiced'
				 ELSE 'Partially Invoiced'
			 END AS "Invoicing Classification",
		 SO."Status" AS "Sales Order Status",
		 SO."Invoiced Status" AS "Invoiced Status"
FROM  "Sales Order Items" I
LEFT JOIN "Sales Orders" SO ON I."Sales order ID"  = SO."Sales order ID" 
LEFT JOIN "Customers" C ON SO."Customer ID"  = C."Customer ID"  
WHERE	 (IFNULL(I."Quantity", 0) -IFNULL(I."Quantity Invoiced", 0) -IFNULL(I."Quantity Cancelled", 0))  > 0
 AND	LOWER(TRIM(SO."Status"))  NOT IN ( 'rejected'  , 'void'  , 'invoiced'  )
ORDER BY SO."Order Date" DESC,
	 SO."Sales Order#" ASC
