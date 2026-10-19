@php
    $format = $format ?? 'currency';
    $valueLabel = $valueLabel ?? 'Value';
@endphp
<table class="viz-table">
    <thead>
        <tr>
            <th>Category</th>
            <th class="num">{{ $valueLabel }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ $format === 'currency' ? ($currencySymbol ?? 'SAR').' '.number_format($row['value'], 2) : number_format($row['value']) }}</td>
            </tr>
        @empty
            <tr><td colspan="2" class="text-center" style="color: var(--text-muted)">No data.</td></tr>
        @endforelse
    </tbody>
</table>
