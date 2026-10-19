{{-- Customer 360 design, copied from the Al-Nasr Lovable portal (c360Theme.ts + the Tailwind classes of each
     component). Plain CSS because new Tailwind classes can't be compiled on the server. Scoped to .c3. --}}
<style>
    .c3 {
        --c360-bg: #F4F8FC; --c360-card: #FFFFFF; --c360-accent: #0875E1; --c360-accent-strong: #075B9D;
        --c360-heading: #14243B; --c360-label: #64748B; --c360-value: #24344D; --c360-border: #E2EAF2;
        --c360-th: #EAF4FF; --c360-annual: #F6FAFE;
        /* base theme tokens, from their published styles.css */
        --c360-destructive: oklch(55% .2 25.5); --c360-success: oklch(55% .14 150); --c360-muted: oklch(53% .026 257.4);
        --chart-1: oklch(47% .108 244.5); --chart-2: oklch(60% .118 184.7); --chart-3: oklch(62% .13 150);
        --chart-4: oklch(72% .14 75); --chart-5: oklch(55% .15 30);
        background: var(--c360-bg); min-height: calc(100vh - 3.5rem); display: flex; flex-direction: column;
        font-family: "IBM Plex Sans", "IBM Plex Sans Arabic", ui-sans-serif, system-ui, sans-serif; color: var(--c360-value);
    }
    .c3 *, .c3 *::before, .c3 *::after { box-sizing: border-box; }
    .c3 .tnum { font-variant-numeric: tabular-nums; }
    .c3 .ico { width: 16px; height: 16px; stroke-width: 1.75; flex-shrink: 0; }
    .c3 .ico-18 { width: 18px; height: 18px; color: var(--c360-accent); stroke-width: 1.75; flex-shrink: 0; }

    /* header */
    .c3-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 16px 16px 16px; }
    @media (min-width: 640px) { .c3-head { padding-left: 24px; padding-right: 24px; } }
    .c3-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; font-size: 12px; color: var(--c360-label); }
    .c3-crumbs a { color: inherit; text-decoration: none; } .c3-crumbs a:hover { color: var(--c360-accent); text-decoration: underline; }
    .c3-crumbs .cur { font-weight: 600; color: var(--c360-heading); }
    .c3-crumbs svg { width: 12px; height: 12px; }
    .c3-title { margin: 8px 0 0; font-size: 24px; font-weight: 600; line-height: 1.35; letter-spacing: -.015em; color: var(--c360-heading); word-break: break-word; }
    @media (min-width: 640px) { .c3-title { font-size: 26px; } }
    .c3-sub { margin-top: 2px; font-size: 13px; color: var(--c360-label); }
    .c3-sub b { font-weight: 500; color: var(--c360-value); }
    .c3-back { display: inline-flex; height: 36px; align-items: center; gap: 8px; flex-shrink: 0; border-radius: 6px; border: 1px solid var(--c360-accent);
        background: var(--c360-card); padding: 0 16px; font-size: 13px; font-weight: 500; color: var(--c360-accent-strong); text-decoration: none; transition: background .15s; }
    .c3-back:hover { background: var(--c360-th); }

    /* tabs */
    .c3-tabsbar { border-top: 1px solid var(--c360-border); border-bottom: 1px solid var(--c360-border); background: var(--c360-card); }
    .c3-tabs { display: flex; flex-wrap: nowrap; overflow-x: auto; padding: 0 8px; }
    @media (min-width: 640px) { .c3-tabs { padding: 0 16px; } }
    .c3-tab { margin-bottom: -1px; display: inline-flex; flex-shrink: 0; align-items: center; gap: 8px; white-space: nowrap; border: 0; border-bottom: 2px solid transparent;
        background: none; padding: 12px 16px; font-size: 13px; font-weight: 500; color: var(--c360-value); cursor: pointer; transition: color .15s, background .15s; font-family: inherit; }
    .c3-tab:hover { background: var(--c360-bg); color: var(--c360-accent); }
    .c3-tab.on { border-bottom-color: var(--c360-accent); font-weight: 600; color: var(--c360-accent); }
    .c3-panel { min-height: 16rem; flex: 1; padding: 16px; }
    @media (min-width: 640px) { .c3-panel { padding: 16px 24px; } }
    .c3-stack { display: flex; flex-direction: column; gap: 16px; }

    /* cards */
    .c3-card { display: flex; flex-direction: column; border-radius: 8px; border: 1px solid var(--c360-border); background: var(--c360-card); box-shadow: 0 1px 2px rgba(20,36,59,.05); }
    .c3-card-h { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; border-bottom: 1px solid var(--c360-border); padding: 12px 16px; }
    .c3-card-h h2 { margin: 0; flex: 1; font-size: 15px; font-weight: 600; color: var(--c360-heading); }
    .c3-card-h .meta { font-size: 12px; color: var(--c360-label); }
    .c3-card-b { padding: 12px 16px; }
    .c3-note { margin: 8px 0 0; font-size: 11.5px; line-height: 1.45; color: var(--c360-label); }
    .c3-note + .c3-note { margin-top: 2px; }
    .c3-muted { padding: 24px 0; font-size: 13px; color: var(--c360-label); }

    /* KPI matrix tables */
    .c3-scroll { overflow-x: auto; }
    .c3-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
    .c3-tbl th, .c3-tbl td { border: 1px solid var(--c360-border); padding: 8px 12px; }
    .c3-tbl thead th { background: var(--c360-th); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .025em; color: var(--c360-heading); white-space: nowrap; }
    .c3-tbl tbody th { text-align: start; font-weight: 600; color: var(--c360-heading); white-space: nowrap; }
    .c3-tbl td { text-align: end; white-space: nowrap; color: var(--c360-value); font-variant-numeric: tabular-nums; }
    .c3-tbl thead th { text-align: end; }
    .c3-tbl tbody tr:hover { background: var(--c360-bg); }
    .c3-tbl tbody tr.nohover:hover { background: none; }
    .c3-tbl .cur { background: var(--c360-bg); }
    .c3-tbl thead th.cur { border-bottom: 2px solid var(--c360-accent); background: var(--c360-th); }
    .c3-tbl .tot { background: var(--c360-annual); font-weight: 600; color: var(--c360-heading); }
    .c3-tbl .start { text-align: start; }

    /* Overview field cards */
    .c3-grid2 { display: grid; grid-template-columns: 1fr; gap: 16px; }
    @media (min-width: 1024px) { .c3-grid2 { grid-template-columns: 1fr 1fr; align-items: start; } }
    .c3-fields { margin: 0; border-top: 1px solid var(--c360-border); border-bottom: 1px solid var(--c360-border); }
    .c3-field { display: grid; grid-template-columns: minmax(0, 42%) minmax(0, 1fr); min-height: 36px; }
    .c3-field + .c3-field { border-top: 1px solid var(--c360-border); }
    .c3-field dt { display: flex; align-items: center; border-inline-end: 1px solid var(--c360-border); padding: 6px 12px 6px 8px; font-size: 13px; color: var(--c360-label); }
    .c3-field dd { margin: 0; display: flex; min-width: 0; align-items: center; padding: 6px 12px; font-size: 13.5px; font-weight: 500; color: var(--c360-value); }
    .c3-field dd span { min-width: 0; word-break: break-word; line-height: 1.375; }
    .c3-badge { display: inline-flex; align-items: center; border-radius: 4px; border: 1px solid; padding: 2px 8px; font-size: 12px; font-weight: 500; line-height: 20px; }
    .c3-badge.success { border-color: #CDEBD8; background: #ECF8F0; color: #1E7B45; }
    .c3-badge.info { border-color: #CFE3F7; background: var(--c360-th); color: var(--c360-accent-strong); }
    .c3-badge.neutral { border-color: #DDE4EC; background: #F1F4F8; color: var(--c360-label); }
    .c3-info3 { display: grid; grid-template-columns: 1fr; gap: 8px; }
    @media (min-width: 640px) { .c3-info3 { grid-template-columns: repeat(3, 1fr); } }
    .c3-info { border-radius: 6px; border: 1px solid var(--c360-border); padding: 10px 12px; }
    .c3-info .l { font-size: 12.5px; color: var(--c360-label); }
    .c3-info .v { margin-top: 4px; font-size: 13.5px; font-weight: 500; color: var(--c360-value); word-break: break-word; white-space: pre-line; }
    .c3-branches { margin-top: 12px; overflow-x: auto; border-radius: 6px; border: 1px solid var(--c360-border); }
    .c3-branches table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .c3-branches th { background: var(--c360-th); color: var(--c360-heading); border-bottom: 1px solid var(--c360-border); padding: 8px 12px; text-align: start; font-weight: 600; }
    .c3-branches td { padding: 8px 12px; font-weight: 500; color: var(--c360-value); border-top: 1px solid var(--c360-border); word-break: break-word; }
    .c3-branches td.n { color: var(--c360-label); font-weight: 400; }
    .c3-branches a { color: var(--c360-accent); }

    /* KPI cards (AR / Credit Limit) */
    .c3-kgrid { display: grid; grid-template-columns: 1fr; gap: 12px; }
    @media (min-width: 640px) { .c3-kgrid { grid-template-columns: 1fr 1fr; } }
    @media (min-width: 1280px) { .c3-kgrid.k3 { grid-template-columns: repeat(3, 1fr); } .c3-kgrid.k4 { grid-template-columns: repeat(4, 1fr); } }
    .c3-kpi { border-radius: 6px; border: 1px solid var(--c360-border); background: #fff; padding: 12px 16px; }
    .c3-kpi .l { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; font-size: 12px; font-weight: 500; color: var(--c360-label); }
    .c3-kpi .v { margin-top: 4px; font-size: 20px; font-weight: 600; color: var(--c360-heading); font-variant-numeric: tabular-nums; }
    .c3-kpi .s { margin-top: 2px; font-size: 11.5px; color: var(--c360-label); }
    .c3-iconbtn { display: inline-flex; height: 24px; width: 24px; flex-shrink: 0; align-items: center; justify-content: center; border-radius: 4px; border: 0; background: none;
        color: var(--c360-label); cursor: pointer; margin: -2px -4px 0 0; }
    .c3-iconbtn:hover { background: var(--c360-th); color: var(--c360-accent); }
    .c3-status { display: inline-flex; align-items: center; white-space: nowrap; border-radius: 4px; border: 1px solid; padding: 2px 8px; font-size: 14px; font-weight: 600; line-height: 24px; }
    .c3-status.neutral { border-color: var(--c360-border); background: var(--c360-bg); color: var(--c360-label); }
    .c3-status.info { border-color: var(--c360-border); background: var(--c360-th); color: var(--c360-accent-strong); }
    .c3-status.healthy { border-color: oklch(55% .14 150 / .3); background: oklch(55% .14 150 / .1); color: var(--c360-success); }
    .c3-status.high { border-color: oklch(72% .14 75 / .4); background: oklch(72% .14 75 / .15); color: oklch(24% .028 258); }
    .c3-status.over { border-color: oklch(55% .2 25.5 / .3); background: oklch(55% .2 25.5 / .1); color: var(--c360-destructive); }

    /* charts */
    .c3-charts { display: grid; grid-template-columns: 1fr; gap: 16px; }
    @media (min-width: 1280px) { .c3-charts.c3col { grid-template-columns: repeat(3, 1fr); } .c3-charts.c2col { grid-template-columns: repeat(2, 1fr); } }
    .c3-chart-body { padding: 16px 8px 8px; }
    .c3-chart-box { position: relative; height: 280px; width: 100%; }
    .c3-chart-body .c3-note { padding: 4px 8px 0; margin: 0; }
    .c3-expand { display: inline-flex; height: 28px; width: 28px; flex-shrink: 0; align-items: center; justify-content: center; border-radius: 6px; border: 0; background: none;
        color: var(--c360-label); cursor: pointer; }
    .c3-expand:hover { background: var(--c360-th); color: var(--c360-accent); }

    /* dialog + drawer */
    .c3-overlay { position: fixed; inset: 0; z-index: 60; background: rgba(0,0,0,.8); }
    .c3-dialog { position: fixed; left: 50%; top: 50%; z-index: 61; transform: translate(-50%, -50%); display: flex; flex-direction: column; height: 85vh; width: 95vw;
        overflow: hidden; border-radius: 8px; border: 1px solid var(--c360-border); background: var(--c360-card); box-shadow: 0 10px 30px rgba(0,0,0,.2); }
    @media (min-width: 640px) { .c3-dialog { width: 92vw; } }
    .c3-dialog-h { display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--c360-border); padding: 12px 48px 12px 20px; }
    .c3-dialog-h h3 { margin: 0; font-size: 17px; font-weight: 600; color: var(--c360-heading); }
    .c3-close { position: absolute; right: 16px; top: 14px; border: 0; background: none; color: var(--c360-label); cursor: pointer; opacity: .7; padding: 0; }
    .c3-close:hover { opacity: 1; }
    .c3-dialog-b { display: flex; min-height: 0; flex: 1; flex-direction: column; padding: 20px 12px 12px; }
    @media (min-width: 640px) { .c3-dialog-b { padding: 20px 20px 12px; } }
    .c3-dialog-b .c3-chart-box { flex: 1; min-height: 0; height: auto; }
    .c3-dialog-b .c3-note { padding: 8px 8px 0; font-size: 12.5px; }
    .c3-drawer { position: fixed; top: 0; right: 0; z-index: 61; display: flex; flex-direction: column; height: 100%; width: 100%; background: var(--c360-card); box-shadow: -8px 0 24px rgba(0,0,0,.15); }
    @media (min-width: 640px) { .c3-drawer { width: 720px; } }
    .c3-drawer-h { border-bottom: 1px solid var(--c360-border); padding: 12px 48px 12px 16px; }
    .c3-drawer-h h3 { margin: 0; font-size: 15px; font-weight: 600; color: var(--c360-heading); }
    .c3-drawer-h p { margin: 2px 0 0; font-size: 12px; color: var(--c360-label); }
    .c3-drawer-b { min-height: 0; flex: 1; overflow: auto; }
    .c3-list { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 12.5px; }
    .c3-list th { position: sticky; top: 0; z-index: 1; border-bottom: 1px solid var(--c360-border); background: var(--c360-th); padding: 6px 10px; font-size: 12px; font-weight: 600; color: var(--c360-heading); white-space: nowrap; }
    .c3-list td { border-bottom: 1px solid var(--c360-border); padding: 4px 10px; white-space: nowrap; color: var(--c360-value); }
    .c3-list tr:hover td { background: var(--c360-bg); }

    /* sub-tabs (Item owners, Sales order statuses) and segmented control */
    .c3-subtabs { display: flex; flex-wrap: nowrap; overflow-x: auto; padding: 0 8px; border-bottom: 1px solid var(--c360-border); }
    .c3-subtab { margin-bottom: -1px; flex-shrink: 0; white-space: nowrap; border: 0; border-bottom: 2px solid transparent; background: none; padding: 10px 12px; font-size: 13px;
        font-weight: 500; color: var(--c360-value); cursor: pointer; font-family: inherit; }
    .c3-subtab:hover { background: var(--c360-bg); color: var(--c360-accent); }
    .c3-subtab.on { border-bottom-color: var(--c360-accent); font-weight: 600; color: var(--c360-accent); }
    .c3-subtab .n { margin-inline-start: 6px; color: var(--c360-label); font-variant-numeric: tabular-nums; }
    .c3-seg { display: inline-flex; overflow: hidden; border-radius: 6px; border: 1px solid var(--c360-border); }
    .c3-seg button { border: 0; padding: 4px 12px; font-size: 12.5px; font-weight: 500; background: var(--c360-card); color: var(--c360-value); cursor: pointer; font-family: inherit; }
    .c3-seg button:hover { background: var(--c360-th); }
    .c3-seg button.on { background: var(--c360-accent); color: var(--c360-card); }
    .c3-xl2 { display: grid; grid-template-columns: 1fr; gap: 12px; padding: 16px; }
    @media (min-width: 1280px) { .c3-xl2 { grid-template-columns: 1fr 1fr; } }
    .c3-rank { display: flex; min-width: 0; flex-direction: column; border-radius: 6px; border: 1px solid var(--c360-border); background: var(--c360-card); }
    .c3-rank-h { border-bottom: 1px solid var(--c360-border); padding: 8px 12px; font-size: 13px; font-weight: 600; color: var(--c360-heading); }
    .c3-rank table th { position: sticky; top: 0; }
    .c3-legend { display: grid; grid-template-columns: 1fr; gap: 4px 16px; padding: 0 4px; font-size: 12px; list-style: none; margin: 0; }
    @media (min-width: 640px) { .c3-legend { grid-template-columns: 1fr 1fr; } }
    .c3-legend li { display: flex; min-width: 0; align-items: center; gap: 8px; }
    .c3-legend .sw { height: 10px; width: 10px; flex-shrink: 0; border-radius: 2px; }
    .c3-legend .nm { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--c360-value); }
    .c3-legend .pc { flex-shrink: 0; font-weight: 600; color: var(--c360-heading); font-variant-numeric: tabular-nums; }
    .c3-sokpi { display: inline-flex; flex-direction: column; border-radius: 4px; border: 1px solid var(--c360-border); background: var(--c360-bg); padding: 8px 12px; }
    .c3-sokpi .l { font-size: 11px; text-transform: uppercase; letter-spacing: .025em; color: var(--c360-label); }
    .c3-sokpi .v { font-size: 17px; font-weight: 600; color: var(--c360-heading); font-variant-numeric: tabular-nums; }
    /* Insights cards */
    .c3-ins-list { border-radius: 6px; border: 1px solid var(--c360-border); }
    .c3-ins-list > article { padding: 12px; font-size: 13px; line-height: 1.625; }
    .c3-ins-list > article + article { border-top: 1px solid var(--c360-border); }
    .c3-ins-list > article > * + * { margin-top: 6px; }
    .c3-ins-list h3 { margin: 0; font-size: 13px; font-weight: 600; color: var(--c360-heading); }
    .c3-ins-list p { margin: 0; color: var(--c360-value); }
    .c3-ins-list .h { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 8px; }
    .c3-chip { border-radius: 4px; border: 1px solid var(--c360-border); background: var(--c360-bg); padding: 2px 8px; font-size: 11px; color: var(--c360-label); }
    .c3-ins-ul { margin: 0; padding: 0; list-style: none; }
    .c3-ins-ul li + li { margin-top: 2px; }
    .c3-ins-ul li > span:first-child { color: var(--c360-label); }
    .c3-tag { margin-inline-start: 8px; border-radius: 4px; border: 1px solid var(--c360-border); background: var(--c360-bg); padding: 2px 6px; font-size: 10.5px; color: var(--c360-label); }
    .c3-tag.new { border-color: var(--c360-accent); background: var(--c360-th); color: var(--c360-accent-strong); }
    [x-cloak] { display: none !important; }
</style>
