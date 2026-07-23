<?php

namespace App\Http\Controllers;

use App\Models\ProfilSekolah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SchoolProfileController extends Controller
{
    // Menampilkan data profil sekolah
    public function show()
    {
        $profil = ProfilSekolah::first(); // Ambil data profil pertama

        if (! $profil) {
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
            'tahun_pelajaran' => 'nullable|string|max:255',
            'semester' => 'nullable|integer|in:1,2',
            'kepala_sekolah' => 'required|string|max:255',
            'nip_kepala_sekolah' => 'nullable|string|max:100',
            'nip_wali_kelas' => 'nullable|string|max:100',
            'guru_kelas' => 'nullable|integer',
            'kelas' => 'nullable|integer',
            'jumlah_siswa' => 'nullable|integer',
            'tempat_terbit' => 'required|string|max:255',
            'tanggal_terbit' => 'required|date',
        ]);

        // Cek apakah data profil sudah ada
        $profil = ProfilSekolah::first();

        // Siapkan data yang akan diupdate/disimpan
        $data = $validated;

        if ($profil) {
            // Jika data profil sudah ada, lakukan update
            $profil->update($data);
        } else {
            // Jika data profil belum ada, buat baru
            $profil = ProfilSekolah::create($data);
        }

        Cache::forget('profil_sekolah');

        // Setelah menyimpan data, arahkan ke halaman data profil sekolah
        return redirect()->route('profile')->with('success', 'Profil sekolah berhasil disimpan.');
    }
}
