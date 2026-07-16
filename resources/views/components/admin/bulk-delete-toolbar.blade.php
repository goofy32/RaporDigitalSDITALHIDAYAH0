@props([
    'action',
    'recordType',
    'formId',
])

<div class="mb-3 flex flex-col gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3 sm:flex-row sm:items-center sm:justify-between"
     data-bulk-delete
     data-bulk-delete-form="{{ $formId }}"
     data-record-type="{{ $recordType }}">
    <div class="text-sm text-gray-600">
        <span class="font-medium text-gray-800" data-bulk-delete-count>0 data dipilih</span>
        <span class="ml-1">Pilih checkbox pada baris yang ingin dihapus.</span>
    </div>

    <button type="button"
            class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500"
            data-bulk-delete-open
            disabled>
        Hapus Terpilih
    </button>

    <div class="fixed inset-0 z-50 hidden items-center justify-center bg-gray-900/50 p-4"
         data-bulk-delete-modal
         data-live-list-ignore
         role="dialog"
         aria-modal="true"
         aria-labelledby="{{ $formId }}-title">
        <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
            <div class="border-b border-gray-100 p-5">
                <h3 id="{{ $formId }}-title" class="text-lg font-semibold text-gray-900">Hapus data terpilih?</h3>
                <p class="mt-2 text-sm text-gray-600">
                    Data yang dihapus akan dipindahkan ke Recycle Bin jika tersedia. Anda dapat memulihkannya dari Recycle Bin selama belum dihapus permanen.
                </p>
            </div>

            <form id="{{ $formId }}" action="{{ $action }}" method="POST" class="p-5">
                @csrf
                @method('DELETE')

                <div data-bulk-delete-hidden-inputs></div>

                <div class="rounded-lg bg-yellow-50 p-3 text-sm text-yellow-800">
                    <p><span data-bulk-delete-selected-count>0</span> data {{ $recordType }} akan dihapus.</p>
                    <p class="mt-1">Sebagian data mungkin tidak dapat dihapus jika masih terhubung dengan data lain.</p>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button"
                            class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                            data-bulk-delete-cancel>
                        Batal
                    </button>
                    <button type="submit"
                            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                        Ya, Hapus Terpilih
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
