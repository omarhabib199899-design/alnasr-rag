{{-- Change History — our own tab (kept), restyled to their card design. --}}
<section class="c3-card">
    <header class="c3-card-h"><i data-lucide="history" class="ico-18"></i><h2>Change History</h2>
        <span class="meta">{{ number_format($changeLogs->total()) }} changes</span></header>
    <div class="c3-card-b">
        <div class="c3-scroll">
            <table class="c3-tbl">
                <thead><tr><th class="start">Date</th><th class="start">Field</th><th class="start">Old Value</th><th class="start">New Value</th></tr></thead>
                <tbody>
                    @forelse ($changeLogs as $log)
                        <tr>
                            <td class="start"><bdi dir="ltr">{{ $log->changed_at->format('d-m-Y H:i') }}</bdi></td>
                            <td class="start">{{ str($log->field)->replace('_', ' ')->title() }}</td>
                            <td class="start" style="white-space: normal" dir="auto">{{ $log->old_value ?? '—' }}</td>
                            <td class="start" style="white-space: normal" dir="auto">{{ $log->new_value ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr class="nohover"><td colspan="4" class="start" style="padding: 24px 12px; color: var(--c360-label)">No tracked changes for this customer yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="c3-note">Every field change detected on this customer since the change log started tracking (2026-09-22) — earlier history can't be reconstructed.</p>
        <div style="margin-top: 12px">{{ $changeLogs->appends(['tab' => 'history'])->links() }}</div>
    </div>
</section>
