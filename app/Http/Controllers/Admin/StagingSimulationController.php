<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScoreController;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Services\SiswaKelasSemesterResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class StagingSimulationController extends Controller
{
    public function index()
    {
        $this->abortUnlessEnabled();

        return view('admin.testing.multi_user_simulation', [
            'enabled' => true,
            'maxRequests' => $this->maxRequests(),
            'scoreConfirmation' => (string) config('staging_test_tools.score_confirmation'),
            'years' => TahunAjaran::query()
                ->select('id', 'tahun_ajaran', 'semester', 'is_active')
                ->orderByDesc('is_active')
                ->orderByDesc('id')
                ->get(),
            'simulationData' => [
                'classes' => $this->simulationClassOptions(),
                'queue_health_url' => route('admin.testing.multi-user.queue-health'),
                'pdf_url' => route('admin.testing.multi-user.pdf'),
                'score_url' => route('admin.testing.multi-user.score'),
                'csrf_token' => csrf_token(),
                'max_requests' => $this->maxRequests(),
                'score_confirmation' => (string) config('staging_test_tools.score_confirmation'),
            ],
            'queueHealth' => $this->queueHealthData(),
        ]);
    }

    public function queueHealth()
    {
        $this->abortUnlessEnabled();

        return response()->json($this->queueHealthData());
    }

    public function simulatePdf(Request $request)
    {
        $this->abortUnlessEnabled();

        $validated = Validator::make($request->all(), [
            'action' => ['required', Rule::in(['preview', 'download'])],
            'report_type' => ['required', Rule::in(['UTS', 'UAS'])],
            'tahun_ajaran_id' => ['required', 'integer', 'exists:tahun_ajarans,id'],
            'kelas_id' => ['required', 'integer', 'exists:kelas,id'],
            'student_id' => ['required', 'integer', 'exists:siswas,id'],
            'request_count' => ['required', 'integer', 'min:1', 'max:'.$this->maxRequests()],
            'request_index' => ['required', 'integer', 'min:1'],
        ], $this->validationMessages())->validate();

        if ((int) $validated['request_index'] > (int) $validated['request_count']) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor request melebihi jumlah request simulasi.',
            ], 422);
        }

        $context = $this->validatedDummyContext(
            (int) $validated['tahun_ajaran_id'],
            (int) $validated['kelas_id'],
            (int) $validated['student_id']
        );

        $wali = $this->waliForClass($context['kelas']);
        if (! $wali) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas dummy belum memiliki wali kelas untuk simulasi PDF.',
            ], 422);
        }

        $disposition = $validated['action'] === 'download' ? 'attachment' : 'inline';

        return $this->withTemporaryGuruContext($wali->id, 'wali_kelas', $context['tahun_ajaran']->id, function () use ($context, $validated, $disposition) {
            $pdfRequest = Request::create(
                route('wali_kelas.rapor.preview-pdf', $context['siswa'], false),
                'GET',
                [
                    'type' => $validated['report_type'],
                    'tahun_ajaran_id' => $context['tahun_ajaran']->id,
                    'disposition' => $disposition,
                ],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                ]
            );

            if (request()->hasSession()) {
                $pdfRequest->setLaravelSession(request()->session());
            }

            try {
                $response = app(ReportController::class)->previewPdf($context['siswa'], $pdfRequest);
                $payload = method_exists($response, 'getData')
                    ? (array) $response->getData(true)
                    : [];

                return response()->json([
                    'success' => ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300),
                    'status' => $payload['status'] ?? ($response->getStatusCode() < 400 ? 'ok' : 'failed'),
                    'http_status' => $response->getStatusCode(),
                    'cache_hit' => (bool) ($payload['cache_hit'] ?? false),
                    'request_id' => $payload['request_id'] ?? null,
                    'poll_url' => $payload['poll_url'] ?? null,
                    'url' => $payload['url'] ?? null,
                    'message' => $payload['message'] ?? 'Request PDF simulasi selesai diproses.',
                ], $response->getStatusCode());
            } catch (HttpExceptionInterface $exception) {
                return response()->json([
                    'success' => false,
                    'status' => 'failed',
                    'http_status' => $exception->getStatusCode(),
                    'message' => 'Simulasi PDF ditolak oleh otorisasi/konteks rapor.',
                ], $exception->getStatusCode());
            }
        });
    }

    public function simulateScore(Request $request)
    {
        $this->abortUnlessEnabled();

        $validated = Validator::make($request->all(), [
            'confirmation' => ['required', Rule::in([(string) config('staging_test_tools.score_confirmation')])],
            'tahun_ajaran_id' => ['required', 'integer', 'exists:tahun_ajarans,id'],
            'kelas_id' => ['required', 'integer', 'exists:kelas,id'],
            'mata_pelajaran_id' => ['required', 'integer', 'exists:mata_pelajarans,id'],
            'student_id' => ['required', 'integer', 'exists:siswas,id'],
            'request_count' => ['required', 'integer', 'min:1', 'max:'.$this->maxRequests()],
            'request_index' => ['required', 'integer', 'min:1'],
        ], $this->validationMessages())->validate();

        if ((int) $validated['request_index'] > (int) $validated['request_count']) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor request melebihi jumlah request simulasi.',
            ], 422);
        }

        $context = $this->validatedDummyContext(
            (int) $validated['tahun_ajaran_id'],
            (int) $validated['kelas_id'],
            (int) $validated['student_id']
        );

        $mataPelajaran = MataPelajaran::query()
            ->whereKey((int) $validated['mata_pelajaran_id'])
            ->where('kelas_id', $context['kelas']->id)
            ->where('tahun_ajaran_id', $context['tahun_ajaran']->id)
            ->where('semester', $context['tahun_ajaran']->semester)
            ->first();

        if (! $mataPelajaran || ! $this->isDummyText($mataPelajaran->nama_pelajaran)) {
            return response()->json([
                'success' => false,
                'message' => 'Simulasi nilai hanya boleh memakai mata pelajaran dummy/test/simulasi.',
            ], 422);
        }

        if (! $mataPelajaran->guru_id) {
            return response()->json([
                'success' => false,
                'message' => 'Mata pelajaran dummy belum memiliki guru pengajar.',
            ], 422);
        }

        return $this->withTemporaryGuruContext((int) $mataPelajaran->guru_id, 'pengajar', $context['tahun_ajaran']->id, function () use ($context, $mataPelajaran, $validated) {
            $score = 70 + ((int) $validated['request_index'] % 20);
            $scoreRequest = Request::create(
                route('pengajar.score.save_scores', $mataPelajaran, false),
                'POST',
                [
                    'scores' => [
                        $context['siswa']->id => [
                            'nilai_tes' => $score,
                            'nilai_non_tes' => $score,
                        ],
                    ],
                ],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                ]
            );

            if (request()->hasSession()) {
                $scoreRequest->setLaravelSession(request()->session());
            }

            try {
                $response = app(ScoreController::class)->saveScore($scoreRequest, $mataPelajaran->id);
                $payload = method_exists($response, 'getData')
                    ? (array) $response->getData(true)
                    : [];

                return response()->json([
                    'success' => ($payload['success'] ?? false) === true,
                    'status' => ($payload['success'] ?? false) === true ? 'saved' : 'failed',
                    'http_status' => $response->getStatusCode(),
                    'message' => $payload['message'] ?? 'Simulasi simpan nilai selesai diproses.',
                ], $response->getStatusCode());
            } catch (HttpExceptionInterface $exception) {
                return response()->json([
                    'success' => false,
                    'status' => 'failed',
                    'http_status' => $exception->getStatusCode(),
                    'message' => 'Simulasi nilai ditolak oleh otorisasi/konteks pengajar.',
                ], $exception->getStatusCode());
            }
        });
    }

    private function abortUnlessEnabled(): void
    {
        abort_unless((bool) config('staging_test_tools.enabled'), 404);
    }

    private function maxRequests(): int
    {
        return (int) config('staging_test_tools.max_requests', 20);
    }

    private function validationMessages(): array
    {
        return [
            'confirmation.in' => 'Konfirmasi simulasi nilai tidak sesuai.',
            'request_count.max' => 'Jumlah request simulasi maksimal '.$this->maxRequests().'.',
            'report_type.in' => 'Tipe rapor hanya boleh UTS atau UAS.',
            'action.in' => 'Aksi simulasi PDF tidak valid.',
        ];
    }

    private function simulationClassOptions(): array
    {
        return Kelas::query()
            ->with('tahunAjaran')
            ->orderBy('tahun_ajaran_id')
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get()
            ->map(function (Kelas $kelas) {
                $tahunAjaran = $kelas->tahunAjaran;
                $semester = (int) ($tahunAjaran?->semester ?: 1);
                $students = $tahunAjaran
                    ? app(SiswaKelasSemesterResolver::class)
                        ->studentsForClass($kelas->id, $tahunAjaran->id, $semester, true)
                        ->filter(fn (Siswa $siswa) => $this->isDummySiswa($siswa))
                        ->sortBy('nama')
                        ->values()
                    : collect();

                $subjects = $tahunAjaran
                    ? MataPelajaran::query()
                        ->where('kelas_id', $kelas->id)
                        ->where('tahun_ajaran_id', $tahunAjaran->id)
                        ->where('semester', $semester)
                        ->orderBy('nama_pelajaran')
                        ->get()
                        ->filter(fn (MataPelajaran $mataPelajaran) => $this->isDummyText($mataPelajaran->nama_pelajaran))
                        ->values()
                    : collect();

                $isDummyClass = $this->isDummyKelas($kelas);

                if (! $isDummyClass && $students->isEmpty() && $subjects->isEmpty()) {
                    return null;
                }

                return [
                    'id' => $kelas->id,
                    'tahun_ajaran_id' => $tahunAjaran?->id,
                    'semester' => $semester,
                    'label' => $kelas->label_kelas,
                    'safe' => $isDummyClass,
                    'students' => $students->map(fn (Siswa $siswa) => [
                        'id' => $siswa->id,
                        'label' => "{$siswa->nama} ({$siswa->nis})",
                    ])->all(),
                    'subjects' => $subjects->map(fn (MataPelajaran $mataPelajaran) => [
                        'id' => $mataPelajaran->id,
                        'label' => $mataPelajaran->nama_pelajaran,
                    ])->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function queueHealthData(): array
    {
        return [
            'pending_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            'worker_reminder' => 'Pastikan queue worker database berjalan dengan --queue=pdf,default saat simulasi PDF.',
        ];
    }

    private function validatedDummyContext(int $tahunAjaranId, int $kelasId, int $siswaId): array
    {
        $tahunAjaran = TahunAjaran::findOrFail($tahunAjaranId);
        $kelas = Kelas::query()
            ->whereKey($kelasId)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->firstOrFail();
        $siswa = Siswa::findOrFail($siswaId);

        if (! $this->isDummyKelas($kelas) || ! $this->isDummySiswa($siswa)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Simulasi hanya boleh memakai kelas dan siswa dummy/test/simulasi.',
            ], 422));
        }

        $isEnrolled = app(SiswaKelasSemesterResolver::class)
            ->isEnrolledInClass($siswa->id, $kelas->id, $tahunAjaran->id, (int) $tahunAjaran->semester, true);

        if (! $isEnrolled) {
            abort(response()->json([
                'success' => false,
                'message' => 'Siswa dummy tidak terdaftar pada konteks kelas/tahun/semester yang dipilih.',
            ], 422));
        }

        return [
            'tahun_ajaran' => $tahunAjaran,
            'kelas' => $kelas,
            'siswa' => $siswa,
        ];
    }

    private function waliForClass(Kelas $kelas): ?Guru
    {
        $guruId = DB::table('guru_kelas')
            ->where('kelas_id', $kelas->id)
            ->where('is_wali_kelas', true)
            ->where('role', 'wali_kelas')
            ->value('guru_id');

        return $guruId ? Guru::find($guruId) : null;
    }

    private function withTemporaryGuruContext(int $guruId, string $role, int $tahunAjaranId, Closure $callback)
    {
        $oldSelectedRole = session('selected_role');
        $oldTahunAjaranId = session('tahun_ajaran_id');
        $hadSelectedRole = session()->has('selected_role');
        $hadTahunAjaran = session()->has('tahun_ajaran_id');

        Auth::guard('guru')->onceUsingId($guruId);
        session([
            'selected_role' => $role,
            'tahun_ajaran_id' => $tahunAjaranId,
        ]);

        try {
            return $callback();
        } finally {
            $hadSelectedRole
                ? session(['selected_role' => $oldSelectedRole])
                : session()->forget('selected_role');

            $hadTahunAjaran
                ? session(['tahun_ajaran_id' => $oldTahunAjaranId])
                : session()->forget('tahun_ajaran_id');

            if (method_exists(Auth::guard('guru'), 'forgetUser')) {
                Auth::guard('guru')->forgetUser();
            }
        }
    }

    private function isDummyKelas(Kelas $kelas): bool
    {
        return $this->isDummyText($kelas->nama_kelas)
            || $this->isDummyText((string) $kelas->nomor_kelas)
            || $this->isDummyText($kelas->label_kelas);
    }

    private function isDummySiswa(Siswa $siswa): bool
    {
        return $this->isDummyText($siswa->nama)
            || $this->isDummyText($siswa->nis)
            || $this->isDummyText((string) $siswa->nisn);
    }

    private function isDummyText(?string $value): bool
    {
        $value = mb_strtolower((string) $value, 'UTF-8');

        if ($value === '') {
            return false;
        }

        foreach ((array) config('staging_test_tools.dummy_markers', []) as $marker) {
            if ($marker !== '' && str_contains($value, mb_strtolower((string) $marker, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }
}
