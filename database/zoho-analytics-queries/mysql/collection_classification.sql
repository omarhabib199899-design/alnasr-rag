/* MySQL override of ../collection_classification.sql — identical logic; the only change is
   MEDIAN("Settlement Delay Days"), which MySQL lacks: computed over the same (≤ 5 latest) invoices
   per customer as the middle value (odd count) or the mean of the two middle values (even count). */
SELECT
		 X.`customer_id` AS `customer_id`,
		 X.`customer_number` AS `customer_number`,
		 X.`customer_name` AS `customer_name`,
		 COUNT(X.`invoice_id`) AS `invoices_used`,
		 MAX(X.`settlement_date`) AS `last_settlement_date`,
		 MAX(X.`median_delay`) AS `median_settlement_delay_days`,
		 CASE
				 WHEN MAX(X.`median_delay`) <= 15 THEN 'On Time / Perfect'
				 WHEN MAX(X.`median_delay`) <= 30 THEN 'Late'
				 WHEN MAX(X.`median_delay`) <= 60 THEN 'Very Late'
				 ELSE 'Severe Delay'
			 END AS `collection_classification`,
		 SUM(CASE WHEN DAYOFMONTH(X.`settlement_date`) BETWEEN 1 AND 10 THEN 1 ELSE 0 END) AS `early_month_count`,
		 SUM(CASE WHEN DAYOFMONTH(X.`settlement_date`) BETWEEN 11 AND 20 THEN 1 ELSE 0 END) AS `mid_month_count`,
		 SUM(CASE WHEN DAYOFMONTH(X.`settlement_date`) >= 21 THEN 1 ELSE 0 END) AS `late_month_count`,
		 CASE
				 WHEN SUM(CASE WHEN DAYOFMONTH(X.`settlement_date`) BETWEEN 1 AND 10 THEN 1 ELSE 0 END) > COUNT(X.`invoice_id`) / 2 THEN 'Early Month'
				 WHEN SUM(CASE WHEN DAYOFMONTH(X.`settlement_date`) BETWEEN 11 AND 20 THEN 1 ELSE 0 END) > COUNT(X.`invoice_id`) / 2 THEN 'Mid Month'
				 WHEN SUM(CASE WHEN DAYOFMONTH(X.`settlement_date`) >= 21 THEN 1 ELSE 0 END) > COUNT(X.`invoice_id`) / 2 THEN 'Late Month'
				 ELSE 'Random / Mixed'
			 END AS `payment_timing_pattern`
FROM (	SELECT
			 Y.*,
			 AVG(CASE WHEN Y.`delay_rank` IN (FLOOR((Y.`cnt` + 1) / 2), FLOOR((Y.`cnt` + 2) / 2)) THEN Y.`settlement_delay_days` END)
				 OVER(PARTITION BY Y.`customer_id`) AS `median_delay`
	FROM (	SELECT
				 Z.*,
				 ROW_NUMBER() OVER(PARTITION BY Z.`customer_id` ORDER BY Z.`settlement_delay_days`) AS `delay_rank`,
				 COUNT(*) OVER(PARTITION BY Z.`customer_id`) AS `cnt`
		FROM (	SELECT
					 `customer_id`,
					 `customer_number`,
					 `customer_name`,
					 `invoice_id`,
					 `settlement_date`,
					 `settlement_delay_days`,
					 ROW_NUMBER() OVER(PARTITION BY `customer_id` ORDER BY `settlement_date` DESC, `invoice_id` DESC) AS `invoice_rank`
			FROM `zoho_collection_classification_staging`
			WHERE `closing_method` = 'CLOSED BY PAYMENT'
			 AND `settlement_date` IS NOT NULL
			 AND `settlement_delay_days` IS NOT NULL
		) Z
		WHERE Z.`invoice_rank` <= 5
	) Y
) X
GROUP BY X.`customer_id`,
	 X.`customer_number`,
	 X.`customer_name`
ORDER BY X.`customer_number`
