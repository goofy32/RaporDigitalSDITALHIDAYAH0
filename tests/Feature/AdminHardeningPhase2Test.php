<?php

namespace Tests\Feature;

use App\Models\BobotNilai;
use App\Models\Guru;
use App\Models\ReportTemplate;
use App\Models\Setting;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AdminHardeningPhase2Test extends TestCase
{
    private User $admin;

    private Guru $guru;

    private int $activeYearId;

    private int $oldYearId;

    private int $activeClassId;

    private int $activeClassBId;

    private int $oldClassId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();
        Event::fake();

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_audit_clear_all_requires_typed_confirmation(): void
    {
        $this->insertAuditLog('before');

        $this->actingAsAdmin()
            ->from(route('admin.audit.index'))
            ->post(route('admin.audit.clear'), ['period' => 'all'])
            ->assertRedirect(route('admin.audit.index'))
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseCount('audit_logs', 1);

        $this->actingAsAdmin()
            ->from(route('admin.audit.index'))
            ->post(route('admin.audit.clear'), [
                'period' => 'all',
                'confirmation' => 'hapus',
            ])
            ->assertRedirect(route('admin.audit.index'))
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseCount('audit_logs', 1);

        $this->actingAsAdmin()
            ->post(route('admin.audit.clear'), [
                'period' => 'all',
                'confirmation' => 'HAPUS AUDIT LOG',
            ])
            ->assertRedirect(route('admin.audit.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_guru_cannot_clear_all_audit_logs(): void
    {
        $this->insertAuditLog('guarded');

        $this->actingAs($this->guru, 'guru')
            ->postJson(route('admin.audit.clear'), [
                'period' => 'all',
                'confirmation' => 'HAPUS AUDIT LOG',
            ])
            ->assertUnauthorized();

        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_recycle_bin_force_delete_all_requires_typed_confirmation(): void
    {
        $studentId = $this->insertDeletedStudent();

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete-all'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->assertNotNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete-all'), [
                'confirmation' => 'hapus',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->assertNotNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete-all'), [
                'confirmation' => 'HAPUS PERMANEN',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('siswas', ['id' => $studentId]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'permanent_purge')->where('model_type', Siswa::class)->count());

        $secondStudentId = $this->insertDeletedStudent();

        $guruId = DB::table('gurus')->insertGetId([
            'nuptk' => 'delete-all-guru',
            'nama' => 'Guru Terhapus',
            'username' => 'delete-all-guru',
            'password' => Hash::make('password'),
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete-all'), [
                'confirmation' => 'HAPUS PERMANEN',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('siswas', ['id' => $secondStudentId]);
        $this->assertDatabaseMissing('gurus', ['id' => $guruId]);
    }

    public function test_guru_cannot_force_delete_all_recycle_bin_items(): void
    {
        $this->insertDeletedStudent();

        $this->actingAs($this->guru, 'guru')
            ->deleteJson(route('admin.recycle-bin.force-delete-all'), [
                'confirmation' => 'HAPUS PERMANEN',
            ])
            ->assertUnauthorized();

        $this->assertSame(1, DB::table('siswas')->whereNotNull('deleted_at')->count());
    }

    public function test_admin_can_delete_only_active_global_uts_template(): void
    {
        $template = $this->createTemplate('UTS', true, null, 1);

        $this->actingAsAdmin()
            ->deleteJson(route('report.template.destroy', $template))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('report_templates', ['id' => $template->id]);
    }

    public function test_admin_can_deactivate_only_active_global_uas_template(): void
    {
        $template = $this->createTemplate('UAS', true, null, 2);

        $this->actingAsAdmin()
            ->postJson(route('report.template.activate', $template))
            ->assertOk()
            ->assertJsonPath('status', 'inactive');

        $this->assertDatabaseHas('report_templates', [
            'id' => $template->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_deactivate_only_active_global_uts_template(): void
    {
        $template = $this->createTemplate('UTS', true, null, 1);

        $this->actingAsAdmin()
            ->postJson(route('report.template.activate', $template))
            ->assertOk()
            ->assertJsonPath('status', 'inactive');

        $this->assertDatabaseHas('report_templates', [
            'id' => $template->id,
            'is_active' => false,
        ]);
    }

    public function test_class_scoped_template_does_not_need_to_replace_global_before_global_can_be_deactivated(): void
    {
        $globalTemplate = $this->createTemplate('UAS', true, null, 2);
        $classTemplate = $this->createTemplate('UAS', true, $this->activeClassId, 2);

        $this->actingAsAdmin()
            ->postJson(route('report.template.activate', $globalTemplate))
            ->assertOk()
            ->assertJsonPath('status', 'inactive');

        $this->assertDatabaseHas('report_templates', [
            'id' => $globalTemplate->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('report_templates', [
            'id' => $classTemplate->id,
            'is_active' => true,
        ]);
    }

    public function test_multiple_active_templates_per_report_type_are_allowed(): void
    {
        $globalTemplate = $this->createTemplate('UTS', true, null, 1);
        $classTemplate = $this->createTemplate('UTS', false, $this->activeClassId, 1);

        $this->actingAsAdmin()
            ->postJson(route('report.template.activate', $classTemplate))
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('report_templates', [
            'id' => $globalTemplate->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('report_templates', [
            'id' => $classTemplate->id,
            'is_active' => true,
        ]);
    }

    public function test_activating_uts_template_does_not_deactivate_active_uas_template(): void
    {
        $uasTemplate = $this->createTemplate('UAS', true, null, 2);
        $utsTemplate = $this->createTemplate('UTS', false, null, 1);

        $this->actingAsAdmin()
            ->postJson(route('report.template.activate', $utsTemplate))
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('report_templates', [
            'id' => $utsTemplate->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('report_templates', [
            'id' => $uasTemplate->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_set_opened_report_period_to_uts(): void
    {
        Setting::set('active_wali_report_period', 'UAS');

        $this->actingAsAdmin()
            ->post(route('report.template.opened-period.update'), [
                'opened_report_type' => 'UTS',
            ])
            ->assertRedirect(route('report.template.index'))
            ->assertSessionHas('success');

        $this->assertSame('UTS', Setting::get('active_wali_report_period'));
    }

    public function test_admin_can_set_opened_report_period_to_uas(): void
    {
        $this->actingAsAdmin()
            ->post(route('report.template.opened-period.update'), [
                'opened_report_type' => 'UAS',
            ])
            ->assertRedirect(route('report.template.index'))
            ->assertSessionHas('success');

        $this->assertSame('UAS', Setting::get('active_wali_report_period'));
    }

    public function test_successful_template_deactivate_updates_backend_active_status(): void
    {
        $template = $this->createTemplate('UAS', true, $this->activeClassId, 2);

        $this->actingAsAdmin()
            ->postJson(route('report.template.activate', $template))
            ->assertOk()
            ->assertJsonPath('status', 'inactive');

        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_student_create_and_update_require_active_year_context(): void
    {
        DB::table('tahun_ajarans')->update(['is_active' => false]);
        Cache::flush();

        $this->actingAs($this->admin, 'web')
            ->from(route('student.create'))
            ->post(route('student.store'), $this->studentPayload([
                'nis' => '12001',
                'nisn' => '1200100001',
            ]))
            ->assertRedirect(route('student.create'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('siswas', ['nis' => '12001']);

        $studentId = $this->insertStudent([
            'nis' => '12002',
            'nisn' => '1200200002',
            'nama' => 'Siswa Lama',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);

        $this->actingAs($this->admin, 'web')
            ->from(route('student.edit', $studentId))
            ->put(route('student.update', $studentId), $this->studentPayload([
                'nis' => '12002',
                'nisn' => '1200200002',
                'nama' => 'Siswa Tanpa Tahun',
            ]))
            ->assertRedirect(route('student.edit', $studentId))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('siswas', [
            'id' => $studentId,
            'nama' => 'Siswa Lama',
        ]);
    }

    public function test_manual_student_creates_and_updates_active_semester_enrollment(): void
    {
        $this->actingAsAdmin()
            ->post(route('student.store'), $this->studentPayload([
                'nis' => '13001',
                'nisn' => '1300100001',
                'nama' => 'Siswa Manual',
            ]))
            ->assertRedirect(route('student'));

        $studentId = (int) DB::table('siswas')->where('nis', '13001')->value('id');

        $this->assertDatabaseHas('siswas', [
            'id' => $studentId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);

        $this->actingAsAdmin()
            ->put(route('student.update', $studentId), $this->studentPayload([
                'nis' => '13001',
                'nisn' => '1300100001',
                'nama' => 'Siswa Manual Update',
                'kelas_id' => $this->activeClassBId,
            ]))
            ->assertRedirect(route('student'));

        $this->assertDatabaseHas('siswas', [
            'id' => $studentId,
            'kelas_id' => $this->activeClassBId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
        $this->assertSame(
            1,
            DB::table('siswa_kelas_semester')
                ->where('siswa_id', $studentId)
                ->where('tahun_ajaran_id', $this->activeYearId)
                ->where('semester', 1)
                ->count()
        );
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassBId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);

        $this->actingAsAdmin()
            ->get(route('student', ['search' => 'Manual Update']))
            ->assertOk()
            ->assertSee('Siswa Manual Update')
            ->assertSee('Kelas 2 B');
    }

    public function test_manual_student_create_rolls_back_when_active_enrollment_fails(): void
    {
        DB::statement("
            CREATE TRIGGER fail_manual_student_enrollment
            BEFORE INSERT ON siswa_kelas_semester
            BEGIN
                SELECT RAISE(ABORT, 'forced enrollment failure');
            END
        ");

        $this->actingAsAdmin()
            ->from(route('student.create'))
            ->post(route('student.store'), $this->studentPayload([
                'nis' => '13009',
                'nisn' => '1300900009',
                'nama' => 'Siswa Atomic',
            ]))
            ->assertRedirect(route('student.create'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('siswas', ['nis' => '13009']);
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_manual_student_identity_update_preserves_active_enrollment_created_at(): void
    {
        $studentId = $this->insertStudent([
            'nis' => '13015',
            'nisn' => '1301500015',
            'nama' => 'Siswa Timestamp',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'created_at' => '2025-01-01 08:00:00',
            'updated_at' => '2025-01-01 08:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2025-01-02 09:30:00'));

        try {
            $this->actingAsAdmin()
                ->put(route('student.update', $studentId), $this->studentPayload([
                    'nis' => '13015',
                    'nisn' => '1301500015',
                    'nama' => 'Siswa Timestamp Update',
                    'kelas_id' => $this->activeClassId,
                ]))
                ->assertRedirect(route('student'));
        } finally {
            Carbon::setTestNow();
        }

        $activeEnrollments = DB::table('siswa_kelas_semester')
            ->where('siswa_id', $studentId)
            ->where('tahun_ajaran_id', $this->activeYearId)
            ->where('semester', 1)
            ->get();

        $this->assertCount(1, $activeEnrollments);

        $activeEnrollment = $activeEnrollments->first();

        $this->assertSame($this->activeClassId, (int) $activeEnrollment->kelas_id);
        $this->assertSame('2025-01-01 08:00:00', (string) $activeEnrollment->created_at);
        $this->assertSame('2025-01-02 09:30:00', (string) $activeEnrollment->updated_at);
    }

    public function test_manual_student_update_only_changes_active_semester_enrollment_and_preserves_history(): void
    {
        $studentId = $this->insertStudent([
            'nis' => '13010',
            'nisn' => '1301000010',
            'nama' => 'Siswa History',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $this->oldClassId,
            'tahun_ajaran_id' => $this->oldYearId,
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->put(route('student.update', $studentId), $this->studentPayload([
                'nis' => '13010',
                'nisn' => '1301000010',
                'nama' => 'Siswa History Update',
                'kelas_id' => $this->activeClassBId,
            ]))
            ->assertRedirect(route('student'));

        $this->assertDatabaseHas('siswas', [
            'id' => $studentId,
            'kelas_id' => $this->activeClassBId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $studentId,
            'kelas_id' => $this->oldClassId,
            'tahun_ajaran_id' => $this->oldYearId,
            'semester' => 2,
        ]);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassBId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);
        $this->assertSame(
            1,
            DB::table('siswa_kelas_semester')
                ->where('siswa_id', $studentId)
                ->where('tahun_ajaran_id', $this->activeYearId)
                ->where('semester', 1)
                ->count()
        );
    }

    public function test_manual_student_update_creates_active_enrollment_for_legacy_student_without_enrollment(): void
    {
        $studentId = $this->insertStudent([
            'nis' => '13011',
            'nisn' => '1301100011',
            'nama' => 'Siswa Legacy',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);

        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('siswa_id', $studentId)->count());

        $this->actingAsAdmin()
            ->put(route('student.update', $studentId), $this->studentPayload([
                'nis' => '13011',
                'nisn' => '1301100011',
                'nama' => 'Siswa Legacy Update',
                'kelas_id' => $this->activeClassBId,
            ]))
            ->assertRedirect(route('student'));

        $this->assertDatabaseHas('siswas', [
            'id' => $studentId,
            'kelas_id' => $this->activeClassBId,
        ]);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassBId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);
    }

    public function test_student_create_rejects_class_from_another_year_context(): void
    {
        $this->actingAsAdmin()
            ->from(route('student.create'))
            ->post(route('student.store'), $this->studentPayload([
                'nis' => '13002',
                'nisn' => '1300200002',
                'kelas_id' => $this->oldClassId,
            ]))
            ->assertRedirect(route('student.create'))
            ->assertSessionHasErrors('kelas_id');

        $this->assertDatabaseMissing('siswas', ['nis' => '13002']);

        $studentId = $this->insertStudent([
            'nis' => '13012',
            'nisn' => '1301200012',
            'nama' => 'Siswa Cross Year',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);

        $this->actingAsAdmin()
            ->from(route('student.edit', $studentId))
            ->put(route('student.update', $studentId), $this->studentPayload([
                'nis' => '13012',
                'nisn' => '1301200012',
                'nama' => 'Siswa Cross Year Update',
                'kelas_id' => $this->oldClassId,
            ]))
            ->assertRedirect(route('student.edit', $studentId))
            ->assertSessionHasErrors('kelas_id');

        $this->assertDatabaseHas('siswas', [
            'id' => $studentId,
            'kelas_id' => $this->activeClassId,
        ]);
        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('siswa_id', $studentId)->count());
    }

    public function test_student_create_and_update_reject_soft_deleted_active_year_class(): void
    {
        DB::table('kelas')
            ->where('id', $this->activeClassBId)
            ->update(['deleted_at' => now()]);

        $this->actingAsAdmin()
            ->from(route('student.create'))
            ->post(route('student.store'), $this->studentPayload([
                'nis' => '13013',
                'nisn' => '1301300013',
                'nama' => 'Siswa Kelas Terhapus',
                'kelas_id' => $this->activeClassBId,
            ]))
            ->assertRedirect(route('student.create'))
            ->assertSessionHasErrors('kelas_id');

        $this->assertDatabaseMissing('siswas', ['nis' => '13013']);
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());

        $studentId = $this->insertStudent([
            'nis' => '13014',
            'nisn' => '1301400014',
            'nama' => 'Siswa Update Kelas Terhapus',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->from(route('student.edit', $studentId))
            ->put(route('student.update', $studentId), $this->studentPayload([
                'nis' => '13014',
                'nisn' => '1301400014',
                'nama' => 'Siswa Update Kelas Terhapus',
                'kelas_id' => $this->activeClassBId,
            ]))
            ->assertRedirect(route('student.edit', $studentId))
            ->assertSessionHasErrors('kelas_id');

        $this->assertDatabaseHas('siswas', [
            'id' => $studentId,
            'kelas_id' => $this->activeClassId,
        ]);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);
    }

    public function test_wali_student_update_rejects_admin_equivalent_validation_errors(): void
    {
        $studentId = $this->insertStudent([
            'nis' => '14001',
            'nisn' => '1400100001',
            'nama' => 'Siswa Wali Validasi',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
        $this->insertStudent([
            'nis' => '14002',
            'nisn' => '1400200002',
            'nama' => 'Siswa Lain',
            'kelas_id' => $this->activeClassBId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);

        $cases = [
            'nis terlalu panjang' => ['nis', ['nis' => str_repeat('1', 21)]],
            'nisn terlalu panjang' => ['nisn', ['nisn' => str_repeat('2', 21)]],
            'nis milik siswa lain' => ['nis', ['nis' => '14002']],
            'nisn milik siswa lain' => ['nisn', ['nisn' => '1400200002']],
            'jenis kelamin invalid' => ['jenis_kelamin', ['jenis_kelamin' => 'Lainnya']],
            'agama invalid' => ['agama', ['agama' => 'Tidak Valid']],
            'alamat terlalu panjang' => ['alamat', ['alamat' => str_repeat('A', 501)]],
            'file bukan gambar diperbolehkan' => ['photo', ['photo' => UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf')]],
            'gambar lebih dari 2048 KB' => ['photo', ['photo' => UploadedFile::fake()->image('besar.jpg')->size(2049)]],
        ];

        foreach ($cases as $label => [$field, $overrides]) {
            $this->actingAsWali()
                ->from(route('wali_kelas.student.edit', $studentId))
                ->put(route('wali_kelas.student.update', $studentId), $this->waliStudentPayload($overrides))
                ->assertRedirect(route('wali_kelas.student.edit', $studentId))
                ->assertSessionHasErrors($field);

            $this->assertDatabaseHas('siswas', [
                'id' => $studentId,
                'nis' => '14001',
                'nisn' => '1400100001',
                'nama' => 'Siswa Wali Validasi',
                'kelas_id' => $this->activeClassId,
            ]);
        }
    }

    public function test_wali_student_update_accepts_self_unique_values_saves_family_fields_photo_and_keeps_class(): void
    {
        Storage::fake('public');

        $studentId = $this->insertStudent([
            'nis' => '14003',
            'nisn' => '1400300003',
            'nama' => 'Siswa Wali Update',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);

        $this->actingAsWali()
            ->put(route('wali_kelas.student.update', $studentId), $this->waliStudentPayload([
                'nis' => '14003',
                'nisn' => '1400300003',
                'nama' => 'Siswa Wali Berhasil',
                'kelas_id' => $this->activeClassBId,
                'nama_ayah' => 'Ayah Baru',
                'nama_ibu' => 'Ibu Baru',
                'pekerjaan_ayah' => 'Dokter',
                'pekerjaan_ibu' => 'Perawat',
                'alamat_orangtua' => 'Alamat Orang Tua Baru',
                'wali_siswa' => 'Paman Baru',
                'pekerjaan_wali' => 'Wiraswasta',
                'photo' => UploadedFile::fake()->image('foto-siswa.jpg')->size(256),
            ]))
            ->assertRedirect(route('wali_kelas.student.index'))
            ->assertSessionHas('success');

        $student = DB::table('siswas')->where('id', $studentId)->first();

        $this->assertSame('14003', $student->nis);
        $this->assertSame('1400300003', $student->nisn);
        $this->assertSame('Siswa Wali Berhasil', $student->nama);
        $this->assertSame($this->activeClassId, (int) $student->kelas_id);
        $this->assertSame('Ayah Baru', $student->nama_ayah);
        $this->assertSame('Ibu Baru', $student->nama_ibu);
        $this->assertSame('Dokter', $student->pekerjaan_ayah);
        $this->assertSame('Perawat', $student->pekerjaan_ibu);
        $this->assertSame('Alamat Orang Tua Baru', $student->alamat_orangtua);
        $this->assertSame('Paman Baru', $student->wali_siswa);
        $this->assertSame('Wiraswasta', $student->pekerjaan_wali);
        $this->assertNotNull($student->photo);
        Storage::disk('public')->assertExists($student->photo);
    }

    public function test_admin_student_update_still_accepts_valid_shared_rules_and_photo(): void
    {
        Storage::fake('public');

        $studentId = $this->insertStudent([
            'nis' => '14004',
            'nisn' => '1400400004',
            'nama' => 'Siswa Admin Update',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);

        $this->actingAsAdmin()
            ->put(route('student.update', $studentId), $this->studentPayload([
                'nis' => '14004',
                'nisn' => '1400400004',
                'nama' => 'Siswa Admin Berhasil',
                'agama' => 'Konghucu',
                'kelas_id' => $this->activeClassBId,
                'wali_siswa' => 'Wali Admin',
                'pekerjaan_wali' => 'Pedagang',
                'photo' => UploadedFile::fake()->image('foto-admin.png')->size(256),
            ]))
            ->assertRedirect(route('student'))
            ->assertSessionHas('success');

        $student = DB::table('siswas')->where('id', $studentId)->first();

        $this->assertSame('Siswa Admin Berhasil', $student->nama);
        $this->assertSame('Konghucu', $student->agama);
        $this->assertSame($this->activeClassBId, (int) $student->kelas_id);
        $this->assertSame('Wali Admin', $student->wali_siswa);
        $this->assertSame('Pedagang', $student->pekerjaan_wali);
        $this->assertNotNull($student->photo);
        Storage::disk('public')->assertExists($student->photo);
    }

    public function test_wali_student_edit_form_renders_shared_validation_contract(): void
    {
        $this->actingAsWali();

        $html = view('wali_kelas.edit_student', [
            'errors' => (new ViewErrorBag())->put('default', new MessageBag([
                'wali_siswa' => ['Nama wali terlalu panjang.'],
                'pekerjaan_wali' => ['Pekerjaan wali terlalu panjang.'],
                'photo' => ['Foto maksimal 2 MB.'],
            ])),
            'student' => (object) [
                'id' => 10,
                'nis' => '1001',
                'nisn' => '2001',
                'nama' => 'Siswa Contoh',
                'tanggal_lahir' => '2015-01-01',
                'jenis_kelamin' => 'Laki-laki',
                'agama' => 'Islam',
                'alamat' => 'Alamat',
                'photo' => null,
                'nama_ayah' => 'Ayah',
                'nama_ibu' => 'Ibu',
                'pekerjaan_ayah' => null,
                'pekerjaan_ibu' => null,
                'alamat_orangtua' => null,
                'wali_siswa' => null,
                'pekerjaan_wali' => null,
            ],
            'kelas' => (object) [
                'id' => $this->activeClassId,
                'nomor_kelas' => 1,
                'nama_kelas' => 'A',
            ],
        ])->render();

        $this->assertMatchesRegularExpression('/name="nis"[^>]*maxlength="20"/', $html);
        $this->assertMatchesRegularExpression('/name="nisn"[^>]*maxlength="20"/', $html);
        $this->assertMatchesRegularExpression('/name="nama"[^>]*maxlength="255"/', $html);
        $this->assertMatchesRegularExpression('/name="alamat"[^>]*maxlength="500"/', $html);
        $this->assertMatchesRegularExpression('/name="nama_ayah"[^>]*maxlength="255"/', $html);
        $this->assertMatchesRegularExpression('/name="pekerjaan_ayah"[^>]*maxlength="100"/', $html);
        $this->assertMatchesRegularExpression('/name="wali_siswa"[^>]*maxlength="255"/', $html);
        $this->assertMatchesRegularExpression('/name="pekerjaan_wali"[^>]*maxlength="100"/', $html);
        $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $html);
        $this->assertStringContainsString('Format JPG, JPEG, PNG, atau WEBP. Ukuran maksimal 2 MB.', $html);
        $this->assertStringContainsString('Nama wali terlalu panjang.', $html);
        $this->assertStringContainsString('Pekerjaan wali terlalu panjang.', $html);
        $this->assertStringContainsString('Foto maksimal 2 MB.', $html);
        $this->assertSame(1, substr_count($html, '>Update</button>'));
        $this->assertSame(1, substr_count($html, '>Kembali</a>'));
    }

    public function test_valid_kkm_batch_save_accepts_class_subject_active_year(): void
    {
        $subjectId = $this->insertSubject($this->activeClassId, $this->activeYearId, 'Matematika');

        $this->actingAsAdmin()
            ->postJson(route('admin.kkm.batch-save'), [
                'kelas_id' => $this->activeClassId,
                'tahun_ajaran_id' => $this->activeYearId,
                'items' => [
                    ['mata_pelajaran_id' => $subjectId, 'nilai' => 78],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('kkms', [
            'kelas_id' => $this->activeClassId,
            'mata_pelajaran_id' => $subjectId,
            'tahun_ajaran_id' => $this->activeYearId,
            'nilai' => 78,
        ]);
    }

    public function test_kkm_batch_save_rejects_class_from_another_year_context(): void
    {
        $subjectId = $this->insertSubject($this->oldClassId, $this->oldYearId, 'IPAS Lama');

        $this->actingAsAdmin()
            ->postJson(route('admin.kkm.batch-save'), [
                'kelas_id' => $this->oldClassId,
                'tahun_ajaran_id' => $this->activeYearId,
                'items' => [
                    ['mata_pelajaran_id' => $subjectId, 'nilai' => 80],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('kkms', 0);
    }

    public function test_kkm_batch_save_rejects_subject_not_assigned_to_selected_class_year(): void
    {
        $subjectId = $this->insertSubject($this->activeClassBId, $this->activeYearId, 'Bahasa Indonesia');

        $this->actingAsAdmin()
            ->postJson(route('admin.kkm.batch-save'), [
                'kelas_id' => $this->activeClassId,
                'tahun_ajaran_id' => $this->activeYearId,
                'items' => [
                    ['mata_pelajaran_id' => $subjectId, 'nilai' => 82],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Mata pelajaran tidak tersedia untuk kelas dan tahun ajaran yang dipilih.']);

        $this->assertDatabaseCount('kkms', 0);
    }

    public function test_kkm_store_rejects_subject_from_another_year_context(): void
    {
        $subjectId = $this->insertSubject($this->oldClassId, $this->oldYearId, 'PJOK Lama');

        $this->actingAsAdmin()
            ->postJson(route('admin.kkm.store'), [
                'mata_pelajaran_id' => $subjectId,
                'nilai' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('kkms', 0);
    }

    public function test_bobot_nilai_is_ratio_based_and_does_not_require_total_100(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.bobot_nilai.update'), [
                'bobot_tp' => 1,
                'bobot_lm' => 1,
                'bobot_as' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $bobot = BobotNilai::where('tahun_ajaran_id', $this->activeYearId)->firstOrFail();

        $this->assertSame(4, $bobot->getTotal());
        $this->assertSame(25.0, $bobot->getTpPercentage());
        $this->assertSame(50.0, $bobot->getAsPercentage());
    }

    public function test_bobot_get_endpoint_returns_default_ratio_without_creating_row(): void
    {
        $this->assertDatabaseCount('bobot_nilais', 0);

        $this->actingAsAdmin()
            ->getJson(route('admin.bobot_nilai.data'))
            ->assertOk()
            ->assertJson([
                'bobot_tp' => 1,
                'bobot_lm' => 1,
                'bobot_as' => 2,
            ]);

        $this->assertDatabaseCount('bobot_nilais', 0);

        $this->actingAsAdmin()
            ->getJson(route('admin.bobot_nilai.data'))
            ->assertOk()
            ->assertJson([
                'bobot_tp' => 1,
                'bobot_lm' => 1,
                'bobot_as' => 2,
            ]);

        $this->assertDatabaseCount('bobot_nilais', 0);
    }

    public function test_bobot_get_endpoint_returns_existing_persisted_bobot(): void
    {
        DB::table('bobot_nilais')->insert([
            'tahun_ajaran_id' => $this->activeYearId,
            'bobot_tp' => 2,
            'bobot_lm' => 3,
            'bobot_as' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->getJson(route('admin.bobot_nilai.data'))
            ->assertOk()
            ->assertJson([
                'bobot_tp' => 2,
                'bobot_lm' => 3,
                'bobot_as' => 4,
            ]);

        $this->assertDatabaseCount('bobot_nilais', 1);
    }

    public function test_explicit_bobot_save_creates_persisted_row_and_audit_log(): void
    {
        $this->assertDatabaseCount('bobot_nilais', 0);

        $this->actingAsAdmin()
            ->postJson(route('admin.bobot_nilai.update'), [
                'bobot_tp' => 1,
                'bobot_lm' => 1,
                'bobot_as' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('bobot_nilais', 1);
        $bobot = BobotNilai::where('tahun_ajaran_id', $this->activeYearId)->firstOrFail();

        $this->assertSame(1, $bobot->bobot_tp);
        $this->assertSame(1, $bobot->bobot_lm);
        $this->assertSame(2, $bobot->bobot_as);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => 'App\Models\BobotNilai',
            'model_id' => $bobot->id,
            'action' => 'update',
        ]);
    }

    public function test_explicit_bobot_save_updates_existing_row_without_duplicate(): void
    {
        $bobotId = DB::table('bobot_nilais')->insertGetId([
            'tahun_ajaran_id' => $this->activeYearId,
            'bobot_tp' => 1,
            'bobot_lm' => 1,
            'bobot_as' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->postJson(route('admin.bobot_nilai.update'), [
                'bobot_tp' => 2,
                'bobot_lm' => 3,
                'bobot_as' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('bobot_nilais', 1);
        $this->assertDatabaseHas('bobot_nilais', [
            'id' => $bobotId,
            'tahun_ajaran_id' => $this->activeYearId,
            'bobot_tp' => 2,
            'bobot_lm' => 3,
            'bobot_as' => 4,
        ]);
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs($this->admin, 'web')->withSession($this->adminSession());
    }

    private function actingAsWali(): self
    {
        return $this->actingAs($this->guru, 'guru')->withSession(array_merge($this->adminSession(), [
            'selected_role' => 'wali_kelas',
        ]));
    }

    private function adminSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->activeYearId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ];
    }

    private function studentPayload(array $overrides = []): array
    {
        return array_merge([
            'nis' => '11001',
            'nisn' => '1100100001',
            'nama' => 'Siswa Baru',
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Jl. Testing',
            'kelas_id' => $this->activeClassId,
            'nama_ayah' => 'Ayah Testing',
            'nama_ibu' => 'Ibu Testing',
            'pekerjaan_ayah' => 'Guru',
            'pekerjaan_ibu' => 'Guru',
            'alamat_orangtua' => 'Jl. Orang Tua',
            'wali_siswa' => '',
            'pekerjaan_wali' => '',
        ], $overrides);
    }

    private function waliStudentPayload(array $overrides = []): array
    {
        return $this->studentPayload($overrides);
    }

    private function createTemplate(string $type, bool $isActive, ?int $kelasId, ?int $semester): ReportTemplate
    {
        return ReportTemplate::create([
            'filename' => strtolower($type) . '-' . uniqid('', true) . '.docx',
            'path' => 'templates/' . strtolower($type) . '-' . uniqid('', true) . '.docx',
            'type' => $type,
            'is_active' => $isActive,
            'tahun_ajaran' => '2025/2026',
            'tahun_ajaran_text' => '2025/2026',
            'semester' => $semester,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
    }

    private function insertAuditLog(string $description): void
    {
        DB::table('audit_logs')->insert([
            'user_type' => User::class,
            'user_id' => $this->admin->id,
            'action' => 'test',
            'model_type' => null,
            'model_id' => null,
            'description' => $description,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDeletedStudent(): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => '90001',
            'nisn' => '9000100001',
            'nama' => 'Siswa Terhapus',
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Jl. Dihapus',
            'kelas_id' => $this->activeClassId,
            'nama_ayah' => 'Ayah',
            'nama_ibu' => 'Ibu',
            'pekerjaan_ayah' => '',
            'pekerjaan_ibu' => '',
            'alamat_orangtua' => '',
            'photo' => null,
            'wali_siswa' => '',
            'pekerjaan_wali' => '',
            'tahun_ajaran_id' => $this->activeYearId,
            'status' => 'aktif',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(array $attributes): int
    {
        return DB::table('siswas')->insertGetId(array_merge([
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Jl. Testing',
            'nama_ayah' => 'Ayah Testing',
            'nama_ibu' => 'Ibu Testing',
            'pekerjaan_ayah' => '',
            'pekerjaan_ibu' => '',
            'alamat_orangtua' => '',
            'photo' => null,
            'wali_siswa' => '',
            'pekerjaan_wali' => '',
            'status' => 'aktif',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function insertSubject(int $kelasId, int $tahunAjaranId, string $name): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $kelasId,
            'guru_id' => $this->guru->id,
            'semester' => 1,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'tahun_ajaran_id' => $tahunAjaranId,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'bobot_nilais',
            'kkms',
            'report_template_kelas',
            'report_templates',
            'settings',
            'siswa_kelas_semester',
            'siswas',
            'absensis',
            'prestasis',
            'ekstrakurikulers',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'mata_pelajarans',
            'guru_kelas',
            'kelas',
            'gurus',
            'audit_logs',
            'profil_sekolah',
            'tahun_ajarans',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('no_handphone')->nullable();
            $table->string('email')->nullable()->unique();
            $table->text('alamat')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->string('photo')->nullable();
            $table->string('signature_path')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('guru_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guru_id');
            $table->foreignId('kelas_id');
            $table->boolean('is_wali_kelas')->default(false);
            $table->string('role')->default('pengajar');
            $table->timestamps();
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->string('judul_lingkup_materi');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->string('kode_tp')->nullable();
            $table->text('deskripsi_tp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama_ekstrakurikuler');
            $table->string('pembina')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prestasis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->string('jenis_prestasi');
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->unsignedTinyInteger('sakit')->default(0);
            $table->unsignedTinyInteger('izin')->default(0);
            $table->unsignedTinyInteger('tanpa_keterangan')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->unique();
            $table->string('nisn')->unique();
            $table->string('nama');
            $table->date('tanggal_lahir')->nullable();
            $table->string('jenis_kelamin')->nullable();
            $table->string('agama')->nullable();
            $table->text('alamat')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->string('nama_ayah')->nullable();
            $table->string('nama_ibu')->nullable();
            $table->string('pekerjaan_ayah')->nullable();
            $table->string('pekerjaan_ibu')->nullable();
            $table->text('alamat_orangtua')->nullable();
            $table->string('photo')->nullable();
            $table->string('wali_siswa')->nullable();
            $table->string('pekerjaan_wali')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->unsignedTinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path')->nullable();
            $table->string('type');
            $table->boolean('is_active')->default(false);
            $table->string('tahun_ajaran')->nullable();
            $table->string('tahun_ajaran_text')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('report_template_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id');
            $table->foreignId('kelas_id');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id');
            $table->unsignedTinyInteger('nilai');
            $table->timestamps();
        });

        Schema::create('bobot_nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->unsignedTinyInteger('bobot_tp')->default(1);
            $table->unsignedTinyInteger('bobot_lm')->default(1);
            $table->unsignedTinyInteger('bobot_as')->default(2);
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin Test',
            'username' => 'admin_test',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->guru = Guru::create([
            'nama' => 'Guru Test',
            'username' => 'guru_test',
            'email' => 'guru@example.test',
            'password' => Hash::make('password'),
            'jabatan' => 'guru_wali',
        ]);

        $this->activeYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'is_active' => true,
            'semester' => 1,
            'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2026-06-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2024/2025',
            'is_active' => false,
            'semester' => 2,
            'tanggal_mulai' => '2024-07-01',
            'tanggal_selesai' => '2025-06-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Test',
            'tahun_pelajaran' => '2025/2026',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->activeClassId = $this->insertClass(1, 'A', $this->activeYearId);
        $this->activeClassBId = $this->insertClass(2, 'B', $this->activeYearId);
        $this->oldClassId = $this->insertClass(1, 'Lama', $this->oldYearId);

        DB::table('guru_kelas')->insert([
            'guru_id' => $this->guru->id,
            'kelas_id' => $this->activeClassId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClass(int $number, string $name, int $yearId): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
