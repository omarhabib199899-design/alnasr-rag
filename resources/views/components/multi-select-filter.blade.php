@props(['name', 'label', 'options' => [], 'selected' => []])

{{--
    Multi-select filter matching the reference portal's MultiSelectFilter:
    label, a button showing All / the one value / "N selected", and a
    dropdown with a search box, checkbox list with per-option counts, and
    Clear. Selections are real form inputs ({name}[]); the enclosing form is
    submitted when the dropdown closes with a changed selection.
--}}
<div class="msf" x-data="{
        open: false,
        q: '',
        sel: @js(array_values($selected)),
        initial: @js(array_values($selected)),
        get changed() { return JSON.stringify([...this.sel].sort()) !== JSON.stringify([...this.initial].sort()); },
        get buttonText() { return this.sel.length === 0 ? @js(__('All')) : (this.sel.length === 1 ? this.sel[0] : this.sel.length + ' ' + @js(__('selected'))); },
        matches(v) { const t = this.q.trim().toLowerCase(); return t === '' || v.toLowerCase().includes(t); },
        close() { if (! this.open) return; this.open = false; if (this.changed) this.$nextTick(() => this.$root.closest('form').submit()); },
    }" @click.outside="close()" @keydown.escape="close()">
    <span class="msf-label">{{ $label }}</span>
    <button type="button" class="msf-button" :class="{ 'msf-active': sel.length > 0 }" @click="open ? close() : (open = true)">
        <span class="msf-button-text" dir="auto" x-text="buttonText"></span>
        <span aria-hidden="true">▾</span>
    </button>
    <div class="msf-panel" x-show="open" x-cloak>
        <div class="msf-search"><input type="text" x-model="q" placeholder="{{ __('Search…') }}" class="msf-input" @keydown.enter.prevent></div>
        <div class="msf-list">
            @forelse ($options as $option)
                <label class="msf-option" x-show="matches(@js($option['value']))">
                    <input type="checkbox" name="{{ $name }}[]" value="{{ $option['value'] }}" x-model="sel">
                    <span class="msf-option-text" dir="auto">{{ $option['value'] }}</span>
                    <span class="msf-option-count" dir="ltr">{{ number_format($option['count']) }}</span>
                </label>
            @empty
                <p class="msf-empty">{{ __('No options') }}</p>
            @endforelse
        </div>
        <div class="msf-footer" x-show="sel.length > 0">
            <button type="button" class="msf-clear" @click="sel = []">{{ __('Clear') }}</button>
        </div>
    </div>
</div>

@once
    <style>
        .msf{position:relative;min-width:0;font-size:11px;color:oklch(53% .026 257.4)}
        .msf-label{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .msf-button{display:flex;height:28px;width:100%;align-items:center;justify-content:space-between;gap:4px;border-radius:2px;border:1px solid oklch(91.2% .01 255.5);background:oklch(97.5% .003 247.9);padding:0 8px;text-align:start;font-size:12px;color:oklch(53% .026 257.4);cursor:pointer}
        .msf-button.msf-active{border-color:oklch(47% .108 244.5);color:oklch(24% .028 258)}
        .msf-button-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .msf-panel{position:absolute;inset-inline-start:0;top:100%;z-index:30;margin-top:4px;width:256px;border-radius:2px;border:1px solid oklch(91.2% .01 255.5);background:#fff;color:oklch(24% .028 258);box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1)}
        .msf-search{border-bottom:1px solid oklch(91.2% .01 255.5);padding:6px}
        .msf .msf-input{height:28px;width:100%;border-radius:2px;border:1px solid oklch(91.2% .01 255.5);background:oklch(97.5% .003 247.9);padding:0 8px;font-size:12px;color:oklch(24% .028 258)}
        .msf-list{max-height:256px;overflow:auto;padding:4px 0}
        .msf-option{display:flex;cursor:pointer;align-items:center;gap:8px;padding:2px 8px;font-size:12px;color:oklch(24% .028 258)}
        .msf-option:hover{background:oklch(95.5% .007 247.9)}
        .msf-option input{border-radius:2px}
        .msf-option-text{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .msf-option-count{color:oklch(53% .026 257.4);font-variant-numeric:tabular-nums}
        .msf-empty{padding:4px 8px;font-size:12px}
        .msf-footer{border-top:1px solid oklch(91.2% .01 255.5);padding:6px;text-align:end}
        .msf-clear{font-size:12px;color:oklch(47% .108 244.5);background:none;border:0;cursor:pointer}
        .msf-clear:hover{text-decoration:underline}
    </style>
@endonce
