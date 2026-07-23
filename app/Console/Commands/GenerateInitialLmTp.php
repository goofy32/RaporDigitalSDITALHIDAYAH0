<?php

namespace App\Console\Commands;

use App\Models\LingkupMateri;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use App\Models\TujuanPembelajaran;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateInitialLmTp extends Command
{
    protected $signature = 'initial-data:generate-lm-tp
        {--force : Allow running outside local/testing/demo environments}';

    protected $description = 'Generate semi-realistic Lingkup Materi and Tujuan Pembelajaran for active-year subjects';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'demo']) && ! $this->option('force')) {
            $this->error('Generator ini hanya boleh dijalankan di environment local, testing, atau demo kecuali menggunakan --force.');

            return self::FAILURE;
        }

        $tahunAjaran = TahunAjaran::where('is_active', true)->first();

        if (! $tahunAjaran) {
            $this->error('Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.');

            return self::FAILURE;
        }

        $subjects = MataPelajaran::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', $tahunAjaran->semester)
            ->orderBy('nama_pelajaran')
            ->orderBy('id')
            ->get();

        if ($subjects->isEmpty()) {
            $this->error('Tidak ada mata pelajaran pada tahun ajaran dan semester aktif.');

            return self::FAILURE;
        }

        $stats = [
            'subjects_processed' => 0,
            'lm_created' => 0,
            'lm_reused' => 0,
            'tp_created' => 0,
            'tp_reused' => 0,
        ];

        DB::transaction(function () use ($subjects, &$stats) {
            foreach ($subjects as $subject) {
                $stats['subjects_processed']++;

                foreach ($this->templateFor((string) $subject->nama_pelajaran) as $lmIndex => $lmTemplate) {
                    $lm = LingkupMateri::withoutEvents(fn () => LingkupMateri::firstOrCreate(
                        [
                            'mata_pelajaran_id' => $subject->id,
                            'judul_lingkup_materi' => $lmTemplate['title'],
                        ],
                        [
                            'is_active' => true,
                        ]
                    ));

                    $lm->wasRecentlyCreated ? $stats['lm_created']++ : $stats['lm_reused']++;

                    foreach ($lmTemplate['tp'] as $tpIndex => $description) {
                        $tp = TujuanPembelajaran::withoutEvents(fn () => TujuanPembelajaran::firstOrCreate(
                            [
                                'lingkup_materi_id' => $lm->id,
                                'kode_tp' => sprintf('TP%d.%d', $lmIndex + 1, $tpIndex + 1),
                            ],
                            [
                                'deskripsi_tp' => $description,
                            ]
                        ));

                        $tp->wasRecentlyCreated ? $stats['tp_created']++ : $stats['tp_reused']++;
                    }
                }
            }
        });

        $this->info("LM/TP selesai disiapkan untuk {$tahunAjaran->tahun_ajaran} semester {$tahunAjaran->semester}.");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Subjects processed', $stats['subjects_processed']],
                ['LM created', $stats['lm_created']],
                ['LM reused', $stats['lm_reused']],
                ['TP created', $stats['tp_created']],
                ['TP reused', $stats['tp_reused']],
            ]
        );
        $this->line('Data nilai tidak dibuat.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{title: string, tp: array<int, string>}>
     */
    private function templateFor(string $subjectName): array
    {
        return $this->templates()[$this->normalizeSubjectName($subjectName)] ?? $this->genericTemplate();
    }

    private function normalizeSubjectName(string $subjectName): string
    {
        $normalized = Str::of($subjectName)
            ->lower()
            ->replace(['.', '-'], ' ')
            ->squish()
            ->toString();

        return match ($normalized) {
            'mtk', 'matematika' => 'mtk',
            'b indonesia', 'bahasa indonesia' => 'b_indonesia',
            'b inggris', 'bahasa inggris' => 'b_inggris',
            'bahasa sunda', 'b sunda' => 'bahasa_sunda',
            'ipas' => 'ipas',
            'pai', 'pendidikan agama islam' => 'pai',
            'pendidikan pancasila' => 'pendidikan_pancasila',
            'pjok', 'penjas' => 'pjok',
            'plh' => 'plh',
            'seni budaya' => 'seni_budaya',
            default => $normalized,
        };
    }

    /**
     * @return array<string, array<int, array{title: string, tp: array<int, string>}>>
     */
    private function templates(): array
    {
        return [
            'mtk' => [
                [
                    'title' => 'Bilangan dan Operasi Hitung',
                    'tp' => [
                        'Memahami nilai tempat dan urutan bilangan.',
                        'Menyelesaikan operasi penjumlahan dan pengurangan.',
                        'Menyelesaikan soal cerita matematika sederhana.',
                    ],
                ],
                [
                    'title' => 'Pengukuran dan Geometri',
                    'tp' => [
                        'Mengukur panjang, berat, waktu, atau luas dengan satuan yang sesuai.',
                        'Mengenal bentuk datar dan bangun ruang sederhana.',
                        'Menyelesaikan masalah pengukuran dalam kehidupan sehari-hari.',
                    ],
                ],
            ],
            'b_indonesia' => [
                [
                    'title' => 'Membaca dan Memahami Teks',
                    'tp' => [
                        'Membaca teks sederhana dengan lafal dan intonasi yang tepat.',
                        'Menemukan informasi penting dari teks bacaan.',
                        'Menyimpulkan isi teks dengan bahasa sendiri.',
                    ],
                ],
                [
                    'title' => 'Menulis dan Berbicara',
                    'tp' => [
                        'Menulis kalimat atau paragraf sederhana dengan runtut.',
                        'Menyampaikan pendapat secara santun.',
                        'Menceritakan kembali pengalaman atau isi bacaan.',
                    ],
                ],
            ],
            'b_inggris' => [
                [
                    'title' => 'Vocabulary and Daily Expressions',
                    'tp' => [
                        'Mengenal kosakata benda, warna, angka, dan kegiatan sehari-hari.',
                        'Menggunakan sapaan dan ungkapan sederhana.',
                        'Menyebutkan informasi diri dengan kalimat sederhana.',
                    ],
                ],
                [
                    'title' => 'Simple Reading and Speaking',
                    'tp' => [
                        'Membaca kata atau kalimat pendek berbahasa Inggris.',
                        'Menjawab pertanyaan sederhana berdasarkan gambar atau teks.',
                        'Melakukan dialog pendek dengan percaya diri.',
                    ],
                ],
            ],
            'bahasa_sunda' => [
                [
                    'title' => 'Kaweruh Basa Sunda',
                    'tp' => [
                        'Mikawanoh kecap sapopoe dina basa Sunda.',
                        'Ngagunakeun ungkara basajan dina paguneman.',
                        'Maca teks pondok basa Sunda kalayan leres.',
                    ],
                ],
                [
                    'title' => 'Nulis jeung Nyarita',
                    'tp' => [
                        'Nulis kalimah basajan dina basa Sunda.',
                        'Nyaritakeun pangalaman ku basa Sunda anu merenah.',
                        'Ngahargaan budaya jeung tatakrama Sunda.',
                    ],
                ],
            ],
            'ipas' => [
                [
                    'title' => 'Makhluk Hidup dan Lingkungan',
                    'tp' => [
                        'Mengidentifikasi ciri-ciri makhluk hidup.',
                        'Menjelaskan hubungan makhluk hidup dengan lingkungannya.',
                        'Menunjukkan sikap peduli terhadap lingkungan sekitar.',
                    ],
                ],
                [
                    'title' => 'Benda, Energi, dan Perubahan',
                    'tp' => [
                        'Mengamati sifat benda di sekitar.',
                        'Menjelaskan perubahan bentuk benda atau energi sederhana.',
                        'Melakukan pengamatan sederhana secara teliti.',
                    ],
                ],
            ],
            'pai' => [
                [
                    'title' => 'Al-Quran Hadis dan Ibadah',
                    'tp' => [
                        'Membaca surah pendek atau doa harian dengan benar.',
                        'Menjelaskan makna ibadah sehari-hari.',
                        'Mempraktikkan tata cara ibadah sederhana.',
                    ],
                ],
                [
                    'title' => 'Akidah dan Akhlak',
                    'tp' => [
                        'Menunjukkan perilaku jujur, disiplin, dan tanggung jawab.',
                        'Meneladani kisah nabi atau tokoh teladan.',
                        'Membiasakan adab baik di rumah dan sekolah.',
                    ],
                ],
            ],
            'pendidikan_pancasila' => [
                [
                    'title' => 'Nilai Pancasila',
                    'tp' => [
                        'Menjelaskan contoh perilaku sesuai sila Pancasila.',
                        'Menunjukkan sikap saling menghargai.',
                        'Menerapkan tanggung jawab di rumah dan sekolah.',
                    ],
                ],
                [
                    'title' => 'Hak, Kewajiban, dan Musyawarah',
                    'tp' => [
                        'Membedakan hak dan kewajiban sebagai siswa.',
                        'Mengikuti aturan kelas dengan tertib.',
                        'Berpartisipasi dalam musyawarah sederhana.',
                    ],
                ],
            ],
            'pjok' => [
                [
                    'title' => 'Gerak Dasar dan Permainan',
                    'tp' => [
                        'Mempraktikkan gerak lokomotor dan nonlokomotor.',
                        'Mengikuti permainan sederhana dengan aturan.',
                        'Menunjukkan kerja sama dan sportivitas.',
                    ],
                ],
                [
                    'title' => 'Kebugaran dan Kesehatan',
                    'tp' => [
                        'Melakukan latihan kebugaran sederhana.',
                        'Menjelaskan pentingnya kebersihan dan pola hidup sehat.',
                        'Menjaga keselamatan saat beraktivitas fisik.',
                    ],
                ],
            ],
            'plh' => [
                [
                    'title' => 'Kebersihan dan Lingkungan Sekolah',
                    'tp' => [
                        'Menjaga kebersihan kelas dan halaman sekolah.',
                        'Mengelompokkan sampah sesuai jenisnya.',
                        'Membiasakan perilaku hemat air dan listrik.',
                    ],
                ],
                [
                    'title' => 'Pelestarian Alam',
                    'tp' => [
                        'Menjelaskan cara merawat tanaman dan hewan.',
                        'Mengikuti kegiatan penghijauan sederhana.',
                        'Menunjukkan sikap peduli terhadap kelestarian lingkungan.',
                    ],
                ],
            ],
            'seni_budaya' => [
                [
                    'title' => 'Seni Rupa dan Kerajinan',
                    'tp' => [
                        'Mengenal unsur garis, warna, bentuk, dan tekstur.',
                        'Membuat karya seni sederhana dengan bahan sekitar.',
                        'Mengapresiasi karya seni teman secara santun.',
                    ],
                ],
                [
                    'title' => 'Seni Musik, Tari, dan Ekspresi',
                    'tp' => [
                        'Mengenal irama, lagu, atau gerak sederhana.',
                        'Menampilkan ekspresi seni dengan percaya diri.',
                        'Menunjukkan kerja sama dalam kegiatan seni.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{title: string, tp: array<int, string>}>
     */
    private function genericTemplate(): array
    {
        return [
            [
                'title' => 'Pemahaman Konsep Dasar',
                'tp' => [
                    'Mengenal konsep dasar materi pembelajaran.',
                    'Menjelaskan contoh penerapan materi secara sederhana.',
                    'Menghubungkan materi dengan pengalaman sehari-hari.',
                ],
            ],
            [
                'title' => 'Keterampilan dan Penerapan',
                'tp' => [
                    'Mengerjakan latihan sesuai petunjuk pembelajaran.',
                    'Menyampaikan hasil belajar dengan bahasa yang runtut.',
                    'Menunjukkan sikap aktif dan bertanggung jawab dalam pembelajaran.',
                ],
            ],
        ];
    }
}
