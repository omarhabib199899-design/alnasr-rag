SELECT
		 X."Customer Number",
		 X."Customer Name",
		 X."L12M Net Sales",
		 X."Current 45D Net Sales",
		 X."Previous 45D Net Sales",
		 X."Previous 90D Net Sales",
		 X."First Positive Invoice Date",
		 X."Last Positive Invoice Date",
		 
			CASE
				 WHEN X."Previous 45D Net Sales"  > 0
				 AND	X."Current 45D Net Sales"  > 0 THEN ROUND((X."Current 45D Net Sales" -X."Previous 45D Net Sales") / X."Previous 45D Net Sales" * 100, 2)
				 WHEN X."Previous 45D Net Sales"  <= 0
				 AND	X."Current 45D Net Sales"  > 0
				 AND	(X."Previous 90D Net Sales" / 2)  > 0 THEN ROUND((X."Current 45D Net Sales" -(X."Previous 90D Net Sales" / 2)) / (X."Previous 90D Net Sales" / 2) * 100, 2)
				 ELSE NULL
			 END AS "Growth %",
		 CONCAT(CASE
				 WHEN X."L12M Net Sales"  < 0 THEN 'Review / Negative Net Sales'
				 WHEN X."L12M Net Sales"  = 0 THEN 'Dormant'
				 WHEN ((SUM(CASE
						 WHEN X."L12M Net Sales"  > 0 THEN X."L12M Net Sales"
						 ELSE 0
					 END) OVER(ORDER BY X."L12M Net Sales" DESC,
					 X."Customer Number"  ROWS  BETWEEN  UNBOUNDED  PRECEDING  AND  CURRENT  ROW ) -
					CASE
						 WHEN X."L12M Net Sales"  > 0 THEN X."L12M Net Sales"
						 ELSE 0
					 END) / SUM(CASE
						 WHEN X."L12M Net Sales"  > 0 THEN X."L12M Net Sales"
						 ELSE 0
					 END) OVER()) * 100  < 50 THEN 'Strategic'
				 WHEN ((SUM(CASE
						 WHEN X."L12M Net Sales"  > 0 THEN X."L12M Net Sales"
						 ELSE 0
					 END) OVER(ORDER BY X."L12M Net Sales" DESC,
					 X."Customer Number"  ROWS  BETWEEN  UNBOUNDED  PRECEDING  AND  CURRENT  ROW ) -
					CASE
						 WHEN X."L12M Net Sales"  > 0 THEN X."L12M Net Sales"
						 ELSE 0
					 END) / SUM(CASE
						 WHEN X."L12M Net Sales"  > 0 THEN X."L12M Net Sales"
						 ELSE 0
					 END) OVER()) * 100  < 80 THEN 'Key Account'
				 WHEN ((SUM(CASE
						 WHEN X."L12M Net Sales"  > 0 THEN X."L12M Net Sales"
						 ELSE 0
					 END) OVER(ORDER BY X."L12M Net Sales" DESC,
					 X."Customer Number"  ROWS  BETWEEN  UNBOUNDED  PRECEDING  AND  CURRENT  ROW ) -
					CASE
						 WHEN X."L12M Net Sales"  > 0 THEN X."L12M Net Sales"
						 ELSE 0
					 END) / SUM(CASE
						 WHEN X."L12M Net Sales"  > 0 THEN X."L12M Net Sales"
						 ELSE 0
					 END) OVER()) * 100  < 95 THEN 'Core'
				 ELSE 'Emerging'
			 END, ' - ', CASE
				 WHEN X."First Positive Invoice Date"  BETWEEN ADDDATE(CURDATE(), -44)  AND  CURDATE() THEN 'New'
				 WHEN X."Current 45D Invoice Count"  > 0
				 AND	X."Previous 90D Invoice Count"  = 0
				 AND	X."Historical Invoice Count"  > 0 THEN 'Reactivated'
				 WHEN X."Last 135D Invoice Count"  = 0
				 AND	X."Historical Before 135D Count"  > 0 THEN 'Lost'
				 WHEN X."Total Positive Invoice Count"  = 0 THEN 'No Sales'
				 WHEN X."Previous 45D Net Sales"  > 0
				 AND	X."Current 45D Net Sales"  <= 0 THEN 'Severe Decline'
				 WHEN X."Previous 45D Net Sales"  > 0
				 AND	(X."Current 45D Net Sales" -X."Previous 45D Net Sales") / X."Previous 45D Net Sales"  >= 0.25 THEN 'Strong Growth'
				 WHEN X."Previous 45D Net Sales"  > 0
				 AND	(X."Current 45D Net Sales" -X."Previous 45D Net Sales") / X."Previous 45D Net Sales"  >= 0.10 THEN 'Growing'
				 WHEN X."Previous 45D Net Sales"  > 0
				 AND	(X."Current 45D Net Sales" -X."Previous 45D Net Sales") / X."Previous 45D Net Sales"  > - 0.10
				 AND	(X."Current 45D Net Sales" -X."Previous 45D Net Sales") / X."Previous 45D Net Sales"  < 0.10 THEN 'Stable'
				 WHEN X."Previous 45D Net Sales"  > 0
				 AND	(X."Current 45D Net Sales" -X."Previous 45D Net Sales") / X."Previous 45D Net Sales"  >= - 0.25 THEN 'Declining'
				 WHEN X."Previous 45D Net Sales"  > 0 THEN 'Severe Decline'
				 WHEN X."Previous 45D Net Sales"  <= 0
				 AND	X."Current 45D Net Sales"  > 0
				 AND	(X."Previous 90D Net Sales" / 2)  > 0
				 AND	(X."Current 45D Net Sales" -(X."Previous 90D Net Sales" / 2)) / (X."Previous 90D Net Sales" / 2)  >= 0.25 THEN 'Strong Growth'
				 WHEN X."Previous 45D Net Sales"  <= 0
				 AND	X."Current 45D Net Sales"  > 0
				 AND	(X."Previous 90D Net Sales" / 2)  > 0
				 AND	(X."Current 45D Net Sales" -(X."Previous 90D Net Sales" / 2)) / (X."Previous 90D Net Sales" / 2)  >= 0.10 THEN 'Growing'
				 WHEN X."Previous 45D Net Sales"  <= 0
				 AND	X."Current 45D Net Sales"  > 0
				 AND	(X."Previous 90D Net Sales" / 2)  > 0
				 AND	(X."Current 45D Net Sales" -(X."Previous 90D Net Sales" / 2)) / (X."Previous 90D Net Sales" / 2)  > - 0.10
				 AND	(X."Current 45D Net Sales" -(X."Previous 90D Net Sales" / 2)) / (X."Previous 90D Net Sales" / 2)  < 0.10 THEN 'Stable'
				 WHEN X."Previous 45D Net Sales"  <= 0
				 AND	X."Current 45D Net Sales"  > 0
				 AND	(X."Previous 90D Net Sales" / 2)  > 0
				 AND	(X."Current 45D Net Sales" -(X."Previous 90D Net Sales" / 2)) / (X."Previous 90D Net Sales" / 2)  >= - 0.25 THEN 'Declining'
				 WHEN X."Previous 45D Net Sales"  <= 0
				 AND	X."Current 45D Net Sales"  > 0
				 AND	(X."Previous 90D Net Sales" / 2)  > 0 THEN 'Severe Decline'
				 WHEN X."Previous 45D Net Sales"  <= 0
				 AND	X."Current 45D Net Sales"  <= 0 THEN 'Severe Decline'
				 ELSE 'Review - No Positive Baseline'
			 END) AS "Sales Classification"
