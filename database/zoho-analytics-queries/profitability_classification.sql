SELECT
		 X."Customer ID" AS "Customer ID",
		 X."Customer Number" AS "Customer Number",
		 X."Customer Name" AS "Customer Name",
		 ROUND(X."L6M Sales", 2) AS "L6M Sales",
		 ROUND(X."L6M Gross Profit", 2) AS "L6M Gross Profit",
		 
			CASE
				 WHEN X."L6M Sales"  > 0 THEN ROUND(X."L6M Gross Profit" / X."L6M Sales" * 100, 2)
				 ELSE NULL
			 END AS "L6M Gross Margin %",
		 ROUND(X."Historical Sales", 2) AS "Historical Sales",
		 ROUND(X."Historical Gross Profit", 2) AS "Historical Gross Profit",
		 
			CASE
				 WHEN X."Historical Sales"  > 0 THEN ROUND(X."Historical Gross Profit" / X."Historical Sales" * 100, 2)
				 ELSE NULL
			 END AS "Historical Gross Margin %",
		 
			CASE
				 WHEN X."L6M Sales"  > 0 THEN 
					CASE
						 WHEN X."L6M Gross Profit" / X."L6M Sales" * 100  <= 3 THEN 'Loss'
						 WHEN X."L6M Gross Profit" / X."L6M Sales" * 100  < 10 THEN 'Low'
						 WHEN X."L6M Gross Profit" / X."L6M Sales" * 100  < 15 THEN 'Healthy'
						 ELSE 'High'
					 END
				 WHEN X."L6M Sales"  = 0
				 AND	X."L6M Gross Profit"  < 0 THEN 'Loss'
				 WHEN X."Historical Sales"  > 0 THEN 
					CASE
						 WHEN X."Historical Gross Profit" / X."Historical Sales" * 100  <= 3 THEN 'Loss'
						 WHEN X."Historical Gross Profit" / X."Historical Sales" * 100  < 10 THEN 'Low'
						 WHEN X."Historical Gross Profit" / X."Historical Sales" * 100  < 15 THEN 'Healthy'
						 ELSE 'High'
					 END
				 WHEN X."Historical Sales"  = 0
				 AND	X."Historical Gross Profit"  < 0 THEN 'Loss'
				 ELSE 'No Data'
			 END AS "Profitability Level",
		 ROUND(X."Current 45D Sales", 2) AS "Current 45D Sales",
		 ROUND(X."Current 45D Gross Profit", 2) AS "Current 45D Gross Profit",
		 
			CASE
				 WHEN X."Current 45D Sales"  > 0 THEN ROUND(X."Current 45D Gross Profit" / X."Current 45D Sales" * 100, 2)
				 ELSE NULL
			 END AS "Current 45D Margin %",
		 ROUND(X."Previous 135D Sales", 2) AS "Previous 135D Sales",
		 ROUND(X."Previous 135D Gross Profit", 2) AS "Previous 135D Gross Profit",
		 
			CASE
				 WHEN X."Previous 135D Sales"  > 0 THEN ROUND(X."Previous 135D Gross Profit" / X."Previous 135D Sales" * 100, 2)
				 ELSE NULL
			 END AS "Previous 135D Margin %",
		 
			CASE
				 WHEN X."Current 45D Sales"  > 0
				 AND	X."Previous 135D Sales"  > 0 THEN ROUND((X."Current 45D Gross Profit" / X."Current 45D Sales" * 100) -(X."Previous 135D Gross Profit" / X."Previous 135D Sales" * 100), 2)
				 ELSE NULL
			 END AS "Margin Change PP",
		 
			CASE
				 WHEN X."Current 45D Sales"  > 0
				 AND	X."Previous 135D Sales"  > 0 THEN 
					CASE
						 WHEN (X."Current 45D Gross Profit" / X."Current 45D Sales" * 100) - (X."Previous 135D Gross Profit" / X."Previous 135D Sales" * 100)  >= 2 THEN 'Growing'
						 WHEN (X."Current 45D Gross Profit" / X."Current 45D Sales" * 100) - (X."Previous 135D Gross Profit" / X."Previous 135D Sales" * 100)  > - 2 THEN 'Stable'
						 WHEN (X."Current 45D Gross Profit" / X."Current 45D Sales" * 100) - (X."Previous 135D Gross Profit" / X."Previous 135D Sales" * 100)  >= - 5 THEN 'Declining'
						 ELSE 'Severe Decline'
					 END
				 WHEN X."Current 45D Sales"  > 0
				 AND	X."Previous 135D Sales"  = 0 THEN 'New / Reactivated Profitability'
				 WHEN X."Current 45D Sales"  = 0
				 AND	X."Previous 135D Sales"  > 0 THEN 'No Recent Sales'
				 WHEN X."Current 45D Sales"  = 0
				 AND	X."Previous 135D Sales"  = 0 THEN 'No Sales L6M'
				 ELSE 'No Data'
			 END AS "Profitability Trend",
		 X."L6M Zero COGS Invoice Count" AS "L6M Zero COGS Invoice Count",
		 ROUND(X."L6M Zero COGS Sales", 2) AS "L6M Zero COGS Sales",
		 
			CASE
				 WHEN X."L6M Zero COGS Invoice Count"  > 0 THEN 'REVIEW ZERO COGS'
				 ELSE 'OK'
			 END AS "Zero COGS Audit",
		 CONCAT(CASE
				 WHEN X."L6M Sales"  > 0 THEN 
					CASE
						 WHEN X."L6M Gross Profit" / X."L6M Sales" * 100  <= 3 THEN 'Loss'
						 WHEN X."L6M Gross Profit" / X."L6M Sales" * 100  < 10 THEN 'Low'
						 WHEN X."L6M Gross Profit" / X."L6M Sales" * 100  < 15 THEN 'Healthy'
						 ELSE 'High'
					 END
				 WHEN X."L6M Gross Profit"  < 0 THEN 'Loss'
				 WHEN X."Historical Sales"  > 0 THEN 
					CASE
						 WHEN X."Historical Gross Profit" / X."Historical Sales" * 100  <= 3 THEN 'Loss'
						 WHEN X."Historical Gross Profit" / X."Historical Sales" * 100  < 10 THEN 'Low'
						 WHEN X."Historical Gross Profit" / X."Historical Sales" * 100  < 15 THEN 'Healthy'
						 ELSE 'High'
					 END
				 WHEN X."Historical Gross Profit"  < 0 THEN 'Loss'
				 ELSE 'No Data'
			 END, ' - ', CASE
				 WHEN X."Current 45D Sales"  > 0
				 AND	X."Previous 135D Sales"  > 0 THEN 
					CASE
						 WHEN (X."Current 45D Gross Profit" / X."Current 45D Sales" * 100) - (X."Previous 135D Gross Profit" / X."Previous 135D Sales" * 100)  >= 2 THEN 'Growing'
						 WHEN (X."Current 45D Gross Profit" / X."Current 45D Sales" * 100) - (X."Previous 135D Gross Profit" / X."Previous 135D Sales" * 100)  > - 2 THEN 'Stable'
						 WHEN (X."Current 45D Gross Profit" / X."Current 45D Sales" * 100) - (X."Previous 135D Gross Profit" / X."Previous 135D Sales" * 100)  >= - 5 THEN 'Declining'
						 ELSE 'Severe Decline'
					 END
				 WHEN X."Current 45D Sales"  > 0
				 AND	X."Previous 135D Sales"  = 0 THEN 'New / Reactivated Profitability'
				 WHEN X."Current 45D Sales"  = 0
				 AND	X."Previous 135D Sales"  > 0 THEN 'No Recent Sales'
				 WHEN X."Current 45D Sales"  = 0
				 AND	X."Previous 135D Sales"  = 0 THEN 'No Sales L6M'
				 ELSE 'No Data'
			 END) AS "Profitability Classification"
