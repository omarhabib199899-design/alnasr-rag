{{-- Lovable MultiSelectFilter.tsx. Usage: @include('lovable.multi-select', ['label' => 'Status', 'opts' => 'statOpts', 'sel' => 'statSel'])
     — opts / sel are names of the parent Alpine component's {value, count}[] getter and string[] property. --}}
<div class="lv-ms" x-data="{ open: false, q: '' }" @click.outside="open = false">
    <span>{{ $label }}</span>
    <button type="button" class="lv-ms-btn" :class="{{ $sel }}.length && 'on'" @click="open = ! open">
        <span dir="auto" x-text="{{ $sel }}.length === 0 ? 'All' : ({{ $sel }}.length === 1 ? {{ $sel }}[0] : `${ {{ $sel }}.length } selected`)"></span><span>▾</span>
    </button>
    <div class="lv-ms-pop" x-show="open" x-cloak>
        <div style="border-bottom: 1px solid var(--border); padding: 6px"><input class="lv-in" x-model="q" placeholder="Search…" x-effect="open && $nextTick(() => $el.focus())"></div>
        <div class="lv-ms-list">
            <template x-if="! {{ $opts }}.filter((o) => ! q.trim() || o.value.toLowerCase().includes(q.trim().toLowerCase())).length">
                <p style="margin: 0; padding: 4px 8px; font-size: 12px">No options</p>
            </template>
            <template x-for="o in {{ $opts }}.filter((o) => ! q.trim() || o.value.toLowerCase().includes(q.trim().toLowerCase())).slice(0, 500)" :key="o.value">
                <label class="lv-ms-row">
                    <input type="checkbox" :checked="{{ $sel }}.includes(o.value)"
                           @change="{{ $sel }} = {{ $sel }}.includes(o.value) ? {{ $sel }}.filter((x) => x !== o.value) : [...{{ $sel }}, o.value]; page = 0">
                    <span class="t" dir="auto" x-text="o.value"></span><span class="c" dir="ltr" x-text="o.count"></span>
                </label>
            </template>
        </div>
        <div x-show="{{ $sel }}.length" style="border-top: 1px solid var(--border); padding: 6px; text-align: end">
            <button type="button" class="lv-ms-clear" @click="{{ $sel }} = []; page = 0">Clear</button>
        </div>
    </div>
</div>
