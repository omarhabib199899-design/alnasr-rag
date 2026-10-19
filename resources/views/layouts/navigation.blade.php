{{--
    Sidebar — mirrors the reference Lovable portal's sidebar exactly (same
    group order, item labels, colors, sizes and collapse behavior: groups
    start closed and auto-open the one holding the current page). Scoped
    plain CSS rather than Tailwind classes so no asset rebuild is needed.
    Pages the reference has but this app doesn't yet are shown as dimmed
    "Soon" entries, never dead links. This app's own admin pages (which the
    reference has no equivalent for) live in the trailing Administration group.
--}}
<style>
    .app-sidebar{
        --sb-bg:oklch(25.5% .035 258);--sb-fg:oklch(90% .012 250);--sb-muted:oklch(68% .02 255);
        --sb-primary:oklch(37% .08 244.5);--sb-primary-fg:oklch(98% .003 247.9);
        --sb-accent:oklch(31% .035 258);--sb-accent-fg:oklch(96% .005 247.9);--sb-border:oklch(33% .03 258);
        background:var(--sb-bg);color:var(--sb-fg);width:240px;flex-shrink:0;height:100vh;position:sticky;top:0;
        display:flex;flex-direction:column;z-index:20;font-family:"IBM Plex Sans","IBM Plex Sans Arabic",ui-sans-serif,system-ui,sans-serif
    }
    .app-sidebar a{text-decoration:none}
    .app-sidebar .sb-brand{height:56px;flex-shrink:0;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--sb-border);padding:0 16px;color:inherit}
    .app-sidebar .sb-mark{width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:2px;background:var(--sb-primary);color:var(--sb-primary-fg);font-size:11px;font-weight:600;letter-spacing:-.025em;flex-shrink:0}
    .app-sidebar .sb-name{font-size:13px;font-weight:600;line-height:1.25}
    .app-sidebar .sb-tagline{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--sb-muted);line-height:1.25}
    .app-sidebar nav{flex:1;overflow-y:auto;padding:12px 8px}
    .app-sidebar .sb-block{margin-bottom:2px}
    .app-sidebar .sb-top{display:flex;width:100%;align-items:center;justify-content:space-between;border-radius:2px;padding:8px 12px;font-size:13px;font-weight:500;color:var(--sb-fg);background:none;border:0;cursor:pointer;text-align:start;transition:background-color .15s,color .15s}
    .app-sidebar .sb-top:hover{background:var(--sb-accent);color:var(--sb-accent-fg)}
    .app-sidebar .sb-top.has-active{color:var(--sb-accent-fg)}
    .app-sidebar .sb-top.has-active:hover{background:none}
    .app-sidebar .sb-top.active{background:var(--sb-primary);color:var(--sb-primary-fg)}
    .app-sidebar .sb-chevron{width:14px;height:14px;color:var(--sb-muted);transition:transform .15s;flex-shrink:0}
    .app-sidebar .sb-chevron.open{transform:rotate(180deg)}
    .app-sidebar .sb-sub{margin:2px 0 0 12px;border-left:1px solid var(--sb-border);padding-left:8px;list-style:none}
    .app-sidebar .sb-item{display:flex;align-items:center;justify-content:space-between;gap:6px;margin:1px 0;border-radius:2px;padding:6px 10px;font-size:12.5px;color:var(--sb-muted);transition:background-color .15s,color .15s}
    .app-sidebar a.sb-item:hover{background:var(--sb-accent);color:var(--sb-accent-fg)}
    .app-sidebar .sb-item.active{background:var(--sb-primary);color:var(--sb-primary-fg);font-weight:500}
    .app-sidebar .sb-soon{opacity:.55;cursor:default}
    .app-sidebar .sb-soon-badge{font-size:9px;text-transform:uppercase;letter-spacing:.06em;border:1px solid var(--sb-border);border-radius:2px;padding:0 4px;line-height:14px;flex-shrink:0}
    .app-sidebar .sb-footer{flex-shrink:0;border-top:1px solid var(--sb-border);padding:12px 16px}
    .app-sidebar .sb-confidential{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--sb-muted)}
    .app-sidebar .sb-user{display:flex;align-items:center;gap:8px;margin-top:10px}
    .app-sidebar .sb-avatar{width:26px;height:26px;border-radius:2px;background:var(--sb-accent);color:var(--sb-accent-fg);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0}
    .app-sidebar .sb-user-name{font-size:12px;font-weight:500;color:var(--sb-accent-fg);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .app-sidebar .sb-user-links{display:flex;gap:8px}
    .app-sidebar .sb-user-links a{font-size:10.5px;color:var(--sb-muted)}
    .app-sidebar .sb-user-links a:hover{color:var(--sb-accent-fg);text-decoration:underline}
    @media (max-width:768px){
        .app-sidebar{position:fixed;left:-250px;transition:left .2s;z-index:60}
        .app-sidebar.open{left:0}
    }
</style>

@php
    $user = Auth::user();
    $canReports = $user->hasPermission('nav.reports');
    $canCustomers = $user->hasPermission('nav.customers');
    $txType = request()->routeIs('reports.transactions') || request()->routeIs('reports.document') ? request()->route('type') : null;

    // Each entry: [label, url|null (null = "Soon"), isActive, isVisible]
    $item = fn (string $label, ?string $url = null, bool $active = false, bool $visible = true) => compact('label', 'url', 'active', 'visible');

    $sidebar = [
        ['kind' => 'group', 'label' => 'Sales Data', 'items' => [
            $item('Customers', route('customers.index'), request()->routeIs('customers.*'), $canCustomers),
            $item('Sales Reps', route('sales-reps.index'), request()->routeIs('sales-reps.*')),
            $item('Sales Owners', route('sales-owners.index'), request()->routeIs('sales-owners.*')),
            $item('Data Entry', route('data-entry.index'), request()->routeIs('data-entry.*')),
            $item('Management', route('management.index'), request()->routeIs('management.*')),
            $item('Sales KPIs', route('sales-kpis.index'), request()->routeIs('sales-kpis.*')),
            $item('Sales Orders', route('sales-orders.index'), request()->routeIs('sales-orders.*') || $txType === 'sales', $canReports),
            $item('Invoices', route('invoices.index'), request()->routeIs('invoices.*'), $canReports),
            $item('Payments Received', route('payments-received.index'), request()->routeIs('payments-received.*')),
            $item('Credit Notes', route('credit-notes.index'), request()->routeIs('credit-notes.*')),
            $item('Sales Operations', route('sales-operations.index'), request()->routeIs('sales-operations.*')),
        ], 'visible' => $canReports || $canCustomers],
        ['kind' => 'group', 'label' => 'Purchasing Data', 'items' => [
            $item('Vendors'),
            $item('Purchasing Managers'),
            $item('Purchasing KPIs'),
            $item('Items'),
            $item('Price Lists'),
            $item('Purchase Orders', route('reports.transactions', 'purchase-orders'), $txType === 'purchase-orders'),
            $item('Purchase Receives'),
            $item('Bills'),
            $item('Payments Made'),
            $item('Vendor Credits'),
        ], 'visible' => $canReports],
        ['kind' => 'link'] + $item('Inventory Data'),
        ['kind' => 'link'] + $item('Finance Data'),
        ['kind' => 'group', 'label' => 'Data Engine', 'items' => [
            $item('Sales Queries'),
            $item('Purchasing Queries'),
            $item('Inventory Queries'),
            $item('Finance Queries'),
        ], 'visible' => $canReports],
        ['kind' => 'group', 'label' => 'Reports', 'items' => [
            $item('Reports', route('reports.index'), request()->routeIs('reports.*') && ! in_array($txType, ['sales', 'purchase-orders'], true)),
                        $item('ZOHO Data', route('zoho-data.index'), request()->routeIs('zoho-data.*'), $user->hasPermission('nav.configuration')),
        ], 'visible' => $canReports],
        ['kind' => 'group', 'label' => 'Administration', 'items' => [
            $item('Dashboard', route('dashboard'), request()->routeIs('dashboard'), $user->hasPermission('nav.dashboard')),
            $item('Chat', route('chat.index'), request()->routeIs('chat.*'), $user->hasPermission('nav.chat')),
            $item('Configuration', route('configuration.index'), request()->routeIs('configuration.*'), $user->hasPermission('nav.configuration')),
            $item('Imports', route('imports.index'), request()->routeIs('imports.*'), $user->hasPermission('nav.imports')),
            $item('User Management', route('users.index'), request()->routeIs('users.*') || request()->routeIs('roles.*'), $user->hasPermission('nav.users')),
            $item('Activity Log', route('activity-log.index'), request()->routeIs('activity-log.*'), $user->hasPermission('nav.activity-log')),
        ], 'visible' => true],
    ];
@endphp

<aside class="app-sidebar" :class="{ 'open': sidebarOpen }">
    <a href="{{ route('dashboard') }}" class="sb-brand">
        <span class="sb-mark">AN</span>
        <span>
            <span class="sb-name" style="display:block">AL-NASR</span>
            <span class="sb-tagline" style="display:block">{{ __('Internal Portal') }}</span>
        </span>
    </a>

    <nav>
        @foreach ($sidebar as $entry)
            @if ($entry['kind'] === 'link')
                <div class="sb-block">
                    @if ($entry['url'])
                        <a href="{{ $entry['url'] }}" @class(['sb-top', 'active' => $entry['active']])>{{ __($entry['label']) }}</a>
                    @else
                        <span class="sb-top sb-soon">{{ __($entry['label']) }}<span class="sb-soon-badge">{{ __('Soon') }}</span></span>
                    @endif
                </div>
            @else
                @php
                    $visibleItems = collect($entry['items'])->where('visible', true);
                    $groupActive = $visibleItems->contains('active', true);
                @endphp
                @if ($entry['visible'] && $visibleItems->isNotEmpty())
                    <div class="sb-block" x-data="{ open: @js($groupActive) }">
                        <button type="button" @click="open = ! open" :aria-expanded="open" @class(['sb-top', 'has-active' => $groupActive])>
                            <span>{{ __($entry['label']) }}</span>
                            <svg class="sb-chevron" :class="{ 'open': open }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <ul class="sb-sub" x-show="open" x-cloak>
                            @foreach ($visibleItems as $sub)
                                <li>
                                    @if ($sub['url'])
                                        <a href="{{ $sub['url'] }}" @class(['sb-item', 'active' => $sub['active']])>{{ __($sub['label']) }}</a>
                                    @else
                                        <span class="sb-item sb-soon">{{ __($sub['label']) }}<span class="sb-soon-badge">{{ __('Soon') }}</span></span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        @endforeach
    </nav>

    <div class="sb-footer">
        <div class="sb-confidential">{{ __('Confidential · Internal use') }}</div>
        <div class="sb-user">
            <div class="sb-avatar">{{ strtoupper(substr($user->name, 0, 1)) }}</div>
            <div style="min-width:0">
                <div class="sb-user-name">{{ $user->name }}</div>
                <div class="sb-user-links">
                    <a href="{{ route('profile.edit') }}">{{ __('Profile') }}</a>
                    <form method="POST" action="{{ route('logout') }}" style="display:inline">
                        @csrf
                        <a href="{{ route('logout') }}" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Log Out') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</aside>
