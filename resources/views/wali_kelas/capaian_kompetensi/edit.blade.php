@extends('layouts.wali_kelas.app')

@section('content')
@php
    $tertinggiDefault = $phraseDefaults->get('tertinggi');
    $terendahDefault = $phraseDefaults->get('terendah');
    $tertinggiFallback = 'menunjukkan pemahaman dalam';
    $terendahFallback = 'berkembang dalam';
    $tertinggiInitialMode = $tertinggiDefault?->mode === 'custom' ? 'custom' : 'preset';
    $terendahInitialMode = $terendahDefault?->mode === 'custom' ? 'custom' : 'preset';
    $tertinggiInitialPhrase = $tertinggiDefault?->phrase ?: $tertinggiFallback;
    $terendahInitialPhrase = $terendahDefault?->phrase ?: $terendahFallback;
    $tertinggiChoice = old(
        'tertinggi_choice',
        $tertinggiDefault ? ($tertinggiDefault->mode === 'custom' ? '__custom__' : $tertinggiDefault->phrase) : $tertinggiFallback
    );
    $terendahChoice = old(
        'terendah_choice',
        $terendahDefault ? ($terendahDefault->mode === 'custom' ? '__custom__' : $terendahDefault->phrase) : $terendahFallback
    );
    $tertinggiCustomPhrase = old(
        'tertinggi_custom_phrase',
        $tertinggiDefault?->mode === 'custom' ? $tertinggiDefault->phrase : ''
    );
    $terendahCustomPhrase = old(
        'terendah_custom_phrase',
        $terendahDefault?->mode === 'custom' ? $terendahDefault->phrase : ''
    );
@endphp

