<?php

namespace App\Services\Zoho;

/**
 * Runs Dr. Ahmed's Zoho Analytics queries (database/zoho-analytics-queries/,
 * stored verbatim) on MySQL without hand-rewriting them. Zoho Analytics SQL
 * is MySQL-flavoured (ADDDATE, DATEDIFF, IFNULL, CURDATE, window functions
 * all mean the same thing), so the only translation needed is identifiers:
 *
 *   "Accrual Transactions"        → zoho_raw_accrual_transactions (raw table copy)
 *   "AR SALES"                    → zoho_ar_sales (another query's output)
 *   AT."Transaction Amount (BCY)" → AT.`transaction_amount_bcy`
 *   AS "GP %"                     → AS `gp_pct`  (= the Lovable portal's column names)
 *
 * Double quotes are always identifiers in Zoho SQL (string literals use
 * single quotes), so a single pass over the text is exact. The one Zoho-only
 * function, MEDIAN(), is handled by a per-query MySQL override (see
 * ZohoQueries::OVERRIDES) rather than guessed at here.
 */
class ZohoQueryTranslator
{
    /** @param  array<string, string>  $tables  Zoho table/query name => MySQL table */
    public function __construct(private readonly array $tables) {}

    public function translate(string $zohoSql): string
    {
        if (preg_match('/\bMEDIAN\s*\(/i', $this->stripLiterals($zohoSql))) {
            throw new \RuntimeException('MEDIAN() has no MySQL equivalent — this query needs a MySQL override.');
        }

        $out = '';
        $len = strlen($zohoSql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $zohoSql[$i];

            if ($ch === "'") {                       // string literal — copied untouched ('' is an escaped quote)
                $j = $i + 1;
                while ($j < $len && ! ($zohoSql[$j] === "'" && ($zohoSql[$j + 1] ?? '') !== "'")) {
                    $j += $zohoSql[$j] === "'" ? 2 : 1;
                }
                $out .= substr($zohoSql, $i, $j - $i + 1);
                $i = $j;

                continue;
            }
            if ($ch === '/' && ($zohoSql[$i + 1] ?? '') === '*') {   // block comment — copied untouched
                $end = strpos($zohoSql, '*/', $i + 2);
                $end = $end === false ? $len - 1 : $end + 1;
                $out .= substr($zohoSql, $i, $end - $i + 1);
                $i = $end;

                continue;
            }
            if ($ch === '"') {                       // identifier
                $end = strpos($zohoSql, '"', $i + 1);
                if ($end === false) {
                    throw new \RuntimeException("Unterminated identifier at offset {$i}.");
                }
                $name = substr($zohoSql, $i + 1, $end - $i - 1);
                $out .= '`'.($this->tables[$name] ?? ZohoRawTables::normalizeHeader($name)).'`';
                $i = $end;

                continue;
            }
            $out .= $ch;
        }

        return $out;
    }

    private function stripLiterals(string $sql): string
    {
        return preg_replace(["/'(?:[^']|'')*'/", '/\/\*.*?\*\//s'], '', $sql);
    }
}
