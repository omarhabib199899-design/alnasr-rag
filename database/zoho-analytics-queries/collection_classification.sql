SELECT
		 X."Customer ID" AS "Customer ID",
		 X."Customer Number" AS "Customer Number",
		 X."Customer Name" AS "Customer Name",
		 COUNT(X."Invoice ID") AS "Invoices Used",
		 MAX(X."Settlement Date") AS "Last Settlement Date",
		 MEDIAN(X."Settlement Delay Days") AS "Median Settlement Delay Days",
		 
			CASE
				 WHEN MEDIAN(X."Settlement Delay Days")  <= 15 THEN 'On Time / Perfect'
				 WHEN MEDIAN(X."Settlement Delay Days")  <= 30 THEN 'Late'
				 WHEN MEDIAN(X."Settlement Delay Days")  <= 60 THEN 'Very Late'
				 ELSE 'Severe Delay'
			 END AS "Collection Classification",
		 SUM(CASE
				 WHEN DAYOFMONTH(X."Settlement Date")  BETWEEN 1  AND  10 THEN 1
				 ELSE 0
			 END) AS "Early Month Count",
		 SUM(CASE
				 WHEN DAYOFMONTH(X."Settlement Date")  BETWEEN 11  AND  20 THEN 1
				 ELSE 0
			 END) AS "Mid Month Count",
		 SUM(CASE
				 WHEN DAYOFMONTH(X."Settlement Date")  >= 21 THEN 1
				 ELSE 0
			 END) AS "Late Month Count",
		 
			CASE
				 WHEN SUM(CASE
						 WHEN DAYOFMONTH(X."Settlement Date")  BETWEEN 1  AND  10 THEN 1
						 ELSE 0
					 END)  > COUNT(X."Invoice ID") / 2 THEN 'Early Month'
				 WHEN SUM(CASE
						 WHEN DAYOFMONTH(X."Settlement Date")  BETWEEN 11  AND  20 THEN 1
						 ELSE 0
					 END)  > COUNT(X."Invoice ID") / 2 THEN 'Mid Month'
				 WHEN SUM(CASE
						 WHEN DAYOFMONTH(X."Settlement Date")  >= 21 THEN 1
						 ELSE 0
					 END)  > COUNT(X."Invoice ID") / 2 THEN 'Late Month'
				 ELSE 'Random / Mixed'
			 END AS "Payment Timing Pattern"
FROM (	SELECT
			 "Customer ID",
			 "Customer Number",
			 "Customer Name",
			 "Invoice ID",
			 "Settlement Date",
			 "Settlement Delay Days",
			 ROW_NUMBER() OVER(PARTITION BY "Customer ID"  ORDER BY "Settlement Date" DESC,
				 "Invoice ID" DESC ) AS "Invoice Rank"
	FROM  "Collection_Classification_Staging" 
	WHERE	 "Closing Method"  = 'CLOSED BY PAYMENT'
	 AND	"Settlement Date"  IS NOT NULL
	 AND	"Settlement Delay Days"  IS NOT NULL
) X 
WHERE	 X."Invoice Rank"  <= 5
GROUP BY X."Customer ID",
	 X."Customer Number",
	  X."Customer Name" 
ORDER BY X."Customer Number"