<div class="mt-14 bg-white p-4"
     x-data="capaianUnifiedEditor({
         defaults: {
             tertinggi: {
                 initialMode: @js($tertinggiInitialMode),
                 initialPhrase: @js($tertinggiInitialPhrase),
                 choice: @js($tertinggiChoice),
                 custom: @js($tertinggiCustomPhrase),
                 fallback: @js($tertinggiFallback)
             },
             terendah: {
                 initialMode: @js($terendahInitialMode),
                 initialPhrase: @js($terendahInitialPhrase),
                 choice: @js($terendahChoice),
                 custom: @js($terendahCustomPhrase),
                 fallback: @js($terendahFallback)
             }
         }
     })"
     x-init="init()">
    <form method="POST"
          action="{{ route('wali_kelas.capaian_kompetensi.save_all', $mataPelajaran->id) }}"
          @submit="submitting = true">
        @csrf
        @method('PUT')
        <input type="hidden" name="context[tahun_ajaran_id]" value="{{ $tahunAjaranId }}">
        <input type="hidden" name="context[semester]" value="{{ $semester }}">
        <input type="hidden" name="context[kelas_id]" value="{{ $kelas->id }}">

        @foreach(['tertinggi', 'terendah'] as $type)
            <template x-if="defaultDirty('{{ $type }}')">
                <div>
                    <input type="hidden" name="defaults[{{ $type }}][changed]" value="1">
                    <input type="hidden" name="defaults[{{ $type }}][mode]" :value="defaultMode('{{ $type }}')">
                    <input type="hidden" name="defaults[{{ $type }}][phrase]" :value="defaultPhraseForSave('{{ $type }}')">
                </div>
            </template>
        @endforeach

        <div class="sticky top-16 z-20 mb-6 rounded-lg border border-gray-200 bg-white/95 p-3 shadow-sm backdrop-blur">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-green-700">Kelola Capaian Kompetensi</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ $mataPelajaran->nama_pelajaran }} - {{ $kelas->label_kelas }}
                    </p>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <a href="{{ route('wali_kelas.capaian_kompetensi.index') }}"
                       class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Kembali
                    </a>
                    <button type="submit"
                            disabled
                            :disabled="dirtyCount === 0 || submitting"
                            class="inline-flex items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600">
                        <span x-show="!submitting">Simpan Semua Perubahan</span>
                        <span x-show="submitting" x-cloak>Menyimpan...</span>
                        <span x-show="dirtyCount > 0 && !submitting" x-cloak class="ml-1">
                            (<span x-text="dirtyCount"></span>)
                        </span>
                    </button>
                </div>
            </div>

            <div x-show="dirtyCount > 0" x-cloak class="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700">
                Ada <span class="font-semibold" x-text="dirtyCount"></span> perubahan belum disimpan.
            </div>
        </div>

        @if(session('success'))
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <p class="font-semibold">Periksa kembali isian capaian.</p>
                <ul class="mt-1 list-inside list-disc">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Pengaturan Kalimat Awal Capaian</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Kalimat ini menjadi pembuka otomatis untuk siswa yang menggunakan pengaturan default.
                    </p>
                </div>

                <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
                    <div><span class="font-medium text-gray-800">Kelas:</span> {{ $kelas->label_kelas }}</div>
                    <div><span class="font-medium text-gray-800">Mapel:</span> {{ $mataPelajaran->nama_pelajaran }}</div>
                    <div><span class="font-medium text-gray-800">Tahun:</span> {{ $tahunAjaran?->tahun_ajaran ?? '-' }}</div>
                    <div><span class="font-medium text-gray-800">Semester:</span> {{ $semester === 1 ? 'Ganjil' : 'Genap' }}</div>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div>
                    <label for="tertinggi_choice" class="block text-sm font-medium text-gray-700">
                        Default capaian tertinggi
                    </label>
                    <select id="tertinggi_choice"
                            x-model="defaults.tertinggi.choice"
                            @change="defaultUpdated('tertinggi')"
                            class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500">
                        @foreach($prefixPresets['tertinggi'] as $preset)
                            <option value="{{ $preset }}">{{ \Illuminate\Support\Str::ucfirst($preset) }}</option>
                        @endforeach
                        <option value="__custom__">Tulis kalimat sendiri</option>
                    </select>
                    <input x-show="defaults.tertinggi.choice === '__custom__'"
                           x-cloak
                           type="text"
                           x-model="defaults.tertinggi.custom"
                           @input="defaultUpdated('tertinggi')"
                           maxlength="150"
                           placeholder="Contoh: menunjukkan penguasaan mendalam dalam"
                           class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500">
                    <p class="mt-2 text-xs text-gray-500">
                        Preview: Ahmad <span x-text="defaultPhraseForPreview('tertinggi')"></span> mengenal lambang dan nama bilangan.
                    </p>
                </div>

                <div>
                    <label for="terendah_choice" class="block text-sm font-medium text-gray-700">
                        Default capaian terendah
                    </label>
                    <select id="terendah_choice"
                            x-model="defaults.terendah.choice"
                            @change="defaultUpdated('terendah')"
                            class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500">
                        @foreach($prefixPresets['terendah'] as $preset)
                            <option value="{{ $preset }}">{{ \Illuminate\Support\Str::ucfirst($preset) }}</option>
                        @endforeach
                        <option value="__custom__">Tulis kalimat sendiri</option>
                    </select>
                    <input x-show="defaults.terendah.choice === '__custom__'"
                           x-cloak
                           type="text"
                           x-model="defaults.terendah.custom"
                           @input="defaultUpdated('terendah')"
                           maxlength="150"
                           placeholder="Contoh: membutuhkan penguatan dalam"
                           class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500">
                    <p class="mt-2 text-xs text-gray-500">
                        Preview: Ahmad <span x-text="defaultPhraseForPreview('terendah')"></span> mengenal lambang dan nama bilangan.
                    </p>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Capaian Per Siswa</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Edit langsung pada kolom capaian seperti spreadsheet sederhana.
                    </p>
                    <p class="mt-2 max-w-3xl text-xs text-gray-500">
                        Kalimat yang diedit akan menjadi khusus untuk siswa tersebut dan tidak lagi mengikuti pengaturan default sampai dikembalikan ke default.
                    </p>
                </div>
                <div class="text-sm text-gray-600">
                    <strong>Total Siswa:</strong> {{ $siswaList->count() }}
                </div>
            </div>

            <div class="p-3 lg:p-0">
                <table class="w-full border-separate border-spacing-y-3 text-left text-sm text-gray-600 lg:border-collapse lg:border-spacing-y-0">
                    <thead class="hidden bg-gray-50 text-xs uppercase text-gray-700 lg:table-header-group">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Nama Siswa</th>
                            <th class="px-4 py-3">Nilai</th>
                            <th class="w-[34%] px-4 py-3">Capaian Tertinggi</th>
                            <th class="w-[34%] px-4 py-3">Capaian Terendah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($siswaList as $index => $siswa)
                            @php
                                $existingRow = $existingCapaian->get($siswa->id);
                                $row = $studentCapaianRows[$siswa->id];
                            @endphp
                            <tr class="mb-4 block rounded-lg border border-gray-200 bg-white align-top shadow-sm lg:mb-0 lg:table-row lg:rounded-none lg:border-0 lg:border-b lg:shadow-none lg:hover:bg-gray-50">
                                <td class="block px-4 py-3 font-medium text-gray-900 lg:table-cell lg:py-4">
                                    <span class="mb-1 block text-xs font-semibold uppercase text-gray-500 lg:hidden">No</span>
                                    {{ $index + 1 }}
                                </td>
                                <td class="block px-4 py-3 lg:table-cell lg:py-4">
                                    <span class="mb-1 block text-xs font-semibold uppercase text-gray-500 lg:hidden">Nama Siswa</span>
                                    <div class="font-medium text-gray-900">{{ $siswa->nama }}</div>
                                    <div class="mt-1 text-xs text-gray-500">NIS: {{ $siswa->nis }}</div>
                                </td>
                                <td class="block px-4 py-3 lg:table-cell lg:py-4">
                                    <span class="mb-1 block text-xs font-semibold uppercase text-gray-500 lg:hidden">Nilai</span>
                                    @if(!is_null($row['nilai_akhir']))
                                        <div class="font-semibold text-gray-900">{{ number_format($row['nilai_akhir'], 0) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">Nilai akhir rapor</div>
                                    @else
                                        <span class="text-xs text-gray-400">Belum tersedia</span>
                                    @endif
                                </td>

                                @foreach(['tertinggi' => 'Capaian Tertinggi', 'terendah' => 'Capaian Terendah'] as $type => $label)
                                    @php
                                        $status = $row['status'][$type];
                                        $fullText = $type === 'tertinggi'
                                            ? $existingRow?->custom_capaian_tertinggi
                                            : $existingRow?->custom_capaian_terendah;
                                        $fieldKey = 's'.$siswa->id.'_'.$type;
                                    @endphp
                                    <td class="block px-4 py-3 lg:table-cell lg:py-4">
                                        <span class="mb-1 block text-xs font-semibold uppercase text-gray-500 lg:hidden">{{ $label }}</span>
                                        <div class="rounded-lg border border-gray-200 bg-white p-3 transition"
                                             x-data="capaianInlineField({
                                                 key: @js($fieldKey),
                                                 type: @js($type),
                                                 initial: @js($row['resolved'][$type]),
                                                 studentName: @js($siswa->nama),
                                                 lmText: @js($row['lm'][$type]),
                                                 followsDefault: @js($row['uses_default'][$type]),
                                                 defaultPhrase: defaultPhraseForPreview(@js($type)),
                                                 needsConfirm: @js(filled($fullText))
                                             })"
                                             :class="{ 'border-green-200 bg-green-50/60': dirty || reset || defaultPreviewDirty }">
                                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span x-show="!dirty && !reset && !defaultPreviewDirty"
                                                          class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $status['class'] }}">
                                                        {{ $status['label'] }}
                                                    </span>
                                                    <span x-show="dirty || defaultPreviewDirty"
                                                          x-cloak
                                                          class="inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-200">
                                                        Belum disimpan
                                                    </span>
                                                    <span x-show="reset"
                                                          x-cloak
                                                          class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200">
                                                        Akan kembali ke default
                                                    </span>
                                                </div>
                                                <button type="button"
                                                        class="text-xs font-medium text-green-700 hover:text-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                                                        @click="resetToDefault()">
                                                    Gunakan default
                                                </button>
                                            </div>

                                            <textarea rows="3"
                                                      x-model="value"
                                                      x-init="$nextTick(() => autogrow($el))"
                                                      @input="update(); autogrow($event.target)"
                                                      class="min-h-[5.5rem] w-full resize-y rounded-lg border border-gray-300 px-3 py-2 text-sm leading-6 text-gray-800 focus:border-green-500 focus:ring-green-500"
                                                      aria-label="{{ $label }} {{ $siswa->nama }}"></textarea>
                                            <p x-show="reset"
                                               x-cloak
                                               class="mt-2 text-xs text-gray-500">
                                                Setelah disimpan, capaian ini akan kembali mengikuti pengaturan default.
                                            </p>

                                            <template x-if="dirty">
                                                <div>
                                                    <input type="hidden" :name="'student_changes[' + key + '][siswa_id]'" value="{{ $siswa->id }}">
                                                    <input type="hidden" :name="'student_changes[' + key + '][' + type + '][action]'" value="custom_full">
                                                    <input type="hidden" :name="'student_changes[' + key + '][' + type + '][text]'" :value="value">
                                                </div>
                                            </template>
                                            <template x-if="reset">
                                                <div>
                                                    <input type="hidden" :name="'student_changes[' + key + '][siswa_id]'" value="{{ $siswa->id }}">
                                                    <input type="hidden" :name="'student_changes[' + key + '][' + type + '][action]'" value="reset_default">
                                                </div>
                                            </template>
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </form>
</div>

