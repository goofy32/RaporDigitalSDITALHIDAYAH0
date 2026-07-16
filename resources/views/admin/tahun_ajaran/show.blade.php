@extends('layouts.app')

@section('title', 'Detail Tahun Ajaran')

@section('content')
<div class="p-4 bg-white">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <h2 class="text-2xl font-bold text-green-700">Detail Tahun Ajaran</h2>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('tahun.ajaran.index') }}" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                Kembali
            </a>
        </div>
    </div>

    @if(session('success'))
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
        <p>{{ session('success') }}</p>
    </div>
    @endif

    @if(session('error'))
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
        <p>{{ session('error') }}</p>
    </div>
    @endif

    <!-- Basic Information -->
    <div class="mb-8">
        <div class="flex items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Informasi Tahun Ajaran</h3>
            @if($tahunAjaran->trashed())
                <span class="ml-3 px-2 py-1 text-xs font-semibold text-orange-800 bg-orange-100 rounded-full">Diarsipkan</span>
            @elseif($tahunAjaran->is_active)
                <span class="ml-3 px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded-full">Aktif</span>
            @endif
        </div>
        
        <div class="bg-gray-50 rounded-lg p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-500">Tahun Ajaran</p>
                    <p class="text-lg font-medium">{{ $tahunAjaran->tahun_ajaran }}</p>
                </div>
                
                <div>
                    <p class="text-sm text-gray-500">Semester</p>
                    <p class="text-lg font-medium">{{ $tahunAjaran->semester }} ({{ $tahunAjaran->semester == 1 ? 'Ganjil' : 'Genap' }})</p>
                </div>
                
                <div>
                    <p class="text-sm text-gray-500">Tanggal Mulai</p>
                    <p class="text-lg font-medium">{{ $tahunAjaran->tanggal_mulai->format('d F Y') }}</p>
                </div>
                
                <div>
                    <p class="text-sm text-gray-500">Tanggal Selesai</p>
                    <p class="text-lg font-medium">{{ $tahunAjaran->tanggal_selesai->format('d F Y') }}</p>
                </div>
                
                <div class="md:col-span-2">
                    <p class="text-sm text-gray-500">Deskripsi</p>
                    <p class="text-base">{{ $tahunAjaran->deskripsi ?? 'Tidak ada deskripsi' }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="mb-8">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Statistik</h3>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-green-50 border border-green-100 rounded-lg p-4">
                <p class="text-2xl font-bold text-green-700">{{ $totalKelas }}</p>
                <p class="text-sm text-green-600">Kelas</p>
            </div>
            
            <div class="bg-green-50 border border-green-100 rounded-lg p-4">
                <p class="text-2xl font-bold text-green-700">{{ $totalSiswa }}</p>
                <p class="text-sm text-green-600">Siswa</p>
            </div>
            
            <div class="bg-green-50 border border-green-100 rounded-lg p-4">
                <p class="text-2xl font-bold text-green-700">{{ $totalMataPelajaran }}</p>
                <p class="text-sm text-green-600">Mata Pelajaran</p>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="mb-8">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ $tahunAjaran->trashed() ? 'Tindakan Arsip' : 'Tindakan' }}</h3>
        
        <div class="flex flex-wrap gap-4">
            @if(!$tahunAjaran->trashed())
                <a href="{{ route('tahun.ajaran.edit', $tahunAjaran->id) }}" 
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-edit mr-2"></i>Edit Tahun Ajaran
                </a>
            @endif
            
            @if($tahunAjaran->trashed())
                <form action="{{ route('tahun.ajaran.restore', $tahunAjaran->id) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" 
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                        onclick="return confirm('Apakah Anda yakin ingin memulihkan tahun ajaran ini?')">
                        <i class="fas fa-undo mr-2"></i>Pulihkan Tahun Ajaran
                    </button>
                </form>

                @if($permanentDeleteProtectionMessage)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                        <div class="mb-1.5">
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 ring-1 ring-gray-200">Dilindungi</span>
                        </div>
                        <p>{{ $permanentDeleteProtectionMessage }}</p>
                    </div>
                @else
                    <form action="{{ route('tahun.ajaran.force-delete', $tahunAjaran->id) }}" method="POST" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
                            onclick="return confirm('PERHATIAN: Tindakan ini tidak dapat dibatalkan. Tahun ajaran hanya dapat dihapus permanen jika tidak memiliki data siswa/enrollment atau riwayat terkait.\n\nApakah Anda benar-benar yakin?')">
                            <i class="fas fa-trash mr-2"></i>Hapus Permanen
                        </button>
                    </form>
                @endif
            @elseif(!$tahunAjaran->is_active)
                <form action="{{ route('tahun.ajaran.set-active', $tahunAjaran->id) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" 
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                        onclick="return confirm('Apakah Anda yakin ingin mengaktifkan tahun ajaran ini?')">
                        <i class="fas fa-power-off mr-2"></i>Aktifkan Tahun Ajaran
                    </button>
                </form>
            @endif

            <!-- Button to advance semester (only shown for active academic year with odd semester) -->
            @if(!$tahunAjaran->trashed() && $tahunAjaran->is_active && $tahunAjaran->semester == 1)
                <div class="w-full rounded-lg border border-amber-200 bg-amber-50 p-4"
                     x-data="{ phrase: '', submitting: false, required: 'LANJUTKAN KE SEMESTER GENAP', get canSubmit() { return this.phrase.trim() === this.required && !this.submitting; } }">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 text-amber-600">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="flex-1 space-y-3">
                            <div>
                                <h3 class="font-semibold text-amber-900">Lanjutkan ke Semester Genap?</h3>
                                <p class="mt-1 text-sm text-amber-800">
                                    Semester Genap akan dibuat berdasarkan kondisi data saat ini. Perubahan yang Anda lakukan pada Semester Ganjil setelah proses ini tidak akan otomatis disalin ke Semester Genap.
                                </p>
                                <p class="mt-1 text-sm text-amber-800">
                                    Setelah proses berhasil, Semester Ganjil akan dinonaktifkan dan Semester Genap akan menjadi periode aktif untuk Admin, Pengajar, dan Wali Kelas.
                                </p>
                                <p class="mt-1 text-sm text-amber-800">
                                    Nilai, catatan, rapor, dan hasil pekerjaan siswa dari Semester Ganjil tidak disalin sebagai pekerjaan Semester Genap.
                                </p>
                            </div>

                            @if($semesterGenapReadiness)
                                @include('admin.tahun_ajaran.partials.transition_readiness', ['readiness' => $semesterGenapReadiness])
                            @endif

                            <p class="text-xs text-amber-800">
                                Jika proses sudah dijalankan terlalu awal, Admin dapat mengaktifkan kembali Semester Ganjil untuk menyelesaikan data sumber. Data yang sudah dibuat di Semester Genap tidak otomatis diperbarui.
                            </p>

                            <form action="{{ route('tahun.ajaran.advance-semester', $tahunAjaran->id) }}"
                                  method="POST"
                                  class="space-y-3"
                                  x-on:submit="if (!canSubmit) { $event.preventDefault(); return false; } submitting = true;">
                                @csrf
                                <input type="hidden" name="tahun_ajaran" value="{{ $tahunAjaran->tahun_ajaran }}">
                                <input type="hidden" name="tanggal_mulai" value="{{ $tahunAjaran->tanggal_mulai->format('Y-m-d') }}">
                                <input type="hidden" name="tanggal_selesai" value="{{ $tahunAjaran->tanggal_selesai->format('Y-m-d') }}">
                                <input type="hidden" name="deskripsi" value="{{ $tahunAjaran->deskripsi }}">
                                <input type="hidden" name="is_active" value="1">
                                <input type="hidden" name="semester" value="2">

                                <div>
                                    <label for="transition_confirmation_semester" class="block text-sm font-medium text-amber-900">
                                        Ketik <span class="font-bold">LANJUTKAN KE SEMESTER GENAP</span> untuk melanjutkan.
                                    </label>
                                    <input id="transition_confirmation_semester"
                                           name="transition_confirmation"
                                           type="text"
                                           x-model="phrase"
                                           autocomplete="off"
                                           class="mt-1 w-full max-w-xl rounded-md border-amber-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                                           placeholder="LANJUTKAN KE SEMESTER GENAP">
                                    @error('transition_confirmation')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <button type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    disabled
                                    x-bind:disabled="!canSubmit"
                                    x-html="submitting ? '<i class=&quot;fas fa-spinner fa-spin mr-2&quot;></i>Memproses...' : '<i class=&quot;fas fa-arrow-right mr-2&quot;></i>Lanjutkan ke Semester Genap'">
                                    <i class="fas fa-arrow-right mr-2"></i>Lanjutkan ke Semester Genap
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Tombol untuk membuat tahun ajaran baru - HANYA muncul di semester genap -->
            @if(!$tahunAjaran->trashed() && $tahunAjaran->semester == 2)
                <a href="{{ route('tahun.ajaran.copy', $tahunAjaran->id) }}" 
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center">
                    <i class="fas fa-graduation-cap mr-2"></i>Buat Tahun Ajaran Berikutnya
                </a>
                
                <!-- Info box untuk menjelaskan fungsi tombol -->
                <div class="w-full mt-2">
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-info-circle text-indigo-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-green-800">
                                    <strong>Buat Tahun Ajaran Berikutnya:</strong> Buat Tahun Ajaran Berikutnya: Digunakan untuk membuat tahun ajaran baru agar bisa mengakses fitur kenaikan kelas. Wajib dilakukan di akhir semester genap .
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if(!$tahunAjaran->is_active && !$tahunAjaran->trashed())
                <form action="{{ route('tahun.ajaran.destroy', $tahunAjaran->id) }}" method="POST" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" 
                        class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700"
                        onclick="return confirm('Apakah Anda yakin ingin mengarsipkan tahun ajaran {{ $tahunAjaran->tahun_ajaran }}?\n\nData terkait masih dapat diakses setelah diarsipkan dengan menampilkan tahun ajaran terarsip.')">
                        <i class="fas fa-archive mr-2"></i>Arsipkan Tahun Ajaran
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