FROM (	SELECT
			 "Customer ID",
			 "Customer Number",
			 "Customer Name",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  BETWEEN 0  AND  179 THEN "Invoice Sales"
					 ELSE 0
				 END) AS "L6M Sales",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  BETWEEN 0  AND  179 THEN "Invoice Gross Profit"
					 ELSE 0
				 END) AS "L6M Gross Profit",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  >= 180 THEN "Invoice Sales"
					 ELSE 0
				 END) AS "Historical Sales",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  >= 180 THEN "Invoice Gross Profit"
					 ELSE 0
				 END) AS "Historical Gross Profit",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  BETWEEN 0  AND  44 THEN "Invoice Sales"
					 ELSE 0
				 END) AS "Current 45D Sales",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  BETWEEN 0  AND  44 THEN "Invoice Gross Profit"
					 ELSE 0
				 END) AS "Current 45D Gross Profit",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  BETWEEN 45  AND  179 THEN "Invoice Sales"
					 ELSE 0
				 END) AS "Previous 135D Sales",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  BETWEEN 45  AND  179 THEN "Invoice Gross Profit"
					 ELSE 0
				 END) AS "Previous 135D Gross Profit",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  BETWEEN 0  AND  179
					 AND	IFNULL("Invoice COGS", 0)  = 0
					 AND	"Invoice Sales"  > 0 THEN 1
					 ELSE 0
				 END) AS "L6M Zero COGS Invoice Count",
			 SUM(CASE
					 WHEN DATEDIFF(CURDATE(), "Invoice Date")  BETWEEN 0  AND  179
					 AND	IFNULL("Invoice COGS", 0)  = 0
					 AND	"Invoice Sales"  > 0 THEN "Invoice Sales"
					 ELSE 0
				 END) AS "L6M Zero COGS Sales"
	FROM  "Profitability_Staging" 
	WHERE	 NOT (IFNULL("Invoice Sales", 0)  = 0
	 AND	IFNULL("Invoice COGS", 0)  = 0)
	GROUP BY "Customer ID",
		 "Customer Number",
		  "Customer Name" 
) X 
ORDER BY X."Customer Number"
