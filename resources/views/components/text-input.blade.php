@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 rounded-md shadow-sm']) }}
    style="--tw-ring-color: #2a78d6;" onfocus="this.style.borderColor='#2a78d6'" onblur="this.style.borderColor=''">
