{{--
    Custom JS-driven searchable dropdown (Alpine.js), replacing the native
    HTML <datalist> approach — manager feedback (2026-09-29): the native
    datalist's suggestion order and dropdown alignment are entirely up to
    the browser, not something CSS/HTML can control. This gives full control
    over sort order, filtering, and positioning instead.

    Every layout-critical rule below is an INLINE style, not a Tailwind
    class — confirmed live (2026-09-29): this app's compiled CSS bundle only
    contains the Tailwind utility classes some OTHER view already used
    before this file existed (e.g. max-h-64/z-20/left-0/right-0 were never
    used anywhere else in the app), and there's no Node/npm on the
    production host to rebuild it (see the Hetzner server reference memory).
    A class with no matching compiled rule is silently a no-op — exactly
    what caused the dropdown's max-height to do nothing until this fix.

    Props: $name (form field name), $label, $value (current query value),
    $options (array of distinct real values, already sorted by the caller).
--}}
<div style="flex:1;min-width:150px" x-data="{
        open: false,
        search: @js($value ?? ''),
        options: @js(array_values($options->all())),
        get filtered() {
            const q = this.search.trim().toLowerCase();
            const list = q === '' ? this.options : this.options.filter(o => o.toLowerCase().includes(q));
            return list.slice(0, 50);
        },
        select(opt) {
            // Sets the actual DOM input value directly (via $refs) instead
            // of relying on x-model's reactive update finishing before
            // form.submit() runs — confirmed live (2026-09-29): submitting
            // in the same synchronous tick as the `search` assignment could
            // race ahead of Alpine's DOM sync, submitting the OLD value and
            // making a dropdown pick look like it did nothing to the table.
            // No double-quote characters allowed anywhere in this x-data
            // expression, including in comments: the attribute itself is
            // delimited by double quotes, so a literal double-quote character
            // here would close it early and truncate the JS. That exact
            // mistake is what broke every dropdown on this page before.
            this.search = opt;
            this.$refs.input.value = opt;
            this.open = false;
            this.$refs.input.closest('form').submit();
        },
    }" @click.outside="open = false">
    <label class="block text-xs font-medium text-gray-500 uppercase mb-1">{{ $label }}</label>
    <div style="position:relative">
        <input type="text" name="{{ $name }}" x-model="search" x-ref="input" autocomplete="off"
               @focus="open = true" @input="open = true" @keydown.escape="open = false"
               class="focus:border-gray-500 focus:ring-gray-500"
               style="width:100%;border-radius:6px;border:1px solid #d1d5db;font-size:14px;padding:8px 10px">
        <div x-show="open && filtered.length > 0" x-cloak
             style="position:absolute;left:0;right:0;top:100%;z-index:20;margin-top:4px;max-height:16rem;overflow-y:auto;border-radius:6px;border:1px solid #e5e7eb;background:#fff;box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1)">
            <template x-for="opt in filtered" :key="opt">
                <div @click="select(opt)" x-text="opt"
                     @mouseenter="$el.style.background='#f3f4f6'" @mouseleave="$el.style.background=''"
                     style="padding:8px 12px;font-size:14px;color:#374151;cursor:pointer"></div>
            </template>
        </div>
    </div>
</div>
