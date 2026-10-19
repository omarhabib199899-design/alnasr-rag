@if (empty($table))
    <div class="placeholder-card">No data</div>
@else
    <div class="kpi-label" style="font-size:11px;font-weight:800;color:#47617f;margin:5px 0">KPIs</div>
    <div style="overflow-x:auto">
        <table class="kpi-table" style="min-width:760px">
            <thead>
                <tr>
                    <th>KPI</th>
                    @foreach (array_keys($table) as $period)
                        <th>{{ $period }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($kpis as $kpi)
                    <tr>
                        <td>{{ $kpi }}</td>
                        @foreach ($table as $period => $values)
                            <td class="{{ in_array($period, ['YTD', 'MTD', 'Today'], true) ? 'value' : '' }}">
                                @if (($values[$kpi] ?? '—') === 'Coming Soon')
                                    <span class="badge soon">Coming Soon</span>
                                @else
                                    {{ $values[$kpi] ?? '—' }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
