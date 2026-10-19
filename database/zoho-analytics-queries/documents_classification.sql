SELECT
		 C."Customer ID" AS "Customer ID",
		 C."Customer Number" AS "Customer Number",
		 C."Customer Name" AS "Customer Name",
		 C."Finance Dept Recommendation" AS "Finance Dept Recommendation",
		 C."CR Expiry Date" AS "CR Expiry Date",
		 C."Last Reconciliation date" AS "Last Reconciliation date",
		 C."Tax Registration Number" AS "Tax Registration Number",
		 C."CR NO" AS "CR NO",
		 C."GLN NO" AS "GLN NO",
		 C."MOH SFDA License Expiry Date" AS "MOH SFDA License Expiry Date",
		 C."Branch 1 City" AS "Branch 1 City",
		 C."Location Branch 1" AS "Location Branch 1",
		 C."WH CITY" AS "WH CITY",
		 
			CASE
				 WHEN C."Finance Dept Recommendation"  IS NOT NULL
				 AND	C."Finance Dept Recommendation"  <> '' THEN 'Critical'
				 ELSE 'PASS'
			 END AS "Finance Recommendation Status",
		 
			CASE
				 WHEN C."CR Expiry Date"  IS NOT NULL
				 AND	DATEDIFF(C."CR Expiry Date", CURDATE())  <= 30 THEN 'Critical'
				 ELSE 'PASS'
			 END AS "CR Expiry Status",
		 
			CASE
				 WHEN C."Last Reconciliation date"  IS NOT NULL
				 AND	DATEDIFF(CURDATE(), C."Last Reconciliation date")  > 60 THEN 'Critical'
				 ELSE 'PASS'
			 END AS "Reconciliation Status",
		 
			CASE
				 WHEN C."Tax Registration Number"  IS NULL
				 OR	C."Tax Registration Number"  = '' THEN 'Critical'
				 ELSE 'PASS'
			 END AS "Tax Registration Status",
		 
			CASE
				 WHEN C."CR NO"  IS NULL
				 OR	C."CR NO"  = '' THEN 'Critical'
				 ELSE 'PASS'
			 END AS "CR Number Status",
		 
			CASE
				 WHEN C."GLN NO"  IS NULL
				 OR	C."GLN NO"  = '' THEN 'Attention Required'
				 ELSE 'PASS'
			 END AS "GLN Status",
		 
			CASE
				 WHEN C."MOH SFDA License Expiry Date"  IS NOT NULL
				 AND	DATEDIFF(C."MOH SFDA License Expiry Date", CURDATE())  <= 30 THEN 'Attention Required'
				 ELSE 'PASS'
			 END AS "MOH SFDA License Status",
		 
			CASE
				 WHEN C."Branch 1 City"  IS NULL
				 OR	C."Branch 1 City"  = '' THEN 'Attention Required'
				 ELSE 'PASS'
			 END AS "Branch 1 City Status",
		 
			CASE
				 WHEN C."Location Branch 1"  IS NULL
				 OR	C."Location Branch 1"  = '' THEN 'Attention Required'
				 ELSE 'PASS'
			 END AS "Location Branch 1 Status",
		 
			CASE
				 WHEN C."WH CITY"  IS NULL
				 OR	C."WH CITY"  = '' THEN 'Attention Required'
				 ELSE 'PASS'
			 END AS "WH City Status",
		 
			CASE
				 WHEN (C."Finance Dept Recommendation"  IS NOT NULL
				 AND	C."Finance Dept Recommendation"  <> '')
				 OR	(C."CR Expiry Date"  IS NOT NULL
				 AND	DATEDIFF(C."CR Expiry Date", CURDATE())  <= 30)
				 OR	(C."Last Reconciliation date"  IS NOT NULL
				 AND	DATEDIFF(CURDATE(), C."Last Reconciliation date")  > 60)
				 OR	C."Tax Registration Number"  IS NULL
				 OR	C."Tax Registration Number"  = ''
				 OR	C."CR NO"  IS NULL
				 OR	C."CR NO"  = '' THEN 'Critical'
				 WHEN C."GLN NO"  IS NULL
				 OR	C."GLN NO"  = ''
				 OR	(C."MOH SFDA License Expiry Date"  IS NOT NULL
				 AND	DATEDIFF(C."MOH SFDA License Expiry Date", CURDATE())  <= 30)
				 OR	C."Branch 1 City"  IS NULL
				 OR	C."Branch 1 City"  = ''
				 OR	C."Location Branch 1"  IS NULL
				 OR	C."Location Branch 1"  = ''
				 OR	C."WH CITY"  IS NULL
				 OR	C."WH CITY"  = '' THEN 'Attention Required'
				 ELSE 'PASS'
			 END AS "Documents Classification"
FROM  "Customers" C 
WHERE	 C."Customer ID"  IS NOT NULL
 AND	C."Customer ID"  <> ''
ORDER BY C."Customer Number"
