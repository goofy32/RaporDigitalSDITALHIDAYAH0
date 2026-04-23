<?php

use App\Http\Controllers\AbsensiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SchoolProfileController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\AchievementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ScoreController;
use App\Http\Controllers\TujuanPembelajaranController;
use App\Http\Controllers\EkstrakurikulerController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TahunAjaranController;
use App\Http\Controllers\GeminiChatController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\KkmController;
use App\Http\Controllers\BobotNilaiController;
use App\Http\Controllers\KenaikanKelasController;
use App\Http\Controllers\CatatanController;
use App\Http\Controllers\CapaianKompetensiController;
use App\Http\Controllers\CapaianRangeTemplateController;
use App\Models\Siswa;
use App\Models\FormatRapor;
use App\Models\Kelas;
use Illuminate\Support\Facades\Auth;

// Root route with role-based redirection
Route::get('/', function () {
    if (Auth::guard('web')->check()) {
        return redirect()->route('admin.dashboard');
    } elseif (Auth::guard('guru')->check()) {
        $selectedRole = session('selected_role');
        
        // Tambahkan pengecekan role yang valid
        if (!in_array($selectedRole, ['guru', 'wali_kelas'])) {
            Auth::guard('guru')->logout();
            return redirect()->route('login')
                ->with('error', 'Sesi tidak valid. Silakan login kembali.');
        }
        
        return $selectedRole === 'wali_kelas' 
            ? redirect()->route('wali_kelas.dashboard')
            : redirect()->route('pengajar.dashboard');
    }
    return redirect()->route('login');
});

Route::fallback(function () {
    if (Auth::guard('web')->check()) {
        return redirect()->route('admin.dashboard');
    }

    if (Auth::guard('guru')->check()) {
        $selectedRole = session('selected_role');
        
        if ($selectedRole === 'wali_kelas') {
            return redirect()->route('wali_kelas.dashboard');
        } else if ($selectedRole === 'guru') {
            return redirect()->route('pengajar.dashboard');
        }
    }
    
    return redirect()->route('login');
});

Route::get('/api/session-config', function () {
    // Manual check both guards
    $isAuthenticated = Auth::guard('web')->check() || Auth::guard('guru')->check();
    
    if (!$isAuthenticated) {
        return response()->json([
            'lifetime' => 7200000, // 2 hours default
            'debug' => false,
            'guest' => true
        ]);
    }
    
    return response()->json([
        'lifetime' => config('session.lifetime') * 60 * 1000,
        'lifetimeMinutes' => config('session.lifetime'),
        'debug' => config('app.debug'),
        'checkInterval' => 30000,
        'timestamp' => time(),
        'authenticated' => true
    ]);
})->middleware(['web']);

Route::get('/notifications/unread-count', function () {
    if (Auth::guard('guru')->check()) {
        $guru = Auth::guard('guru')->user();
        $role = session('selected_role');

        $count = \App\Models\Notification::where(function($query) use ($guru, $role) {
            $query->where('target', 'all')
                  ->orWhere('target', $role)
                  ->orWhere(function($q) use ($guru) {
                      $q->where('target', 'specific')
                        ->whereJsonContains('specific_users', (int) $guru->id);
                  });
        })
        ->whereDoesntHave('readers', function($query) use ($guru) {
            $query->where('guru_id', $guru->id);
        })
        ->count();

        return response()->json(['count' => $count]);
    }

    if (Auth::guard('web')->check()) {
        return response()->json(['count' => 0]);
    }

    return response()->json(['count' => 0], 401);
})->middleware(['web']);


// Login Routes
Route::middleware(['web', 'guest'])->group(function () {
    Route::get('login', function () {
        // Cek jika user sudah login
        if (Auth::guard('web')->check()) {
            return redirect()->route('admin.dashboard');
        }
        
        if (Auth::guard('guru')->check()) {
            $selectedRole = session('selected_role');
            return $selectedRole === 'wali_kelas' 
                ? redirect()->route('wali_kelas.dashboard')
                : redirect()->route('pengajar.dashboard');
        }
        
        return view('login');
    })->name('login');

    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.post');
});


Route::post('logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/admin/check-password-format/{id}', function($id) {
    $guru = \App\Models\Guru::find($id);
    if (!$guru) return 'Guru tidak ditemukan';
    
    // Cek apakah password disimpan dengan format bcrypt (dimulai dengan $2y$)
    $passwordFormat = substr($guru->password, 0, 4);
    
    return "Format password: {$passwordFormat}. " . 
        "Benar jika dimulai dengan \$2y\$ atau \$2a\$. " .
        "Password length: " . strlen($guru->password);
})->middleware(['auth:web']);


