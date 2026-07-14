@php
    $fieldPrefix = $fieldPrefix ?? null;
    $checkboxName = $fieldPrefix ? "{$fieldPrefix}[copy_lm_tp]" : 'copy_lm_tp';
    $sourceName = $fieldPrefix ? "{$fieldPrefix}[copy_lm_tp_source_id]" : 'copy_lm_tp_source_id';
    $checkboxId = $checkboxId ?? 'copy_lm_tp';
    $sourceId = $sourceId ?? 'copy_lm_tp_source_id';
    $oldChecked = old($fieldPrefix ? "{$fieldPrefix}.copy_lm_tp" : 'copy_lm_tp');
    $oldSourceId = old($fieldPrefix ? "{$fieldPrefix}.copy_lm_tp_source_id" : 'copy_lm_tp_source_id');
@endphp

<div class="lm-tp-copy-option mt-4 hidden rounded-lg border border-blue-100 bg-blue-50 p-3 text-sm"
     data-lm-tp-copy-option
     data-initial-checked="{{ $oldChecked ? 'true' : 'false' }}"
     data-initial-source-id="{{ $oldSourceId }}">
    <div class="flex items-start gap-2">
        <input id="{{ $checkboxId }}"
               type="checkbox"
               name="{{ $checkboxName }}"
               value="1"
               disabled
               class="mt-0.5 h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500"
               data-lm-tp-copy-checkbox
               @checked($oldChecked)>
        <div class="min-w-0 flex-1">
            <label for="{{ $checkboxId }}" class="font-medium text-gray-900">
                Salin LM dan TP dari mata pelajaran yang sama di kelas paralel
            </label>
            <p class="mt-1 text-xs text-gray-600">
                Gunakan opsi ini jika mata pelajaran dan tingkat kelas sama, misalnya Matematika Kelas 1 Ubay ke Matematika Kelas 1 Zaid.
            </p>
            <p class="mt-1 text-xs text-gray-600">
                Yang disalin hanya Lingkup Materi dan Tujuan Pembelajaran. Nilai, siswa, rapor, absensi, dan catatan tidak ikut disalin.
            </p>

            <p class="mt-2 hidden text-xs font-medium text-blue-800" data-lm-tp-copy-source-label></p>

            <div class="mt-2 hidden" data-lm-tp-copy-source-wrap>
                <label for="{{ $sourceId }}" class="mb-1 block text-xs font-medium text-gray-700">Sumber LM/TP</label>
                <select id="{{ $sourceId }}"
                        name="{{ $sourceName }}"
                        disabled
                        class="block w-full rounded-lg border border-blue-200 bg-white p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                        data-lm-tp-copy-source>
                    <option value="">Pilih sumber LM/TP</option>
                </select>
            </div>
        </div>
    </div>

    @error($fieldPrefix ? "{$fieldPrefix}.copy_lm_tp_source_id" : 'copy_lm_tp_source_id')
        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
