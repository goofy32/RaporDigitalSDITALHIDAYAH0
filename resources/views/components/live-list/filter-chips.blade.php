@props(['filters' => []])

@php
    $activeFilters = collect($filters)
        ->filter(fn ($filter) => filled(request($filter['key'] ?? '')))
        ->values();

    $filterUrl = function (string $key) {
        $query = request()->except([$key, 'page']);
        $queryString = http_build_query($query);

        return url()->current().($queryString ? '?'.$queryString : '');
    };
@endphp

@if($activeFilters->isNotEmpty())
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm" data-live-filter-chips>
        <span class="text-gray-500">Filter aktif:</span>
        @foreach($activeFilters as $filter)
            @php
                $key = $filter['key'];
                $rawValue = request($key);
                $valueLabel = $filter['values'][$rawValue] ?? $rawValue;
            @endphp
            <a href="{{ $filterUrl($key) }}"
               data-live-reset
               class="inline-flex items-center gap-1 rounded-full bg-green-50 px-3 py-1 font-medium text-green-700 hover:bg-green-100">
                <span>{{ $filter['label'] }}: {{ $valueLabel }}</span>
                <span aria-hidden="true">&times;</span>
            </a>
        @endforeach
    </div>
@endif