@push('scripts')
<script>
function capaianUnifiedEditor(config) {
    return {
        defaults: config.defaults,
        dirtyFields: {},
        submitting: false,
        init() {
            window.addEventListener('capaian-dirty', (event) => {
                this.setDirty(event.detail.key, event.detail.dirty);
            });

            window.addEventListener('beforeunload', (event) => {
                if (this.dirtyCount === 0 || this.submitting) {
                    return;
                }

                event.preventDefault();
                event.returnValue = '';
            });
        },
        get dirtyCount() {
            return Object.keys(this.dirtyFields).length + this.defaultDirtyCount;
        },
        get defaultDirtyCount() {
            return ['tertinggi', 'terendah'].filter((type) => this.defaultDirty(type)).length;
        },
        setDirty(key, isDirty) {
            if (isDirty) {
                this.dirtyFields = { ...this.dirtyFields, [key]: true };
                return;
            }

            const next = { ...this.dirtyFields };
            delete next[key];
            this.dirtyFields = next;
        },
        defaultMode(type) {
            return this.defaults[type].choice === '__custom__' ? 'custom' : 'preset';
        },
        defaultPhraseForSave(type) {
            const current = this.defaults[type];
            const phrase = this.defaultMode(type) === 'custom' ? current.custom : current.choice;

            return String(phrase || '').trim();
        },
        defaultPhraseForPreview(type) {
            return this.defaultPhraseForSave(type) || this.defaults[type].fallback;
        },
        defaultDirty(type) {
            const current = this.defaults[type];

            return this.defaultMode(type) !== current.initialMode
                || this.defaultPhraseForSave(type) !== current.initialPhrase;
        },
        defaultUpdated(type) {
            window.dispatchEvent(new CustomEvent('capaian-default-updated', {
                detail: {
                    type,
                    phrase: this.defaultPhraseForPreview(type),
                    changed: this.defaultDirty(type),
                },
            }));
        },
    };
}

