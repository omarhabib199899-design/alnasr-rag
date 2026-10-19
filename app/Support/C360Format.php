<?php

namespace App\Support;

/**
 * Display formatting identical to the Lovable portal's (src/lib/format.ts and
 * the Intl.NumberFormat("en-US") formatters in each Customer 360 component).
 * Display only — never used for calculations.
 */
class C360Format
{
    public const DASH = '—';

    /** formatMoney: whole SAR, en-US separators, half away from zero, never "-0". */
    public static function money(?float $v): string
    {
        if ($v === null) {
            return self::DASH;
        }
        $s = number_format(round($v), 0, '.', ',');

        return $s === '-0' ? '0' : $s;
    }

    public static function sar(?float $v): string
    {
        return $v === null ? self::DASH : 'SAR '.self::money($v);
    }

    public static function pct2(?float $v): string
    {
        return $v === null ? self::DASH : number_format($v, 2, '.', ',').'%';
    }

    public static function pct1(?float $v): string
    {
        return $v === null ? self::DASH : number_format($v, 1, '.', ',').'%';
    }

    public static function int(?int $v): string
    {
        return $v === null ? self::DASH : number_format($v, 0, '.', ',');
    }

    public static function dec2(?float $v): string
    {
        return $v === null ? self::DASH : number_format($v, 2, '.', ',');
    }

    /** Quantities: up to 2 decimals, trailing zeros dropped (Intl maximumFractionDigits: 2). */
    public static function qty(?float $v): string
    {
        if ($v === null) {
            return self::DASH;
        }
        $s = number_format($v, 2, '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    public static function rank(?int $rank, ?int $total): string
    {
        return ($rank === null || $total === null) ? self::DASH : '#'.number_format($rank).' / '.number_format($total);
    }

    /** YYYY-MM-DD → DD-MM-YYYY ('' when empty, like their ddmmyyyy helper). */
    public static function dmy(?string $iso): string
    {
        if (! $iso) {
            return '';
        }
        [$y, $m, $d] = explode('-', substr($iso, 0, 10));

        return "{$d}-{$m}-{$y}";
    }

    /** YYYY-MM-DD → DD/MM/YYYY ("—" when empty). */
    public static function slash(?string $iso): string
    {
        if (! $iso) {
            return self::DASH;
        }
        [$y, $m, $d] = explode('-', substr($iso, 0, 10));

        return "{$d}/{$m}/{$y}";
    }

    public const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public const MONTHS_FULL = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public static function monthShort(string $iso, bool $current = false): string
    {
        return self::MONTHS_SHORT[(int) substr($iso, 5, 2) - 1].' '.substr($iso, 0, 4).($current ? '*' : '');
    }

    public static function monthFull(string $iso): string
    {
        return self::MONTHS_FULL[(int) substr($iso, 5, 2) - 1].' '.substr($iso, 0, 4);
    }

    public const PERIODS = [
        'lifetime' => 'Lifetime', 'previous_year' => 'Previous Year', 'ytd' => 'YTD', 'previous_month' => 'Previous Month',
        'mtd' => 'MTD', 'yesterday' => 'Yesterday', 'today' => 'Today',
    ];
}
