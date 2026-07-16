@php
    $sections = [
        'siap' => [
            'title' => 'Siap',
            'class' => 'border-green-200 bg-green-50 text-green-800',
            'badge' => 'bg-green-100 text-green-700 ring-green-200',
        ],
        'perlu_diperiksa' => [
            'title' => 'Perlu diperiksa',
            'class' => 'border-amber-200 bg-amber-50 text-amber-800',
            'badge' => 'bg-amber-100 text-amber-700 ring-amber-200',
        ],
        'informasi' => [
            'title' => 'Informasi',
            'class' => 'border-blue-200 bg-blue-50 text-blue-800',
            'badge' => 'bg-blue-100 text-blue-700 ring-blue-200',
        ],
    ];
@endphp

<div class="space-y-3">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        <div class="rounded-lg border border-gray-200 bg-white px-3 py-2">
            <p class="text-xs text-gray-500">Kelas</p>
            <p class="font-semibold text-gray-800">{{ $readiness['counts']['kelas'] ?? 0 }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white px-3 py-2">
            <p class="text-xs text-gray-500">Mata Pelajaran</p>
            <p class="font-semibold text-gray-800">{{ $readiness['counts']['mata_pelajaran'] ?? 0 }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white px-3 py-2">
            <p class="text-xs text-gray-500">LM/TP Belum Lengkap</p>
            <p class="font-semibold text-gray-800">{{ $readiness['counts']['mata_pelajaran_lm_tp_belum_lengkap'] ?? 0 }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white px-3 py-2">
            <p class="text-xs text-gray-500">Template Aktif</p>
            <p class="font-semibold text-gray-800">{{ $readiness['counts']['template_rapor_aktif'] ?? 0 }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
        @foreach($sections as $key => $section)
            <div class="rounded-lg border {{ $section['class'] }} p-3">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $section['badge'] }}">
                    {{ $section['title'] }}
                </span>
                <div class="mt-2 space-y-2 text-sm">
                    @forelse($readiness[$key] ?? [] as $item)
                        <div>
                            <p class="font-medium">{{ $item['label'] }}</p>
                            <p class="text-xs opacity-90">{{ $item['value'] }}</p>
                        </div>
                    @empty
                        <p class="text-xs opacity-80">Tidak ada catatan.</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>