function capaianInlineField(config) {
    return {
        key: config.key,
        type: config.type,
        initial: config.initial || '',
        value: config.initial || '',
        studentName: config.studentName || '',
        lmText: config.lmText || '',
        followsDefault: Boolean(config.followsDefault),
        defaultPhrase: config.defaultPhrase || '',
        needsConfirm: Boolean(config.needsConfirm),
        dirty: false,
        reset: false,
        defaultPreviewDirty: false,
        init() {
            window.addEventListener('capaian-default-updated', (event) => {
                if (event.detail.type !== this.type || this.dirty) {
                    return;
                }

                this.defaultPhrase = event.detail.phrase || this.defaultPhrase;

                if (!this.followsDefault && !this.reset) {
                    return;
                }

                this.value = this.composeDefaultText();
                this.defaultPreviewDirty = this.followsDefault && Boolean(event.detail.changed);
                this.$nextTick(() => this.autogrow(this.$el.querySelector('textarea')));
            });
        },
        update() {
            this.reset = false;
            this.defaultPreviewDirty = false;
            this.dirty = this.value !== this.initial;
            this.notify();
        },
        resetToDefault() {
            if (this.needsConfirm && !window.confirm('Deskripsi custom yang ada akan dihapus dan capaian kembali mengikuti default. Lanjutkan?')) {
                return;
            }

            this.value = this.composeDefaultText();
            this.dirty = false;
            this.reset = true;
            this.defaultPreviewDirty = false;
            this.notify();
            this.$nextTick(() => this.autogrow(this.$el.querySelector('textarea')));
        },
        composeDefaultText() {
            const phrase = String(this.defaultPhrase || '').trim();
            const lmText = String(this.lmText || '').trim().replace(/[.\s]+$/u, '');

            if (!phrase || !lmText) {
                return this.initial;
            }

            return `${this.studentName} ${phrase} ${lmText}.`.replace(/\s+/gu, ' ').trim();
        },
        notify() {
            window.dispatchEvent(new CustomEvent('capaian-dirty', {
                detail: {
                    key: this.key,
                    dirty: this.dirty || this.reset,
                },
            }));
        },
        autogrow(element) {
            if (!element) {
                return;
            }

            element.style.height = 'auto';
            element.style.height = Math.max(element.scrollHeight, 88) + 'px';
        },
    };
}
</script>
@endpush
@endsection
