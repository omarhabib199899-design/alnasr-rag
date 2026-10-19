{{-- The Lovable portal's PageShell + list-page look (src/styles.css tokens, PageShell.tsx, MultiSelectFilter.tsx), scoped to .lv
     so it never touches the rest of the app. Used by the pages copied from their plain (non-360) list routes. --}}
<style>
    [x-cloak] { display: none !important; }
    .lv { --bg: oklch(0.975 0.003 247.9); --fg: oklch(0.24 0.028 258); --surface: #fff; --muted: oklch(0.955 0.007 247.9);
        --muted-fg: oklch(0.53 0.026 257.4); --border: oklch(0.912 0.01 255.5); --primary: oklch(0.47 0.108 244.5);
        --accent: oklch(0.94 0.017 244.5); --destructive: oklch(0.55 0.2 25.5); --success: oklch(0.55 0.14 150);
        font-family: "IBM Plex Sans", "IBM Plex Sans Arabic", ui-sans-serif, system-ui, sans-serif; font-size: 14px; line-height: 1.45;
        color: var(--fg); background: var(--bg); display: flex; flex-direction: column; gap: 12px; padding: 20px; min-height: calc(100vh - 3.5rem); }
    .lv *, .lv *::before, .lv *::after { border-color: var(--border); box-sizing: border-box; }
    .lv-crumb { margin-bottom: 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--muted-fg); }
    .lv h1 { margin: 0; font-size: 18px; font-weight: 600; letter-spacing: -.01em; color: var(--fg); }
    .lv-sub { margin: 2px 0 0; font-size: 13px; color: var(--muted-fg); }
    .lv-panel { display: flex; flex: 1; flex-direction: column; background: var(--surface); border: 1px solid var(--border); border-radius: .25rem; min-height: 0; }
    .lv-msg { padding: 24px; font-size: 12.5px; color: var(--muted-fg); } .lv-msg.err { color: var(--destructive); }
    .lv-bar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px; border-bottom: 1px solid var(--border); padding: 12px; }
    .lv-fld { display: block; font-size: 11px; color: var(--muted-fg); }
    .lv-in { height: 28px; width: 100%; border-radius: .125rem; border: 1px solid var(--border); background: var(--bg); padding: 0 8px; font-size: 12px;
        color: var(--fg); font-family: inherit; box-shadow: none; } .lv-in:focus { outline: none; box-shadow: none; border-color: var(--primary); }
    .lv-btn { height: 28px; border-radius: .125rem; border: 1px solid var(--border); background: transparent; padding: 0 8px; font-size: 12px; color: var(--fg);
        cursor: pointer; font-family: inherit; } .lv-btn:disabled { opacity: .4; cursor: default; }
    .lv-chips { display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid var(--border); padding: 8px 12px; }
    .lv-chip { display: flex; align-items: center; gap: 4px; border-radius: .125rem; border: 1px solid var(--border); background: var(--muted); padding: 2px 8px;
        font-size: 11.5px; color: var(--fg); cursor: pointer; font-family: inherit; } .lv-chip:hover { background: var(--accent); } .lv-chip .k { color: var(--muted-fg); }
    .lv-scroll { max-height: 68vh; min-height: 16rem; flex: 1;min-w-0; overflow: auto; scrollbar-width: auto; scrollbar-color: #94a3b8 #e2e8f0; }
    /* always-visible, thick scrollbars so the horizontal one can be grabbed (the table is wider than the screen) */
    .lv-scroll::-webkit-scrollbar { width: 14px; height: 14px; } .lv-scroll::-webkit-scrollbar-track { background: #e2e8f0; }
    .lv-scroll::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 7px; border: 3px solid #e2e8f0; } .lv-scroll::-webkit-scrollbar-thumb:hover { background: #64748b; }
    .lv table { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 12px; }
    .lv thead { position: sticky; top: 0; z-index: 10; background: var(--muted); }
    .lv th { cursor: pointer; user-select: none; white-space: nowrap; border-bottom: 1px solid var(--border); border-inline-end: 1px solid var(--border);
        padding: 6px 8px; font-weight: 600; text-align: start; color: var(--fg); background: var(--muted); }
    .lv td { white-space: nowrap; border-bottom: 1px solid var(--border); border-inline-end: 1px solid var(--border); padding: 4px 8px; color: var(--fg); }
    .lv tbody tr:nth-child(odd) { background: var(--surface); } .lv tbody tr:nth-child(even) { background: var(--bg); } .lv tbody tr:hover { background: var(--muted); }
    .lv .end { text-align: end; font-variant-numeric: tabular-nums; }
    .lv a.lk { color: var(--primary); text-decoration: none; } .lv a.lk:hover { text-decoration: underline; }
    .lv-empty { padding: 32px; text-align: center; color: var(--muted-fg); }
    .lv-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; border-top: 1px solid var(--border); padding: 8px 12px; font-size: 12px; }
    .lv-foot .m { color: var(--muted-fg); }
    /* MultiSelectFilter */
    .lv-ms { position: relative; min-width: 0; font-size: 11px; color: var(--muted-fg); }
    .lv-ms > span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lv-ms-btn { display: flex; height: 28px; width: 100%; align-items: center; justify-content: space-between; gap: 4px; border-radius: .125rem; border: 1px solid var(--border);
        background: var(--bg); padding: 0 8px; text-align: start; font-size: 12px; color: var(--muted-fg); cursor: pointer; font-family: inherit; }
    .lv-ms-btn.on { border-color: var(--primary); color: var(--fg); } .lv-ms-btn > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lv-ms-pop { position: absolute; inset-inline-start: 0; top: 100%; z-index: 30; margin-top: 4px; width: 16rem; border-radius: .125rem; border: 1px solid var(--border);
        background: #fff; color: var(--fg); box-shadow: 0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -2px rgba(0,0,0,.1); }
    .lv-ms-list { max-height: 16rem; overflow: auto; padding: 4px 0; }
    .lv-ms-row { display: flex; cursor: pointer; align-items: center; gap: 8px; padding: 2px 8px; font-size: 12px; color: var(--fg); } .lv-ms-row:hover { background: var(--muted); }
    .lv-ms-row .t { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .lv-ms-row .c { color: var(--muted-fg); font-variant-numeric: tabular-nums; }
    .lv-ms-clear { border: 0; background: none; font-size: 12px; color: var(--primary); cursor: pointer; font-family: inherit; } .lv-ms-clear:hover { text-decoration: underline; }
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
