<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-offset-2 transition ease-in-out duration-150']) }}
    style="background-color: #2a78d6; --tw-ring-color: #2a78d6;"
    onmouseover="this.style.backgroundColor='#256abf'" onmouseout="this.style.backgroundColor='#2a78d6'">
    {{ $slot }}
</button>
