@props(['name' => 'search', 'label' => 'Search', 'placeholder' => 'Search', 'value' => null])

{{--
    A search box with its icon, for the toolbar above a list. The label is
    for screen readers; the placeholder says what can be searched.

        <x-search-input placeholder="Asset tag or hostname" />
--}}

<label {{ $attributes->merge(['class' => 'relative block min-w-[14rem] flex-1 sm:max-w-xs']) }}>
    <span class="sr-only">{{ $label }}</span>
    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-fg-subtle" />
    <input type="search" name="{{ $name }}" value="{{ $value ?? request($name) }}" placeholder="{{ $placeholder }}"
           class="h-9 w-full pl-9 text-sm">
</label>
