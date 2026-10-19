{{--
    Shared design layer matching the reference Lovable portal's list pages
    (its "c360" theme tokens, card/table/input/button metrics). Applied by
    targeting the class patterns every redesigned page already uses, so the
    whole app picks it up from this one file — no per-page edits and no
    Tailwind rebuild (new utility classes wouldn't exist in the compiled
    bundle). Scoped to <main> so the sidebar/topbar are unaffected; the
    customer-360 detail pages use their own .c360 stylesheet and markup
    (no thead.bg-[#eef3fa], no .py-8 wrapper), so they're unaffected too.
--}}
<style>
    :root{
        --c360-bg:#F4F8FC;--c360-card:#FFFFFF;--c360-accent:#0875E1;--c360-accent-strong:#075B9D;
        --c360-heading:#14243B;--c360-label:#64748B;--c360-value:#24344D;--c360-border:#E2EAF2;--c360-th:#EAF4FF;
        --lv-primary:oklch(47% .108 244.5);--lv-primary-hover:oklch(42% .108 244.5);
    }

    /* Page frame: full width, 24px gutters, 16px between blocks */
    main > .py-8{padding-top:16px;padding-bottom:24px}
    main > .py-8 > .mx-auto{max-width:none;padding-left:16px;padding-right:16px}
    @media (min-width:640px){main > .py-8 > .mx-auto{padding-left:24px;padding-right:24px}}
    main > .py-8 > .mx-auto.space-y-5 > * + *{margin-top:16px}

    /* Cards */
    main .bg-white.rounded-lg.border-gray-200{border-color:var(--c360-border);box-shadow:0 1px 2px rgba(20,36,59,.05)}
    main .bg-white.rounded-lg.border-gray-200 .border-gray-100{border-color:var(--c360-border)}
    main .bg-white.rounded-lg.border-gray-200 > .border-b:first-child{padding:12px 16px}
    main .bg-white.rounded-lg.border-gray-200 > .border-t:last-child{padding:10px 16px;font-size:12.5px;color:var(--c360-label)}
    main .bg-white.rounded-lg.border-gray-200 h3{font-size:15px;font-weight:600;color:var(--c360-heading);gap:10px}
    main .bg-white.rounded-lg.border-gray-200 h3 > svg{width:18px;height:18px;color:var(--c360-accent);flex-shrink:0}
    main .bg-white.rounded-lg.border-gray-200 h3 + p{font-size:12.5px;color:var(--c360-label)}

    /* Tables */
    main thead.bg-\[\#eef3fa\]{background:var(--c360-th)}
    main thead.bg-\[\#eef3fa\] th{font-size:12.5px;font-weight:600;text-transform:none;letter-spacing:normal;color:var(--c360-heading);padding:10px 16px;border-bottom:1px solid var(--c360-border);white-space:nowrap}
    main thead.bg-\[\#eef3fa\] th a:hover{color:var(--c360-accent-strong)}
    main thead.bg-\[\#eef3fa\] ~ tbody td{font-size:13px;padding:10px 16px}
    main thead.bg-\[\#eef3fa\] ~ tbody > tr:hover{background:var(--c360-bg)}
    main table.divide-gray-100 > * + *,main tbody.divide-gray-100 > * + *{border-color:var(--c360-border)}
    main thead.bg-\[\#eef3fa\] ~ tbody .text-gray-900{color:var(--c360-heading)}
    main thead.bg-\[\#eef3fa\] ~ tbody .text-gray-500,main thead.bg-\[\#eef3fa\] ~ tbody .text-gray-600{color:var(--c360-value)}
    main thead.bg-\[\#eef3fa\] ~ tbody td.text-right{font-variant-numeric:tabular-nums}
    main .text-blue-700{color:var(--lv-primary)}

    /* Form controls */
    main input[type=text],main input[type=date],main input[type=search],main select{border-color:var(--c360-border);font-size:13px;color:var(--c360-value);border-radius:6px}
    main input[type=text]:focus,main input[type=date]:focus,main input[type=search]:focus,main select:focus{border-color:var(--c360-accent);box-shadow:none;--tw-ring-shadow:0 0 #0000}
    main label.uppercase{text-transform:none;font-size:12px;letter-spacing:normal;color:var(--c360-label)}

    /* Primary buttons and active pills */
    main .bg-gray-900{background-color:var(--lv-primary)}
    main .bg-gray-900:hover,main .hover\:bg-gray-700:hover{background-color:var(--lv-primary-hover)}

    /* Pagination (resources/views/vendor/pagination/tailwind.blade.php) */
    .lv-pager{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;font-size:12.5px;color:var(--c360-label)}
    .lv-pager-nav{display:flex;align-items:center;gap:8px}
    .lv-pager-btn{display:inline-flex;align-items:center;height:32px;border-radius:6px;border:1px solid var(--c360-border);background:var(--c360-card);padding:0 12px;font-size:12.5px;font-weight:500;color:var(--c360-value);text-decoration:none}
    a.lv-pager-btn:hover{background:var(--c360-bg)}
    span.lv-pager-btn{opacity:.4;cursor:default}
</style>
