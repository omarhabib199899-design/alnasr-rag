<style>
    {{-- Customer 360 — matches the "Al Nasr Portal" reference design (mockup
       file: al_nasr_customer360_final_mockup_corrected_kpis), scoped under
       .c360 so it never bleeds into the rest of the app's Tailwind styling.
       Extracted into this shared partial 2026-10-14 (was duplicated inline
       in customers/show.blade.php only) so every page reusing the same
       360-style card/tab layout — Sales Rep/Data Entry/Sales Owner detail
       pages, per the manager's 2026-10-13 spec — looks identical without
       copy-pasting ~100 lines of CSS into each one. Confirmed live the hard
       way: the Sales Rep detail page shipped with zero styling the first
       time around because this block only existed inside the Customer
       page's own <style> tag, invisible to any other view. --}}
    .c360{
        --navy:#16345f;--blue:#2f7de1;--blueSoft:#eaf3ff;--line:#d9e6f5;--line2:#e9f0f8;
        --text:#183153;--muted:#6f839d;--green:#139653;--greenSoft:#eaf8f0;
        --amber:#a96d12;--amberSoft:#fff6e3;--rose:#ad4056;--roseSoft:#fff0f3;
        --teal:#1c7f78;--tealSoft:#ebfbf9;--violet:#6c50b2;--violetSoft:#f3efff;
        --shadow:0 6px 22px rgba(27,60,102,.055);
        font-family:"IBM Plex Sans","IBM Plex Sans Arabic",Segoe UI,Arial,sans-serif;color:var(--text);font-size:13px;
    }
    .c360 .customer-head{background:#fff;border:1px solid var(--line2);border-radius:11px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow);flex-wrap:wrap;gap:10px}
    .c360 .customer-title{display:flex;align-items:center;gap:12px}
    .c360 .avatar{width:40px;height:40px;border-radius:9px;background:linear-gradient(135deg,#2f7de1,#7bb2f4);display:grid;place-items:center;color:#fff;font-weight:800;font-size:14px;flex-shrink:0}
    .c360 .customer-title h1{font-size:18px;margin:0 0 2px;font-weight:800}
    .c360 .customer-title p{font-size:12px;margin:0;color:var(--muted)}
    .c360 .head-badges{display:flex;gap:6px;flex-wrap:wrap}
    .c360 .badge{padding:4px 9px;border-radius:999px;font-size:10px;font-weight:800;background:var(--greenSoft);color:var(--green)}
    .c360 .badge.gray{background:#f1f3f6;color:#556}
    .c360 .badge.soon{background:#fdf1de;color:#a96d12}
    .c360 .tabs{background:#fff;border:1px solid var(--line2);border-top:none;display:flex;overflow-x:auto;white-space:nowrap;box-shadow:var(--shadow);margin-top:8px}
    .c360 .tab{padding:11px 15px;border:0;background:transparent;color:#425a78;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;font-size:12.5px}
    .c360 .tab.active{color:#1262cc;border-bottom-color:var(--blue);background:linear-gradient(180deg,#fff,#f7fbff)}
    .c360 .tab:hover{background:#f7faff}
    .c360 .tab-content{background:#fff;border:1px solid var(--line2);border-top:0;padding:14px 16px 18px}
    .c360 .section-head{background:linear-gradient(90deg,#eaf3ff,#f7fbff);border:1px solid #d8e7fa;border-radius:9px;padding:8px 11px;display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
    .c360 .section-head h2{font-size:13px;margin:0;display:flex;align-items:center;gap:8px;font-weight:800}
    .c360 .section-icon{width:20px;height:20px;border-radius:5px;background:var(--blue);color:#fff;display:grid;place-items:center;font-size:10px;font-weight:800}
    .c360 .group-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .c360 .group{border:1px solid var(--line2);border-radius:9px;overflow:hidden}
    .c360 .group.full{grid-column:1/-1}
    .c360 .group-title{padding:8px 11px;font-weight:800;border-bottom:1px solid var(--line2);font-size:12px}
    .c360 .tint-blue{background:var(--blueSoft);color:#285b9b}
    .c360 .tint-green{background:var(--greenSoft);color:#28794a}
    .c360 .tint-amber{background:var(--amberSoft);color:#8a631b}
    .c360 .tint-rose{background:var(--roseSoft);color:#9d4257}
    .c360 .tint-teal{background:var(--tealSoft);color:#1f716d}
    .c360 .tint-violet{background:var(--violetSoft);color:#654d9a}
    .c360 .c-rows{display:grid;grid-template-columns:1fr 1fr}
    .c360 .c-row{display:flex;justify-content:space-between;gap:10px;padding:7px 11px;border-bottom:1px solid #edf2f7;min-height:32px;align-items:center}
    .c360 .c-row .label{color:#6d8198;font-size:12px}
    .c360 .c-row .val{font-weight:800;text-align:right;font-size:12.5px}
    .c360 .c-row .val.green{color:var(--green)}
    .c360 .c-row .val.rose{color:var(--rose)}
    .c360 .c-row .val.amber{color:var(--amber)}
    .c360 .c-row .val.gray{color:var(--muted)}
    .c360 .placeholder-card{border:1px dashed var(--line);border-radius:9px;padding:24px;text-align:center;color:var(--muted);font-size:12.5px;background:#fbfdff}
    .c360 .kpi-table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--line);border-radius:7px;overflow:hidden}
    .c360 .kpi-table th,.c360 .kpi-table td{padding:7px 9px;border-bottom:1px solid var(--line2);border-right:1px solid var(--line2);text-align:left}
    .c360 .kpi-table th:last-child,.c360 .kpi-table td:last-child{border-right:0}
    .c360 .kpi-table tr:last-child td{border-bottom:0}
    .c360 .kpi-table th{background:#eef5fd;color:#37516e;font-size:10px}
    .c360 .kpi-table td{font-size:12px}
    .c360 .kpi-table tbody tr:nth-child(even) td{background:#fbfdff}
    .c360 .kpi-table td.value{font-weight:800}
    .c360 .charts-label{font-size:11px;font-weight:800;color:#47617f;margin:12px 0 6px}
    .c360 .chart-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
    .c360 .chart-card{border:1px solid var(--line);border-radius:8px;background:#fff;padding:8px 9px;box-shadow:0 2px 9px rgba(26,58,97,.025)}
    .c360 .chart-title{font-size:10.5px;font-weight:800;margin-bottom:1px}
    .c360 .chart-sub{font-size:8.5px;color:var(--muted);margin-bottom:3px}
    .c360 .linebox{height:96px}
    .c360 .linebox svg{width:100%;height:100%;overflow:visible}
    .c360 .month-labels{display:flex;justify-content:space-between;padding:0 2px;margin-top:2px}
    .c360 .month-labels span{font-size:7.5px;color:#71849a}
    .c360 .donut-wrap{height:132px;display:flex;align-items:center;justify-content:center;gap:16px}
    .c360 .donut{width:104px;height:104px;border-radius:50%;position:relative;flex-shrink:0;background:conic-gradient(var(--donutColor,var(--blue)) calc(var(--p)*1%),#e9f0f8 0)}
    .c360 .donut::after{content:"";position:absolute;inset:17px;background:#fff;border-radius:50%}
    .c360 .donut-center{position:absolute;inset:0;display:grid;place-items:center;z-index:2;font-weight:800;font-size:15px;color:#21496f}
    .c360 .donut-legend{font-size:10px;color:#5f7590;line-height:1.9}
    .c360 .legend-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;background:var(--donutColor,var(--blue))}
    .c360 .legend-dot.light{background:#e9f0f8;border:1px solid #d5e2f2}
    .c360 .hbar-wrap{display:flex;flex-direction:column;gap:7px}
    .c360 .hbar-row{display:flex;align-items:center;gap:8px}
    .c360 .hbar-label{width:76px;flex-shrink:0;font-size:10.5px;color:#47617f;font-weight:700}
    .c360 .hbar-track{flex:1;height:16px;border-radius:4px;background:#eef3f9;overflow:hidden}
    .c360 .hbar-fill{height:100%;border-radius:4px;background:var(--blue)}
    .c360 .hbar-val{width:88px;flex-shrink:0;text-align:right;font-size:10.5px;font-weight:800;color:#21496f}
    .c360 .sbar-wrap{display:flex;align-items:flex-end;gap:10px;height:150px;padding-top:6px;overflow-x:auto}
    .c360 .sbar-col{display:flex;flex-direction:column;align-items:center;gap:6px;flex:1;min-width:28px}
    .c360 .sbar-bar{width:100%;max-width:34px;height:130px;border-radius:4px 4px 2px 2px;overflow:hidden;display:flex;flex-direction:column-reverse;background:#eef3f9}
    .c360 .sbar-seg{width:100%}
    .c360 .sbar-label{font-size:9.5px;color:#8a9bb2}
    .c360 .sbar-label.current{font-weight:800;color:#21496f}
    .c360 .sbar-legend{display:flex;flex-wrap:wrap;gap:5px 14px;margin-top:14px;font-size:10px;color:#5f7590}
    .c360 .owner-tabs{display:flex;flex-wrap:wrap;gap:6px}
    .c360 .owner-tab{padding:6px 14px;border-radius:8px;font-size:11.5px;font-weight:700;border:1px solid var(--line2);background:#fff;color:#47617f;cursor:pointer}
    .c360 .owner-tab.active{background:var(--blue);border-color:var(--blue);color:#fff}
    @media(max-width:900px){.c360 .chart-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:800px){.c360 .group-grid{grid-template-columns:1fr}.c360 .group.full{grid-column:auto}.c360 .c-rows{grid-template-columns:1fr}.c360 .chart-grid{grid-template-columns:1fr}}
    .c360 .crumbs-bar{display:flex;align-items:center;justify-content:space-between;padding:9px 2px 12px}
    .c360 .crumbs{font-size:11.5px;color:var(--muted)}
    .c360 .crumbs b{color:var(--text);font-weight:800}
    .c360 .btn-outline{border:1px solid var(--line);background:#fff;border-radius:8px;padding:7px 12px;color:#426080;font-weight:700;font-size:12px;text-decoration:none;box-shadow:var(--shadow)}
    .c360 .btn-outline:hover{background:#f7faff}
</style>