// Admin Routes - Guard: web, Role: admin only
Route::middleware(['auth:web', 'role:admin', 'check.basic.setup'])->prefix('admin')->group(function () {

    
    Route::prefix('gemini')->name('gemini.')->group(function () {
        Route::post('/send-message', [GeminiChatController::class, 'sendMessage'])->name('send');
        Route::get('/history', [GeminiChatController::class, 'getHistory'])->name('history');
        Route::post('/update-knowledge', [GeminiChatController::class, 'updateKnowledgeBase'])->name('update-knowledge');
        Route::get('/debug-test', [GeminiChatController::class, 'debugTest'])->name('debug-test');
        Route::get('/test-direct', [GeminiChatController::class, 'testGeminiDirectly'])->name('test-direct');
        Route::delete('/clear-history', [GeminiChatController::class, 'clearHistory'])->name('clear-history');
        Route::delete('/chat/{id}', [GeminiChatController::class, 'deleteChat'])->name('delete-chat');
        Route::get('/test-db', [GeminiChatController::class, 'testDatabaseConnection'])->name('test-db');
        Route::get('/test-intent', [GeminiChatController::class, 'testIntentAnalysis'])->name('test-intent');
        Route::get('/test-data', [GeminiChatController::class, 'testDataFetching'])->name('test-data');
        Route::get('/debug-nilai', [GeminiChatController::class, 'debugNilaiData'])->name('gemini.debug-nilai');
        Route::get('/auto-switch-tahun', [GeminiChatController::class, 'autoSwitchTahunAjaran'])->name('gemini.auto-switch');
        Route::delete('/clear-conversation', [GeminiChatController::class, 'resetConversation'])->name('reset-conversation');
    });

    Route::get('/admin/gemini/test-database', function() {
        try {
            $tahunAjaranId = session('tahun_ajaran_id');
            
            // Test basic data
            $nilaiCount = \App\Models\Nilai::where('tahun_ajaran_id', $tahunAjaranId)
                ->whereNotNull('nilai_akhir_rapor')
                ->count();
                
            $siswaCount = \App\Models\Siswa::whereHas('kelas', function($q) use ($tahunAjaranId) {
                $q->where('tahun_ajaran_id', $tahunAjaranId);
            })->count();
            
            $kelasCount = \App\Models\Kelas::where('tahun_ajaran_id', $tahunAjaranId)->count();
            
            $mataPelajaranCount = \App\Models\MataPelajaran::where('tahun_ajaran_id', $tahunAjaranId)->count();
            
            // Test user role
            $userRole = 'unknown';
            if (Auth::guard('web')->check()) {
                $userRole = 'admin';
            } elseif (Auth::guard('guru')->check()) {
                $userRole = session('selected_role') === 'wali_kelas' ? 'wali_kelas' : 'guru';
            }
            
            return response()->json([
                'success' => true,
                'tahun_ajaran_id' => $tahunAjaranId,
                'user_role' => $userRole,
                'data_counts' => [
                    'nilai' => $nilaiCount,
                    'siswa' => $siswaCount,
                    'kelas' => $kelasCount,
                    'mata_pelajaran' => $mataPelajaranCount
                ],
                'sample_nilai' => \App\Models\Nilai::where('tahun_ajaran_id', $tahunAjaranId)
                    ->with(['siswa', 'mataPelajaran'])
                    ->whereNotNull('nilai_akhir_rapor')
                    ->limit(3)
                    ->get()
                    ->map(function($nilai) {
                        return [
                            'siswa' => $nilai->siswa->nama ?? 'N/A',
                            'mata_pelajaran' => $nilai->mataPelajaran->nama_pelajaran ?? 'N/A',
                            'nilai' => $nilai->nilai_akhir_rapor
                        ];
                    })
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    })->middleware(['auth:web']);

    Route::prefix('kkm')->name('admin.kkm.')->group(function() {
        Route::get('/', [KkmController::class, 'index'])->name('index');
        Route::post('/', [KkmController::class, 'store'])->name('store');
        Route::get('/list', [KkmController::class, 'getKkmList'])->name('list');
        // Route baru untuk KKM massal
        Route::post('/global', [KkmController::class, 'applyGlobalKkm'])->name('global');
        Route::delete('/{id}', [KkmController::class, 'destroy'])->name('destroy');
        Route::get('/notification-settings', [KkmController::class, 'getNotificationSettings'])
        ->name('notification-settings.get');
        Route::post('/notification-settings', [KkmController::class, 'saveNotificationSettings'])
            ->name('notification-settings.save');
    });

    Route::get('/tujuan-pembelajaran/{id}/check-dependencies', [TujuanPembelajaranController::class, 'checkDependencies'])
    ->name('tujuan_pembelajaran.check_dependencies');
    
    Route::get('/set-semester/{tahunAjaranId}/{semester}', [TahunAjaranController::class, 'setSessionSemester'])
    ->name('tahun.ajaran.set-semester');
    // Bobot Nilai Routes
    Route::prefix('bobot-nilai')->name('admin.bobot_nilai.')->group(function() {
        Route::get('/', [BobotNilaiController::class, 'index'])->name('index');
        Route::post('/', [BobotNilaiController::class, 'update'])->name('update');
        Route::get('/data', [BobotNilaiController::class, 'getBobot'])->name('data');
    });
    Route::post('/report-history/regenerate/{report}', [ReportController::class, 'regenerateHistoryRapor'])
    ->name('admin.report.history.regenerate');

    Route::get('/report-history/preview/{report}', [ReportController::class, 'previewHistoryRapor'])
    ->name('admin.report.history.preview');

    // Endpoint untuk mendapatkan data kelas
    Route::get('/kelas/data', function() {
        $tahunAjaranId = session('tahun_ajaran_id');
        $kelas = Kelas::with(['mataPelajarans' => function($query) use ($tahunAjaranId) {
            $query->where('tahun_ajaran_id', $tahunAjaranId);
        }])->where('tahun_ajaran_id', $tahunAjaranId)->get();
        
        return response()->json(['kelas' => $kelas]);
    })->name('kelas.data');


    Route::get('/set-tahun-ajaran/{id}', function($id) {
        session(['tahun_ajaran_id' => $id]);
        return redirect()->back()->with('success', 'Tahun ajaran berhasil diubah');
    })->name('tahun.ajaran.set-session');
        // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'adminDashboard'])->name('admin.dashboard');
    Route::get('/kelas-progress/{id}', [DashboardController::class, 'getKelasProgressAdmin'])
        ->name('admin.kelas.progress');
    
    // Information/Notifications
    Route::prefix('information')->name('information.')->group(function () {
        Route::post('/', [NotificationController::class, 'store'])->name('store');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
        Route::get('/list', [NotificationController::class, 'list'])->name('list');
    });

    Route::prefix('audit')->name('admin.audit.')->group(function () {
        Route::get('/', [AuditController::class, 'index'])->name('index');
        Route::get('/export', [AuditController::class, 'export'])->name('export');
        Route::post('/clear', [AuditController::class, 'clear'])->name('clear');
        Route::get('/{auditLog}', [AuditController::class, 'show'])->name('show');
    });
    
    // Profile Routes
    Route::get('profile', [SchoolProfileController::class, 'show'])->name('profile');
    Route::get('profile/edit', [SchoolProfileController::class, 'edit'])->name('profile.edit');
    Route::post('profile', [SchoolProfileController::class, 'store'])->name('profile.submit');
    
    // Student Management
    Route::resource('students', StudentController::class)->names([
        'index' => 'student',
        'create' => 'student.create',
        'store' => 'student.store',
        'show' => 'student.show',
        'edit' => 'student.edit',
        'update' => 'student.update',
        'destroy' => 'student.destroy',
    ]);
    
    Route::get('template/student', [StudentController::class, 'downloadTemplate'])->name('student.template');
    Route::get('students/upload', [StudentController::class, 'uploadPage'])->name('student.upload');
    Route::post('students/import', [StudentController::class, 'importExcel'])->name('student.import');

    // Subject Settings Routes - harus di atas resource route untuk menghindari konflik
    Route::get('subject/bobot-nilai', [BobotNilaiController::class, 'subjectView'])->name('admin.subject.bobot-nilai');
    Route::get('subject/kkm', [KkmController::class, 'subjectView'])->name('admin.subject.kkm');
    
    // Subject Routes
    Route::resource('subject', SubjectController::class);

    // Class Management
    Route::get('kelas', [ClassController::class, 'index'])->name('kelas.index');
    Route::get('kelas/create', [ClassController::class, 'create'])->name('kelas.create');
    Route::post('kelas', [ClassController::class, 'store'])->name('kelas.store');
    Route::get('kelas/{id}', [ClassController::class, 'show'])->name('kelas.show');
    Route::get('kelas/{id}/edit', [ClassController::class, 'edit'])->name('kelas.edit');
    Route::put('kelas/{id}', [ClassController::class, 'update'])->name('kelas.update');
    Route::delete('kelas/{id}', [ClassController::class, 'destroy'])->name('kelas.destroy');
    
    Route::get('/set-semester/{tahunAjaranId}/{semester}', [TahunAjaranController::class, 'setSessionSemester'])
        ->name('admin.set-semester');

    Route::prefix('tahun-ajaran')->name('tahun.ajaran.')->group(function () {
        // Route yang sudah ada...
        Route::post('/{id}/restore', [TahunAjaranController::class, 'restore'])->name('restore'); 
        Route::get('/', [TahunAjaranController::class, 'index'])->name('index');
        Route::get('/create', [TahunAjaranController::class, 'create'])->name('create');
        Route::post('/', [TahunAjaranController::class, 'store'])->name('store');
        Route::get('/{id}', [TahunAjaranController::class, 'show'])->name('show');
        Route::get('/{id}/edit', [TahunAjaranController::class, 'edit'])->name('edit');
        Route::put('/{id}', [TahunAjaranController::class, 'update'])->name('update');
        Route::post('{id}/set-active', [TahunAjaranController::class, 'setActive'])->name('set-active');
        Route::get('/{id}/copy', [TahunAjaranController::class, 'copy'])->name('copy');
        Route::post('/{id}/copy', [TahunAjaranController::class, 'processCopy'])->name('process-copy');
        Route::delete('/{id}', [TahunAjaranController::class, 'destroy'])->name('destroy');
        Route::delete('/{id}/force-delete', [TahunAjaranController::class, 'forceDelete'])->name('force-delete');
        Route::get('tahun-ajaran/check-semester-genap', [TahunAjaranController::class, 'checkSemesterGenap'])->name('tahun.ajaran.check.semester.genap');
        // Route baru untuk sistem semester dengan snapshot
        Route::post('/{id}/advance-semester', [TahunAjaranController::class, 'advanceToNextSemester'])
            ->name('advance-semester');
        Route::post('/{id}/return-semester', [TahunAjaranController::class, 'returnToSemester1'])
            ->name('return-semester');
        Route::get('/{id}/snapshots', [TahunAjaranController::class, 'getSnapshots'])
            ->name('get-snapshots');
        Route::post('/{id}/create-snapshot', [TahunAjaranController::class, 'createManualSnapshot'])
            ->name('create-snapshot');

    });
    
    // Teacher Management
    Route::prefix('pengajar')->group(function () {
        Route::get('/', [TeacherController::class, 'index'])->name('teacher');
        Route::get('/create', [TeacherController::class, 'create'])->name('teacher.create');
        Route::post('/store', [TeacherController::class, 'store'])->name('teacher.store');
        Route::get('/{id}', [TeacherController::class, 'show'])->name('teacher.show');
        Route::get('/{id}/edit', [TeacherController::class, 'edit'])->name('teacher.edit');
        Route::put('/{id}', [TeacherController::class, 'update'])->name('teacher.update');
        Route::delete('/{id}', [TeacherController::class, 'destroy'])->name('teacher.destroy');
        Route::get('/{id}/password', [TeacherController::class, 'showPassword'])
        ->name('teacher.show_password');
        
        // Tambahkan rute baru di sini
        Route::post('/verify-password', [TeacherController::class, 'verifyPassword'])
            ->name('teacher.verify-password');
    });
    
    Route::prefix('kenaikan-kelas')->name('admin.kenaikan-kelas.')->group(function () {
        Route::get('/', [KenaikanKelasController::class, 'index'])->name('index');
        Route::get('/kelas/{id}', [KenaikanKelasController::class, 'showKelasSiswa'])->name('show-siswa');
        Route::post('/kenaikan', [KenaikanKelasController::class, 'processKenaikanKelas'])->name('process-kenaikan');
        Route::post('/tinggal', [KenaikanKelasController::class, 'processTinggalKelas'])->name('process-tinggal');
        Route::post('/kelulusan', [KenaikanKelasController::class, 'processKelulusan'])->name('process-kelulusan');
        Route::post('/mass-promotion', [KenaikanKelasController::class, 'processMassPromotion'])->name('process-mass');
    });

    // Achievement Routes
    Route::resource('achievement', AchievementController::class)->names([
        'index' => 'achievement.index',
        'create' => 'achievement.create',
        'store' => 'achievement.store',
        'edit' => 'achievement.edit',
        'update' => 'achievement.update',
        'destroy' => 'achievement.destroy',
    ]);
    
    // Learning Objectives
    Route::prefix('tujuan-pembelajaran')->name('tujuan_pembelajaran.')->group(function () {
        Route::get('/create/{mata_pelajaran_id}', [TujuanPembelajaranController::class, 'create'])->name('create');
        Route::post('/store', [TujuanPembelajaranController::class, 'store'])->name('store');
        Route::get('/{mata_pelajaran_id}', [TujuanPembelajaranController::class, 'view'])->name('view');
        Route::get('/{mata_pelajaran_id}/list', [TujuanPembelajaranController::class, 'listByMataPelajaran'])->name('list');
        Route::delete('/{id}', [TujuanPembelajaranController::class, 'destroy'])->name('destroy');
    });
    
    // Extracurricular
    Route::resource('ekstrakulikuler', EkstrakurikulerController::class)->names([
        'index' => 'ekstra.index',
        'create' => 'ekstra.create',
        'store' => 'ekstra.store',
        'edit' => 'ekstra.edit',
        'update' => 'ekstra.update',
        'destroy' => 'ekstra.destroy',
    ]);

    Route::get('/report-history', [ReportController::class, 'history'])->name('admin.report.history');
    Route::get('/report-history/download/{report}', [ReportController::class, 'downloadHistory'])->name('admin.report.history.download');
    
    Route::get('/report-template/tutorial', [ReportController::class, 'tutorialView'])
    ->name('report.template.tutorial');
    // Report Format
    Route::prefix('report-template')->name('report.template.')->group(function () {
        // Sample template download route
        Route::get('/sample', [ReportController::class, 'downloadSampleTemplate'])
        ->name('sample');
        Route::get('/placeholder-guide', [ReportController::class, 'placeholderGuide'])
            ->name('placeholder.guide');
        Route::get('/current', [ReportController::class, 'getCurrentTemplate'])
            ->name('current');
        Route::post('/upload', [ReportController::class, 'upload'])
            ->name('upload');
        
        Route::get('/{template}/download', [ReportController::class, 'downloadTemplate'])
        ->name('download');
        Route::get('/{template}/preview', [ReportController::class, 'preview'])
            ->name('preview');
        // Tambahkan route baru untuk preview dengan docx.js
        Route::get('/{template}/preview-data', [ReportController::class, 'previewData'])
            ->name('preview-data');
        Route::post('/{template}/activate', [ReportController::class, 'activate'])
            ->name('activate');
        Route::delete('/{template}', [ReportController::class, 'destroy'])
            ->name('destroy');
        Route::get('/{type?}', [ReportController::class, 'index'])
            ->name('index');
    });
});

    // Pengajar Routes - Guard: guru, Role: guru
    Route::middleware(['auth:guru', 'role:guru'])
        ->prefix('pengajar')
        ->name('pengajar.')  // Tambahkan ini untuk name prefix
        ->group(function () {

    Route::get('/mata-pelajaran-progress/{mataPelajaranId}', [DashboardController::class, 'getMataPelajaranProgress'])
        ->name('mata_pelajaran.progress');

    Route::prefix('gemini')->name('gemini.')->group(function () {
        Route::post('/send-message', [GeminiChatController::class, 'sendMessage'])->name('send');
        Route::get('/history', [GeminiChatController::class, 'getHistory'])->name('history');

        Route::delete('/clear-history', [GeminiChatController::class, 'clearHistory'])->name('clear-history');
        Route::delete('/chat/{id}', [GeminiChatController::class, 'deleteChat'])->name('delete-chat');
    });

    Route::get('/check-access/{mapelId}', function($mapelId) {
        $guru = Auth::guard('guru')->user();
        $mapel = \App\Models\MataPelajaran::find($mapelId);
        
        return [
            'guru_id' => $guru->id,
            'guru_name' => $guru->nama,
            'guru_role' => $guru->jabatan,
            'is_wali_kelas' => $guru->isWaliKelas(),
            'mapel_id' => $mapel->id,
            'mapel_name' => $mapel->nama_pelajaran,
            'mapel_guru_id' => $mapel->guru_id,
            'tahun_ajaran_match' => $mapel->tahun_ajaran_id == session('tahun_ajaran_id'),
            'session_tahun_ajaran' => session('tahun_ajaran_id'),
            'mapel_tahun_ajaran' => $mapel->tahun_ajaran_id,
            'has_access' => $mapel->guru_id === $guru->id
        ];
    })->middleware(['auth:guru']);

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('read');
        Route::get('/unread-count', [NotificationController::class, 'getUnreadCount'])->name('unread-count');
    });

    Route::get('/nilai/kkm/{mapelId}', [KkmController::class, 'getKkm'])->name('nilai.kkm');
    Route::get('/nilai/bobot', [BobotNilaiController::class, 'getBobot'])->name('nilai.bobot');
    
    Route::get('/dashboard', [DashboardController::class, 'pengajarDashboard'])->name('dashboard');
        
        
    Route::get('/kelas-progress/overall', [DashboardController::class, 'getOverallClassProgress'])
        ->name('kelas.progress.overall');
    
    // Route baru untuk mata pelajaran
    Route::get('/kelas-progress/{id}', [DashboardController::class, 'getKelasProgress'])
        ->name('kelas.progress');

    Route::get('/profile', [TeacherController::class, 'showProfile'])->name('profile');
    
    // Score Management
    Route::prefix('score')->name('score.')->group(function () {
        Route::get('/', [ScoreController::class, 'index'])->name('index');
        Route::get('/{id}/input', [ScoreController::class, 'inputScore'])->name('input_score');
        Route::post('/{id}/save', [ScoreController::class, 'saveScore'])->name('save_scores');
        Route::get('/{id}/preview', [ScoreController::class, 'previewScore'])->name('preview_score');
        Route::delete('/{id}', [ScoreController::class, 'deleteScores'])->name('delete');
        Route::post('/score/nilai/delete', [ScoreController::class, 'deleteNilai'])->name('nilai.delete');
        Route::post('/validate', [ScoreController::class, 'validateScores'])->name('validate');
        Route::post('/get-class-subjects', [ScoreController::class, 'getClassSubjects'])->name('get_class_subjects');
    });

    // Subject Management
    Route::prefix('subject')->name('subject.')->group(function () {
        Route::get('/', [SubjectController::class, 'teacherIndex'])->name('index');
        Route::get('/create', [SubjectController::class, 'teacherCreate'])->name('create');
        Route::post('/', [SubjectController::class, 'teacherStore'])->name('store');
        Route::get('/{id}/edit', [SubjectController::class, 'teacherEdit'])->name('edit');
        Route::put('/{id}', [SubjectController::class, 'teacherUpdate'])->name('update');
        Route::delete('/{id}', [SubjectController::class, 'teacherDestroy'])->name('destroy');
        Route::delete('/lingkup-materi/{id}', [SubjectController::class, 'deleteLingkupMateri'])->name('lingkup_materi.destroy');
        Route::get('/lingkup-materi/{id}/check-dependencies', [SubjectController::class, 'checkLingkupMateriDependencies'])
        ->name('lingkup_materi.check_dependencies');
    });

    // Learning Objectives
    Route::prefix('tujuan-pembelajaran')->name('tujuan_pembelajaran.')->group(function () {
        Route::get('/create/{mata_pelajaran_id}', [TujuanPembelajaranController::class, 'teacherCreate'])->name('create');
        Route::post('/store', [TujuanPembelajaranController::class, 'teacherStore'])->name('store');
        Route::get('/{mata_pelajaran_id}', [TujuanPembelajaranController::class, 'teacherView'])->name('view');
        Route::get('/{mata_pelajaran_id}/list', [TujuanPembelajaranController::class, 'listByMataPelajaran'])->name('list');
        Route::delete('/{id}', [TujuanPembelajaranController::class, 'teacherDestroy'])->name('destroy');
    });
});

// Wali Kelas Routes - Guard: guru, Role: wali_kelas
Route::middleware(['auth:guru', 'role:wali_kelas'])
->prefix('wali-kelas')
->name('wali_kelas.')
->group(function () {

// Cetak Rapor HTML Routes
Route::prefix('rapor-html')->name('rapor_html.')->group(function () {
    // Halaman index untuk daftar siswa yang akan dicetak
    Route::get('/', [ReportController::class, 'indexPrintRapor'])->name('index');
    
    // Halaman cetak rapor HTML untuk siswa tertentu
    Route::get('/print/{siswa}', [ReportController::class, 'printRaporHtml'])->name('print');
    
    // Route alternatif dengan nama yang lebih jelas
    Route::get('/cetak/{siswa}', [ReportController::class, 'printRaporHtml'])->name('cetak');
});

// Alternative routes for rapor HTML
Route::get('/cetak-rapor', [ReportController::class, 'indexPrintRapor'])->name('rapor.print_index');
Route::get('/cetak-rapor/{siswa}', [ReportController::class, 'printRaporHtml'])->name('rapor.print_html');

Route::prefix('gemini')->name('gemini.')->group(function () {
    Route::post('/send-message', [GeminiChatController::class, 'sendMessage'])->name('send');
    Route::get('/history', [GeminiChatController::class, 'getHistory'])->name('history');
    Route::delete('/clear-history', [GeminiChatController::class, 'clearHistory'])->name('clear-history');
    Route::delete('/chat/{id}', [GeminiChatController::class, 'deleteChat'])->name('delete-chat');
});

Route::prefix('capaian-kompetensi')->name('capaian_kompetensi.')->group(function () {
    Route::get('/', [CapaianKompetensiController::class, 'waliKelasIndex'])->name('index');
    Route::get('/{mataPelajaran}/edit', [CapaianKompetensiController::class, 'waliKelasEdit'])->name('edit');
    Route::put('/{mataPelajaran}', [CapaianKompetensiController::class, 'waliKelasUpdate'])->name('update');
    
    // Route baru untuk range templates
    Route::get('/range-templates', [CapaianKompetensiController::class, 'rangeTemplates'])->name('range_templates');
    Route::put('/range-templates', [CapaianRangeTemplateController::class, 'update'])->name('range_templates.update');
    Route::post('/range-templates', [CapaianRangeTemplateController::class, 'store'])->name('range_templates.store');
    Route::delete('/range-templates/{id}', [CapaianRangeTemplateController::class, 'destroy'])->name('range_templates.destroy');
    Route::post('/range-templates/reset', [CapaianRangeTemplateController::class, 'resetToDefault'])->name('range_templates.reset');
});

Route::prefix('catatan')->name('catatan.')->group(function () {
    // Catatan Siswa
    Route::prefix('siswa')->name('siswa.')->group(function () {
        Route::get('/{siswa}', [CatatanController::class, 'showCatatanSiswa'])->name('show');
        Route::post('/{siswa}', [CatatanController::class, 'storeCatatanSiswa'])->name('store');
    });
    
    // Catatan Mata Pelajaran
    Route::prefix('mata-pelajaran')->name('mata_pelajaran.')->group(function () {
        Route::get('/', [CatatanController::class, 'indexCatatanMataPelajaran'])->name('index');
        Route::get('/{mataPelajaran}', [CatatanController::class, 'showCatatanMataPelajaran'])->name('show');
        Route::post('/{mataPelajaran}', [CatatanController::class, 'storeCatatanMataPelajaran'])->name('store');
        Route::get('/ajax/get-catatan', [CatatanController::class, 'getCatatanForSiswa'])->name('get-catatan');
    });
});

Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('read');
    Route::get('/unread-count', [NotificationController::class, 'getUnreadCount'])->name('unread-count');
});

// Diagnose route
Route::get('/rapor/diagnose/{siswa}', [ReportController::class, 'diagnoseSiswaData'])->name('diagnose');

// Dashboard
Route::get('/dashboard', [DashboardController::class, 'waliKelasDashboard'])
    ->middleware('check.wali.kelas')
    ->name('dashboard');
    
Route::get('/profile', [TeacherController::class, 'showWaliKelasProfile'])->name('profile');
Route::get('/overall-progress', [DashboardController::class, 'getOverallProgressWaliKelas'])
    ->name('overall.progress');
    
Route::get('/kelas-progress', [DashboardController::class, 'getKelasProgressWaliKelas'])
    ->name('kelas.progress');

Route::get('/mata-pelajaran-progress/{mataPelajaranId}', [DashboardController::class, 'getMataPelajaranProgressWaliKelas'])
        ->name('mata_pelajaran.progress');
        
// Student Management
Route::prefix('siswa')->name('student.')->group(function () {
    Route::get('/', [StudentController::class, 'waliKelasIndex'])->name('index');
    Route::get('/create', [StudentController::class, 'waliKelasCreate'])->name('create'); 
    Route::post('/', [StudentController::class, 'waliKelasStore'])->name('store');
    Route::get('/{id}', [StudentController::class, 'waliKelasShow'])->name('show');
    Route::get('/{id}/edit', [StudentController::class, 'waliKelasEdit'])->name('edit');
    Route::put('/{id}', [StudentController::class, 'waliKelasUpdate'])->name('update');
    Route::delete('/{id}', [StudentController::class, 'waliKelasDestroy'])->name('destroy');
});

// Extracurricular
Route::prefix('ekstrakurikuler')->name('ekstrakurikuler.')->group(function () {
    Route::get('/', [EkstrakurikulerController::class, 'waliKelasIndex'])->name('index');
    Route::get('/create', [EkstrakurikulerController::class, 'waliKelasCreate'])->name('create');
    Route::post('/', [EkstrakurikulerController::class, 'waliKelasStore'])->name('store');
    Route::get('/{id}/edit', [EkstrakurikulerController::class, 'waliKelasEdit'])->name('edit');
    Route::put('/{id}', [EkstrakurikulerController::class, 'waliKelasUpdate'])->name('update');
    Route::delete('/{id}', [EkstrakurikulerController::class, 'waliKelasDestroy'])->name('destroy');
});

// Absence Management
Route::resource('absensi', AbsensiController::class)->names([
    'index' => 'absence.index',
    'create' => 'absence.create', 
    'store' => 'absence.store',
    'edit' => 'absence.edit',
    'update' => 'absence.update',
    'destroy' => 'absence.destroy',
]);

// Learning objectives routes
Route::get('/lingkup-materi/{id}/check-dependencies', [TujuanPembelajaranController::class, 'checkLingkupMateriDependencies'])
    ->name('lingkup_materi.check_dependencies');

// Add route for updating lingkup materi (if needed)
Route::post('/lingkup-materi/{id}/update', [SubjectController::class, 'updateLingkupMateri'])
    ->name('lingkup_materi.update');
    
// Ensure this route exists for tujuan pembelajaran view
Route::get('/tujuan-pembelajaran/{mata_pelajaran_id}/view', [TujuanPembelajaranController::class, 'teacherView'])
    ->name('tujuan_pembelajaran.view');
Route::get('/test-pdf-request', function() {
    try {
        $siswa = \App\Models\Siswa::first();
        if (!$siswa) {
            return "No student found";
        }
        
        $controller = new \App\Http\Controllers\ReportController();
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'type' => 'UTS',
            'tahun_ajaran_id' => session('tahun_ajaran_id', 1)
        ]);
        
        return $controller->requestPdf($siswa, $request);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
});
// RAPOR ROUTES - Consolidated and organized
Route::prefix('rapor')->name('rapor.')->group(function () {
    Route::get('/', [ReportController::class, 'indexWaliKelas'])->name('index');
    
    // Basic rapor routes with middleware
    Route::middleware('check.rapor.access')->group(function () {
        Route::post('/generate/{siswa}', [ReportController::class, 'generateReport'])->name('generate');
        Route::get('/download/{siswa}/{type}', [ReportController::class, 'downloadReport'])->name('download');
    });

    Route::get('/preview/{siswa}', [ReportController::class, 'previewRapor'])->name('preview');
    Route::get('/check-templates', [ReportController::class, 'checkActiveTemplates'])->name('check-templates');
    Route::post('/batch-generate', [ReportController::class, 'generateBatchReport'])->name('batch.generate');
    
    Route::delete('/clear-cache/{siswa}', [ReportController::class, 'clearPdfCache'])->name('clear-cache');
    Route::post('/request-pdf/{siswa}', [ReportController::class, 'requestPdf'])->name('request-pdf');
    Route::get('/pdf-progress/{requestId}', [ReportController::class, 'checkPdfProgress'])->name('pdf-progress');

    // Cache management routes
    Route::get('/cache-stats', function() {
        return response()->json(PdfCacheService::getCacheStats());
    })->name('cache-stats');

    // PDF Routes with middleware
    Route::middleware('check.rapor.access')->group(function () {
        Route::get('/preview-pdf/{siswa}', [ReportController::class, 'previewPdf'])->name('preview-pdf');
        Route::get('/download-pdf/{siswa}', [ReportController::class, 'downloadPdf'])->name('download-pdf');
        Route::post('/generate-pdf/{siswa}', [ReportController::class, 'generatePdfDirect'])->name('generate-pdf');
    });
    
    // Batch PDF Route
    Route::post('/batch-generate-pdf', [ReportController::class, 'generateBatchPdf'])->name('batch.generate-pdf');
    
    // Testing routes (remove in production)
    Route::get('/test-pdf-conversion', [ReportController::class, 'testPdfConversion'])->name('test.pdf');
    Route::get('/conversion-status', [ReportController::class, 'getConversionStatus'])->name('conversion.status');
});
});
