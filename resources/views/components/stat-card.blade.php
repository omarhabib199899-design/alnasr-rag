@props(['label', 'value'])

{{-- Matches the reference portal's stat card: 12.5px label, 20px semibold tabular value. --}}
<div class="lv-stat">
    <div class="lv-stat-label">{{ $label }}</div>
    <div class="lv-stat-value">{{ $value }}</div>
</div>

@once
    <style>
        .lv-stat{border-radius:8px;border:1px solid var(--c360-border,#E2EAF2);background:var(--c360-card,#fff);padding:12px 16px;box-shadow:0 1px 2px rgba(20,36,59,.05)}
        .lv-stat-label{font-size:12.5px;color:var(--c360-label,#64748B)}
        .lv-stat-value{margin-top:4px;font-size:20px;font-weight:600;font-variant-numeric:tabular-nums;color:var(--c360-heading,#14243B)}
    </style>
@endonce
