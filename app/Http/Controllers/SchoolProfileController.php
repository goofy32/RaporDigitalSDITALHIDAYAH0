<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProfilSekolah;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SchoolProfileController extends Controller
{
    // Menampilkan data profil sekolah
    public function show()
    {
        $profil = ProfilSekolah::first(); // Ambil data profil pertama

        if (!$profil) {
            // Jika data profil belum ada, arahkan ke form untuk menambah data
            return redirect()->route('profile.edit')->with('warning', 'Silakan isi profil sekolah terlebih dahulu.');
        }

        return view('data.school_data', compact('profil'));
    }

    // Menampilkan form untuk menambah atau mengedit data profil sekolah
    public function edit()
    {
        $profil = ProfilSekolah::first(); // Ambil data profil pertama
        $tahunAjarans = \App\Models\TahunAjaran::orderBy('tanggal_mulai', 'desc')->get();
        return view('admin.profile', compact('profil', 'tahunAjarans'));
    }

    // Menyimpan atau memperbarui data profil sekolah
    public function store(Request $request)
    {
        // Validasi input
        $validated = $request->validate([
            'nama_instansi' => 'required|string|max:255',
            'nama_sekolah' => 'required|string|max:255',
            'npsn' => 'required|string|max:100',
            'alamat' => 'required|string',
            'kelurahan' => 'nullable|string|max:255',
            'kecamatan' => 'nullable|string|max:255',
            'kabupaten' => 'nullable|string|max:255',
            'provinsi' => 'nullable|string|max:255',
            'kode_pos' => 'required|string|max:10',
            'telepon' => 'required|string|max:20',
            'email_sekolah' => 'required|email|max:255',
            'website' => 'nullable|string|max:255',
            'tahun_pelajaran' => 'required|string|max:255',
            'semester' => 'required|integer',
            'kepala_sekolah' => 'required|string|max:255',
            'nip_kepala_sekolah' => 'nullable|string|max:100',
            'nip_wali_kelas' => 'nullable|string|max:100',
            'guru_kelas' => 'nullable|integer',
            'kelas' => 'nullable|integer',
            'jumlah_siswa' => 'nullable|integer',
            'tempat_terbit' => 'required|string|max:255',
            'tanggal_terbit' => 'required|date',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
    
        // Cek apakah data profil sudah ada
        $profil = ProfilSekolah::first();
        
        // Siapkan data yang akan diupdate/disimpan
        $data = $request->except(['_token', 'logo']);

        // Jika ada file logo yang diupload
        if ($request->hasFile('logo')) {
            \Log::info('Logo file found', [
                'file' => $request->file('logo'),
                'original_name' => $request->file('logo')->getClientOriginalName(),
                'mime' => $request->file('logo')->getMimeType(),
                'size' => $request->file('logo')->getSize()
            ]);
            
            try {
                // Jika ada logo lama, hapus dulu
                if ($profil && $profil->logo) {
                    Storage::disk('public')->delete($profil->logo);
                }
                
                // Simpan logo baru
                $logoPath = $this->storePublicUpload($request->file('logo'), 'logos');
                $data['logo'] = $logoPath;
                
                \Log::info('Logo stored successfully', [
                    'path' => $logoPath
                ]);
            } catch (\Exception $e) {
                \Log::error('Error storing logo', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    
        // Cari tahun ajaran aktif berdasarkan tahun dan semester yang dipilih
        $tahunAjaran = \App\Models\TahunAjaran::where('tahun_ajaran', $validated['tahun_pelajaran'])
                                             ->where('semester', $validated['semester'])
                                             ->first();
    
        // Jika tahun ajaran ditemukan, atur sebagai aktif
        if ($tahunAjaran) {
            // Nonaktifkan semua tahun ajaran dulu
            \App\Models\TahunAjaran::where('is_active', true)
                                  ->update(['is_active' => false]);
            
            // Aktifkan tahun ajaran yang dipilih
            $tahunAjaran->update(['is_active' => true]);
            
            // Set session untuk tahun ajaran
            session(['tahun_ajaran_id' => $tahunAjaran->id]);
        }
    
        if ($profil) {
            // Jika data profil sudah ada, lakukan update
            $profil->update($data);
        } else {
            // Jika data profil belum ada, buat baru
            $profil = ProfilSekolah::create($data);
        }
    
        // Setelah menyimpan data, arahkan ke halaman data profil sekolah
        return redirect()->route('profile')->with('success', 'Profil sekolah berhasil disimpan.');
    }

    private function storePublicUpload(\Illuminate\Http\UploadedFile $file, string $folder, ?string $fileName = null): string
    {
        Storage::disk('public')->makeDirectory($folder);

        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $baseName = $fileName
            ? pathinfo($fileName, PATHINFO_FILENAME)
            : pathinfo($file->hashName(), PATHINFO_FILENAME);
        $safeBaseName = Str::slug($baseName ?: pathinfo($file->hashName(), PATHINFO_FILENAME));
        $finalFileName = $safeBaseName . '.' . $extension;

        $destinationDirectory = Storage::disk('public')->path($folder);
        $file->move($destinationDirectory, $finalFileName);

        $filePath = $folder . '/' . $finalFileName;

        if (!Storage::disk('public')->exists($filePath)) {
            throw new \RuntimeException('File gagal disimpan.');
        }

        return $filePath;
    }
}
