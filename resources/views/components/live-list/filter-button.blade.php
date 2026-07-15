@props(['label' => 'Filter'])

<summary
    data-live-filter-button
    role="button"
    class="flex min-w-24 cursor-pointer list-none items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-green-100 [&::-webkit-details-marker]:hidden"
>
    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M3 4.75A.75.75 0 013.75 4h12.5a.75.75 0 01.53 1.28L12 10.06v4.19a.75.75 0 01-.32.61l-3 2.1A.75.75 0 017.5 16.35v-6.29L3.22 5.28A.75.75 0 013 4.75z" clip-rule="evenodd" />
    </svg>
    <span>{{ $label }}</span>
</summary>