FROM (	SELECT
			 B."Customer Number" AS "Customer Number",
			 MAX(B."Customer Name") AS "Customer Name",
			 ROUND(SUM(CASE
					 WHEN B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -364)  AND  CURDATE()
					 AND	B."Entity Type"  = 'invoice' THEN ABS(B."Transaction Amount (BCY)")
					 WHEN B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -364)  AND  CURDATE()
					 AND	B."Entity Type"  = 'creditnote' THEN -ABS(B."Transaction Amount (BCY)")
					 ELSE 0
				 END), 2) AS "L12M Net Sales",
			 ROUND(SUM(CASE
					 WHEN B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -44)  AND  CURDATE()
					 AND	B."Entity Type"  = 'invoice' THEN ABS(B."Transaction Amount (BCY)")
					 WHEN B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -44)  AND  CURDATE()
					 AND	B."Entity Type"  = 'creditnote' THEN -ABS(B."Transaction Amount (BCY)")
					 ELSE 0
				 END), 2) AS "Current 45D Net Sales",
			 ROUND(SUM(CASE
					 WHEN B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -89)  AND  ADDDATE(CURDATE(), -45)
					 AND	B."Entity Type"  = 'invoice' THEN ABS(B."Transaction Amount (BCY)")
					 WHEN B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -89)  AND  ADDDATE(CURDATE(), -45)
					 AND	B."Entity Type"  = 'creditnote' THEN -ABS(B."Transaction Amount (BCY)")
					 ELSE 0
				 END), 2) AS "Previous 45D Net Sales",
			 ROUND(SUM(CASE
					 WHEN B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -134)  AND  ADDDATE(CURDATE(), -45)
					 AND	B."Entity Type"  = 'invoice' THEN ABS(B."Transaction Amount (BCY)")
					 WHEN B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -134)  AND  ADDDATE(CURDATE(), -45)
					 AND	B."Entity Type"  = 'creditnote' THEN -ABS(B."Transaction Amount (BCY)")
					 ELSE 0
				 END), 2) AS "Previous 90D Net Sales",
			 MIN(CASE
					 WHEN B."Entity Type"  = 'invoice'
					 AND	ABS(B."Transaction Amount (BCY)")  > 0 THEN B."Transaction Date"
					 ELSE NULL
				 END) AS "First Positive Invoice Date",
			 MAX(CASE
					 WHEN B."Entity Type"  = 'invoice'
					 AND	ABS(B."Transaction Amount (BCY)")  > 0 THEN B."Transaction Date"
					 ELSE NULL
				 END) AS "Last Positive Invoice Date",
			 SUM(CASE
					 WHEN B."Entity Type"  = 'invoice'
					 AND	ABS(B."Transaction Amount (BCY)")  > 0 THEN 1
					 ELSE 0
				 END) AS "Total Positive Invoice Count",
			 SUM(CASE
					 WHEN B."Entity Type"  = 'invoice'
					 AND	ABS(B."Transaction Amount (BCY)")  > 0
					 AND	B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -44)  AND  CURDATE() THEN 1
					 ELSE 0
				 END) AS "Current 45D Invoice Count",
			 SUM(CASE
					 WHEN B."Entity Type"  = 'invoice'
					 AND	ABS(B."Transaction Amount (BCY)")  > 0
					 AND	B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -134)  AND  ADDDATE(CURDATE(), -45) THEN 1
					 ELSE 0
				 END) AS "Previous 90D Invoice Count",
			 SUM(CASE
					 WHEN B."Entity Type"  = 'invoice'
					 AND	ABS(B."Transaction Amount (BCY)")  > 0
					 AND	B."Transaction Date"  < ADDDATE(CURDATE(), -134) THEN 1
					 ELSE 0
				 END) AS "Historical Invoice Count",
			 SUM(CASE
					 WHEN B."Entity Type"  = 'invoice'
					 AND	ABS(B."Transaction Amount (BCY)")  > 0
					 AND	B."Transaction Date"  BETWEEN ADDDATE(CURDATE(), -134)  AND  CURDATE() THEN 1
					 ELSE 0
				 END) AS "Last 135D Invoice Count",
			 SUM(CASE
					 WHEN B."Entity Type"  = 'invoice'
					 AND	ABS(B."Transaction Amount (BCY)")  > 0
					 AND	B."Transaction Date"  < ADDDATE(CURDATE(), -134) THEN 1
					 ELSE 0
				 END) AS "Historical Before 135D Count"
	FROM  "Sales_Classification_Staging" B 
	GROUP BY  B."Customer Number" 
) X 
ORDER BY X."L12M Net Sales" DESC,
	 X."Customer Number"
