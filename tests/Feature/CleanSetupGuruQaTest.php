<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CleanSetupGuruQaTest extends TestCase
{
    private User $admin;

    private int $yearId;

    private int $kelas5AId;

    private int $kelas5BId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_creating_guru_with_empty_nuptk_saves_null_and_wali_responsibility(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'budi_clean',
                'email' => 'budi.clean@example.test',
                'jabatan' => 'guru_wali',
                'wali_kelas_id' => $this->kelas5AId,
            ]))
            ->assertRedirect(route('teacher'));

        $guru = Guru::where('username', 'budi_clean')->firstOrFail();

        $this->assertNull($guru->nuptk);
        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $guru->id,
            'kelas_id' => $this->kelas5AId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher'))
            ->assertOk()
            ->assertSee('Wali Kelas:')
            ->assertSee('5A');
    }

    public function test_updating_guru_with_empty_nuptk_saves_null_and_pengajar_responsibility(): void
    {
        $guruId = $this->insertGuru('Guru Yusuf', 'yusuf_clean', '900000001');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('teacher.update', $guruId), $this->teacherPayload([
                'nuptk' => '',
                'nama' => 'Guru Yusuf',
                'username' => 'yusuf_clean',
                'email' => 'yusuf.clean@example.test',
                'jabatan' => 'guru',
                'kelas_ids' => [$this->kelas5BId],
                'password' => null,
                'password_confirmation' => null,
            ]))
            ->assertRedirect(route('teacher'));

        $guru = Guru::findOrFail($guruId);

        $this->assertNull($guru->nuptk);
        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $guru->id,
            'kelas_id' => $this->kelas5BId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher'))
            ->assertOk()
            ->assertSee('Mengajar:')
            ->assertSee('Kelas 5B');
    }

    public function test_admin_can_create_guru_with_valid_16_digit_nuptk(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'valid_nuptk_teacher',
                'nuptk' => '1234567890123456',
            ]))
            ->assertRedirect(route('teacher'));

        $this->assertDatabaseHas('gurus', [
            'username' => 'valid_nuptk_teacher',
            'nuptk' => '1234567890123456',
        ]);
    }

    public function test_admin_cannot_create_guru_with_15_digit_nuptk(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.create'))
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'short_nuptk_teacher',
                'nuptk' => '123456789012345',
            ]))
            ->assertRedirect(route('teacher.create'))
            ->assertSessionHasErrors(['nuptk' => 'NUPTK harus 16 digit angka']);

        $this->assertDatabaseMissing('gurus', ['username' => 'short_nuptk_teacher']);
    }

    public function test_admin_cannot_create_guru_with_17_digit_nuptk(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.create'))
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'long_nuptk_teacher',
                'nuptk' => '12345678901234567',
            ]))
            ->assertRedirect(route('teacher.create'))
            ->assertSessionHasErrors(['nuptk' => 'NUPTK harus 16 digit angka']);

        $this->assertDatabaseMissing('gurus', ['username' => 'long_nuptk_teacher']);
    }

    public function test_admin_cannot_create_guru_with_non_numeric_nuptk(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.create'))
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'non_numeric_nuptk_teacher',
                'nuptk' => 'abcdefghijklmnop',
            ]))
            ->assertRedirect(route('teacher.create'))
            ->assertSessionHasErrors(['nuptk' => 'NUPTK harus 16 digit angka']);

        $this->assertDatabaseMissing('gurus', ['username' => 'non_numeric_nuptk_teacher']);
    }

    public function test_duplicate_guru_nuptk_is_rejected_when_filled(): void
    {
        $this->insertGuru('Guru Existing NUPTK', 'existing_nuptk_teacher', '2345678901234567');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.create'))
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'duplicate_nuptk_teacher',
                'nuptk' => '2345678901234567',
            ]))
            ->assertRedirect(route('teacher.create'))
            ->assertSessionHasErrors(['nuptk' => 'NUPTK sudah digunakan']);

        $this->assertDatabaseMissing('gurus', ['username' => 'duplicate_nuptk_teacher']);
    }

    public function test_guru_update_nuptk_unique_rule_ignores_current_guru_but_rejects_other_guru_nuptk(): void
    {
        $currentGuruId = $this->insertGuru('Guru Current NUPTK', 'current_nuptk_teacher', '3456789012345678');
        $otherGuruId = $this->insertGuru('Guru Other NUPTK', 'other_nuptk_teacher', '4567890123456789');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('teacher.update', $currentGuruId), $this->teacherPayload([
                'nama' => 'Guru Current NUPTK',
                'username' => 'current_nuptk_teacher',
                'nuptk' => '3456789012345678',
                'jabatan' => 'guru',
                'kelas_ids' => [$this->kelas5BId],
                'password' => null,
                'password_confirmation' => null,
            ]))
            ->assertRedirect(route('teacher'));

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.edit', $currentGuruId))
            ->put(route('teacher.update', $currentGuruId), $this->teacherPayload([
                'nama' => 'Guru Current NUPTK',
                'username' => 'current_nuptk_teacher',
                'nuptk' => '4567890123456789',
                'jabatan' => 'guru',
                'kelas_ids' => [$this->kelas5BId],
                'password' => null,
                'password_confirmation' => null,
            ]))
            ->assertRedirect(route('teacher.edit', $currentGuruId))
            ->assertSessionHasErrors(['nuptk' => 'NUPTK sudah digunakan']);

        $this->assertSame('3456789012345678', DB::table('gurus')->where('id', $currentGuruId)->value('nuptk'));
        $this->assertSame('4567890123456789', DB::table('gurus')->where('id', $otherGuruId)->value('nuptk'));
    }

    public function test_admin_can_create_guru_without_private_profile_fields(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'optional_profile_create',
                'tanggal_lahir' => '',
                'no_handphone' => '',
                'email' => '',
                'jabatan' => 'guru_wali',
                'wali_kelas_id' => $this->kelas5AId,
            ]))
            ->assertRedirect(route('teacher'));

        $guru = Guru::where('username', 'optional_profile_create')->firstOrFail();

        $this->assertNull($guru->tanggal_lahir);
        $this->assertNull($guru->no_handphone);
        $this->assertNull($guru->email);
    }

    public function test_admin_can_update_guru_and_leave_private_profile_fields_empty(): void
    {
        $guruId = $this->insertGuru('Guru Optional Update', 'optional_profile_update', '900000002');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('teacher.update', $guruId), $this->teacherPayload([
                'nama' => 'Guru Optional Update',
                'username' => 'optional_profile_update',
                'tanggal_lahir' => '',
                'no_handphone' => '',
                'email' => '',
                'jabatan' => 'guru',
                'kelas_ids' => [$this->kelas5BId],
                'password' => null,
                'password_confirmation' => null,
            ]))
            ->assertRedirect(route('teacher'));

        $guru = Guru::findOrFail($guruId);

        $this->assertNull($guru->tanggal_lahir);
        $this->assertNull($guru->no_handphone);
        $this->assertNull($guru->email);
    }

    public function test_admin_can_create_guru_without_alamat(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'optional_address_create',
                'alamat' => '',
                'jabatan' => 'guru_wali',
                'wali_kelas_id' => $this->kelas5AId,
            ]))
            ->assertRedirect(route('teacher'));

        $guru = Guru::where('username', 'optional_address_create')->firstOrFail();

        $this->assertNull($guru->alamat);
    }

    public function test_admin_can_update_guru_with_empty_alamat(): void
    {
        $guruId = $this->insertGuru('Guru Optional Address', 'optional_address_update', '900000007');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('teacher.update', $guruId), $this->teacherPayload([
                'nama' => 'Guru Optional Address',
                'username' => 'optional_address_update',
                'alamat' => '',
                'jabatan' => 'guru',
                'kelas_ids' => [$this->kelas5BId],
                'password' => null,
                'password_confirmation' => null,
            ]))
            ->assertRedirect(route('teacher'));

        $guru = Guru::findOrFail($guruId);

        $this->assertNull($guru->alamat);
    }

    public function test_invalid_guru_email_is_rejected_when_filled(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.create'))
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'invalid_email_teacher',
                'email' => 'bukan-email',
            ]))
            ->assertRedirect(route('teacher.create'))
            ->assertSessionHasErrors(['email' => 'Format email tidak valid']);

        $this->assertDatabaseMissing('gurus', ['username' => 'invalid_email_teacher']);
    }

    public function test_duplicate_guru_email_is_rejected_when_filled(): void
    {
        $this->insertGuru('Guru Existing Email', 'existing_email_teacher', '900000003');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.create'))
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'duplicate_email_teacher',
                'email' => 'existing_email_teacher@example.test',
            ]))
            ->assertRedirect(route('teacher.create'))
            ->assertSessionHasErrors(['email' => 'Email sudah digunakan']);

        $this->assertDatabaseMissing('gurus', ['username' => 'duplicate_email_teacher']);
    }

    public function test_guru_update_email_unique_rule_ignores_current_guru_but_rejects_other_guru_email(): void
    {
        $currentGuruId = $this->insertGuru('Guru Current Email', 'current_email_teacher', '900000004');
        $otherGuruId = $this->insertGuru('Guru Other Email', 'other_email_teacher', '900000005');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('teacher.update', $currentGuruId), $this->teacherPayload([
                'nama' => 'Guru Current Email',
                'username' => 'current_email_teacher',
                'email' => 'current_email_teacher@example.test',
                'jabatan' => 'guru',
                'kelas_ids' => [$this->kelas5BId],
                'password' => null,
                'password_confirmation' => null,
            ]))
            ->assertRedirect(route('teacher'));

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.edit', $currentGuruId))
            ->put(route('teacher.update', $currentGuruId), $this->teacherPayload([
                'nama' => 'Guru Current Email',
                'username' => 'current_email_teacher',
                'email' => 'other_email_teacher@example.test',
                'jabatan' => 'guru',
                'kelas_ids' => [$this->kelas5BId],
                'password' => null,
                'password_confirmation' => null,
            ]))
            ->assertRedirect(route('teacher.edit', $currentGuruId))
            ->assertSessionHasErrors(['email' => 'Email sudah digunakan']);

        $this->assertSame('current_email_teacher@example.test', DB::table('gurus')->where('id', $currentGuruId)->value('email'));
        $this->assertSame('other_email_teacher@example.test', DB::table('gurus')->where('id', $otherGuruId)->value('email'));
    }

    public function test_guru_login_still_works_with_username_when_email_is_empty(): void
    {
        $this->withMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');

        $guruId = $this->insertGuru('Guru Login Optional Email', 'login_optional_email', '900000006');
        DB::table('gurus')->where('id', $guruId)->update(['email' => null]);
        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $this->kelas5BId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession(['_token' => 'login-request-token'])
            ->post(route('login.post'), [
                '_token' => 'login-request-token',
                'username' => 'login_optional_email',
                'password' => 'password',
            ])->assertRedirect(route('pengajar.dashboard'));

        $this->assertAuthenticatedAs(Guru::findOrFail($guruId), 'guru');
    }

    public function test_guru_birth_date_must_be_before_today_with_indonesian_message(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.create'))
            ->post(route('teacher.store'), $this->teacherPayload([
                'tanggal_lahir' => now()->toDateString(),
                'username' => 'today_teacher',
                'email' => 'today.teacher@example.test',
            ]))
            ->assertRedirect(route('teacher.create'))
            ->assertSessionHasErrors(['tanggal_lahir' => 'Tanggal lahir harus sebelum hari ini.']);

        $this->assertDatabaseMissing('gurus', ['username' => 'today_teacher']);
    }

    public function test_student_birth_date_must_be_before_today_with_indonesian_message(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('student.create'))
            ->post(route('student.store'), [
                'nis' => '12345',
                'nisn' => '1234567890',
                'nama' => 'Siswa Hari Ini',
                'tanggal_lahir' => now()->toDateString(),
                'jenis_kelamin' => 'Laki-laki',
                'agama' => 'Islam',
                'alamat' => 'Jl. Demo',
                'kelas_id' => $this->kelas5AId,
                'nama_ayah' => 'Ayah Demo',
                'nama_ibu' => 'Ibu Demo',
            ])
            ->assertRedirect(route('student.create'))
            ->assertSessionHasErrors(['tanggal_lahir' => 'Tanggal lahir harus sebelum hari ini.']);

        $this->assertDatabaseMissing('siswas', ['nis' => '12345']);
    }

    public function test_duplicate_student_unique_fields_show_indonesian_messages_on_create(): void
    {
        $this->insertStudent('12345', '1234567890', $this->kelas5AId, 'Siswa Lama');

        $response = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('student.create'))
            ->post(route('student.store'), $this->studentPayload([
                'nis' => '12345',
                'nisn' => '1234567890',
                'nama' => 'Siswa Baru',
            ]));

        $response
            ->assertRedirect(route('student.create'))
            ->assertSessionHasErrors([
                'nis' => 'NIS sudah digunakan.',
                'nisn' => 'NISN sudah digunakan.',
            ]);

        $this->assertNotContains('validation.unique', session('errors')->getBag('default')->all());
        $this->assertDatabaseMissing('siswas', ['nama' => 'Siswa Baru']);
    }

    public function test_duplicate_student_unique_fields_show_indonesian_messages_on_update(): void
    {
        $this->insertStudent('12345', '1234567890', $this->kelas5AId, 'Siswa Lama');
        $targetId = $this->insertStudent('54321', '0987654321', $this->kelas5BId, 'Siswa Target');

        $response = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('student.edit', $targetId))
            ->put(route('student.update', $targetId), $this->studentPayload([
                'nis' => '12345',
                'nisn' => '1234567890',
                'nama' => 'Siswa Target',
                'kelas_id' => $this->kelas5BId,
            ]));

        $response
            ->assertRedirect(route('student.edit', $targetId))
            ->assertSessionHasErrors([
                'nis' => 'NIS sudah digunakan.',
                'nisn' => 'NISN sudah digunakan.',
            ]);

        $this->assertNotContains('validation.unique', session('errors')->getBag('default')->all());
        $this->assertSame('54321', DB::table('siswas')->where('id', $targetId)->value('nis'));
    }

    public function test_admin_student_create_rejects_class_from_another_academic_year(): void
    {
        $oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldClassId = $this->insertClass(5, 'Old', $oldYearId);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('student.create'))
            ->post(route('student.store'), $this->studentPayload([
                'kelas_id' => $oldClassId,
            ]))
            ->assertRedirect(route('student.create'))
            ->assertSessionHasErrors('kelas_id');

        $this->assertDatabaseMissing('siswas', ['nis' => '12345']);
    }

    public function test_admin_student_update_rejects_class_from_another_academic_year(): void
    {
        $targetId = $this->insertStudent('54321', '0987654321', $this->kelas5AId, 'Siswa Target');
        $oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldClassId = $this->insertClass(5, 'Old', $oldYearId);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('student.edit', $targetId))
            ->put(route('student.update', $targetId), $this->studentPayload([
                'nis' => '54321',
                'nisn' => '0987654321',
                'nama' => 'Siswa Target',
                'kelas_id' => $oldClassId,
            ]))
            ->assertRedirect(route('student.edit', $targetId))
            ->assertSessionHasErrors('kelas_id');

        $this->assertSame($this->kelas5AId, (int) DB::table('siswas')->where('id', $targetId)->value('kelas_id'));
    }

    public function test_admin_teacher_create_rejects_class_from_another_academic_year(): void
    {
        $oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldClassId = $this->insertClass(5, 'Old', $oldYearId);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.create'))
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'wrong_year_teacher',
                'email' => 'wrong.year.teacher@example.test',
                'jabatan' => 'guru',
                'kelas_ids' => [$oldClassId],
            ]))
            ->assertRedirect(route('teacher.create'))
            ->assertSessionHasErrors('kelas_ids.0');

        $this->assertDatabaseMissing('gurus', ['username' => 'wrong_year_teacher']);
    }

    public function test_admin_teacher_update_rejects_wali_class_from_another_academic_year(): void
    {
        $guruId = $this->insertGuru('Guru Tahun Salah', 'guru_tahun_salah', null);
        $oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldClassId = $this->insertClass(5, 'Old', $oldYearId);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.edit', $guruId))
            ->put(route('teacher.update', $guruId), $this->teacherPayload([
                'nama' => 'Guru Tahun Salah',
                'username' => 'guru_tahun_salah',
                'email' => 'guru_tahun_salah@example.test',
                'jabatan' => 'guru_wali',
                'wali_kelas_id' => $oldClassId,
                'password' => null,
                'password_confirmation' => null,
            ]))
            ->assertRedirect(route('teacher.edit', $guruId))
            ->assertSessionHasErrors('wali_kelas_id');

        $this->assertDatabaseMissing('guru_kelas', [
            'guru_id' => $guruId,
            'kelas_id' => $oldClassId,
        ]);
    }

    public function test_admin_subject_create_rejects_class_from_another_academic_year(): void
    {
        $guruId = $this->insertGuru('Guru Mapel', 'guru_mapel', null);
        $oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldClassId = $this->insertClass(5, 'Old', $oldYearId);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('subject.create'))
            ->post(route('subject.store'), [
                'subjects' => [
                    [
                        'mata_pelajaran' => 'IPA',
                        'kelas' => $oldClassId,
                        'guru_pengampu' => $guruId,
                        'semester' => 1,
                        'teaching_type' => 'specialist',
                        'lingkup_materi' => ['Makhluk Hidup'],
                    ],
                ],
            ])
            ->assertRedirect(route('subject.create'))
            ->assertSessionHasErrors('subjects.0.kelas');

        $this->assertDatabaseMissing('mata_pelajarans', ['nama_pelajaran' => 'IPA']);
    }

    public function test_admin_subject_update_rejects_class_from_another_academic_year(): void
    {
        $guruId = $this->insertGuru('Guru Mapel Update', 'guru_mapel_update', null);
        $subjectId = $this->insertSubject('IPA', $this->kelas5AId, $guruId);
        $oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldClassId = $this->insertClass(5, 'Old', $oldYearId);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('subject.edit', $subjectId))
            ->put(route('subject.update', $subjectId), [
                'mata_pelajaran' => 'IPA',
                'kelas' => $oldClassId,
                'guru_pengampu' => $guruId,
                'semester' => 1,
                'teaching_type' => 'specialist',
                'lingkup_materi' => ['Makhluk Hidup'],
            ])
            ->assertRedirect(route('subject.edit', $subjectId))
            ->assertSessionHasErrors('kelas');

        $this->assertSame($this->kelas5AId, (int) DB::table('mata_pelajarans')->where('id', $subjectId)->value('kelas_id'));
    }

    public function test_teacher_create_form_uses_clear_labels_and_date_limit(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher.create'))
            ->assertOk()
            ->assertSee('Tanggung Jawab Guru')
            ->assertSee('Pilih kelas wali')
            ->assertSee('Kelas yang diajar sebagai pengajar khusus/muatan lokal')
            ->assertSee('accept="image/jpeg,image/png,image/webp"', false)
            ->assertSee('Format JPG, JPEG, PNG, atau WebP. Maksimal 2 MB.')
            ->assertSee('max="'.now()->subDay()->format('Y-m-d').'"', false);
    }

    public function test_teacher_create_rejects_oversized_photo_with_indonesian_message(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.create'))
            ->post(route('teacher.store'), $this->teacherPayload([
                'username' => 'photo_large_create',
                'email' => 'photo.large.create@example.test',
                'photo' => UploadedFile::fake()->image('foto-guru.jpg')->size(2500),
            ]))
            ->assertRedirect(route('teacher.create'))
            ->assertSessionHasErrors(['photo' => 'Ukuran foto guru maksimal 2 MB.']);

        $this->assertDatabaseMissing('gurus', ['username' => 'photo_large_create']);
    }

    public function test_teacher_update_rejects_oversized_photo_with_indonesian_message(): void
    {
        $guruId = $this->insertGuru('Guru Foto', 'guru_foto', null);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.edit', $guruId))
            ->put(route('teacher.update', $guruId), $this->teacherPayload([
                'nama' => 'Guru Foto',
                'username' => 'guru_foto',
                'email' => 'guru.foto@example.test',
                'jabatan' => 'guru',
                'kelas_ids' => [$this->kelas5BId],
                'password' => null,
                'password_confirmation' => null,
                'photo' => UploadedFile::fake()->image('foto-guru.png')->size(2500),
            ]))
            ->assertRedirect(route('teacher.edit', $guruId))
            ->assertSessionHasErrors(['photo' => 'Ukuran foto guru maksimal 2 MB.']);

        $this->assertNull(Guru::findOrFail($guruId)->photo);
    }

    public function test_class_forms_no_longer_render_wali_selector_but_guru_form_still_does(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('kelas.create'))
            ->assertOk()
            ->assertSee('Nomor Kelas')
            ->assertSee('Nama Kelas')
            ->assertDontSee('Wali Kelas (Opsional)')
            ->assertDontSee('Pilih Wali Kelas')
            ->assertDontSee('name="wali_kelas_id"', false);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('kelas.edit', $this->kelas5AId))
            ->assertOk()
            ->assertDontSee('Wali Kelas (Opsional)')
            ->assertDontSee('Pilih Wali Kelas')
            ->assertDontSee('name="wali_kelas_id"', false);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher.create'))
            ->assertOk()
            ->assertSee('Pilih kelas wali')
            ->assertSee('name="wali_kelas_id"', false);
    }

    public function test_class_store_ignores_legacy_wali_field(): void
    {
        $guruId = $this->insertGuru('Guru Calon Wali', 'calon_wali', null);
        DB::table('gurus')->where('id', $guruId)->update(['jabatan' => 'guru_wali']);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->post(route('kelas.store'), [
                'nomor_kelas' => 6,
                'nama_kelas' => 'A',
                'tahun_ajaran_id' => $this->yearId,
                'target_tahun_ajaran_id' => $this->yearId,
                'wali_kelas_id' => $guruId,
            ])
            ->assertRedirect(route('kelas.index'));

        $newClassId = (int) DB::table('kelas')
            ->where('nomor_kelas', 6)
            ->where('nama_kelas', 'A')
            ->value('id');

        $this->assertGreaterThan(0, $newClassId);
        $this->assertDatabaseMissing('guru_kelas', [
            'guru_id' => $guruId,
            'kelas_id' => $newClassId,
            'role' => 'wali_kelas',
        ]);
    }

    public function test_editing_regular_pengajar_without_wali_assignment_does_not_crash(): void
    {
        $guruId = $this->insertGuru('Guru Yusuf', 'yusuf_no_wali', null);

        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $this->kelas5BId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher.edit', $guruId))
            ->assertOk()
            ->assertSee('Pengajar Biasa')
            ->assertSee('Kelas 5B')
            ->assertSee('accept="image/jpeg,image/png,image/webp"', false)
            ->assertSee('Format JPG, JPEG, PNG, atau WebP. Maksimal 2 MB.');
    }

    public function test_editing_wali_guru_still_loads_current_wali_assignment(): void
    {
        $guruId = $this->insertGuru('Guru Wali', 'wali_clean', null);
        DB::table('gurus')->where('id', $guruId)->update(['jabatan' => 'guru_wali']);

        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $this->kelas5AId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher.edit', $guruId))
            ->assertOk()
            ->assertSee('Saat ini menjadi wali kelas')
            ->assertSee('Kelas 5A');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function teacherPayload(array $overrides = []): array
    {
        return array_merge([
            'nuptk' => '',
            'nama' => 'Guru Demo',
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => '1990-01-01',
            'no_handphone' => '081234567890',
            'email' => 'guru.demo@example.test',
            'alamat' => 'Jl. Demo',
            'jabatan' => 'guru_wali',
            'wali_kelas_id' => $this->kelas5AId,
            'username' => 'guru_demo',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function studentPayload(array $overrides = []): array
    {
        return array_merge([
            'nis' => '12345',
            'nisn' => '1234567890',
            'nama' => 'Siswa Demo',
            'tanggal_lahir' => '2016-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Jl. Demo',
            'kelas_id' => $this->kelas5AId,
            'nama_ayah' => 'Ayah Demo',
            'nama_ibu' => 'Ibu Demo',
        ], $overrides);
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'siswas',
            'mata_pelajarans',
            'guru_kelas',
            'kelas',
            'profil_sekolah',
            'tahun_ajarans',
            'gurus',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
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
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->nullable()->unique();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('no_handphone')->nullable();
            $table->string('email')->nullable()->unique();
            $table->text('alamat')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->string('password_plain')->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->integer('semester')->default(1);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->integer('nomor_kelas');
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
            $table->integer('semester')->default(1);
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
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
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Demo Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->yearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->kelas5AId = $this->insertClass(5, 'A');
        $this->kelas5BId = $this->insertClass(5, 'B');

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClass(int $number, string $name, ?int $yearId = null): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $yearId ?? $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertGuru(string $name, string $username, ?string $nuptk): int
    {
        return DB::table('gurus')->insertGetId([
            'nuptk' => $nuptk,
            'nama' => $name,
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => '1990-01-01',
            'no_handphone' => '081234567890',
            'email' => "{$username}@example.test",
            'alamat' => 'Jl. Demo',
            'jabatan' => 'guru',
            'username' => $username,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(string $nis, string $nisn, int $kelasId, string $name): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $name,
            'tanggal_lahir' => '2016-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Jl. Demo',
            'kelas_id' => $kelasId,
            'nama_ayah' => 'Ayah Demo',
            'nama_ibu' => 'Ibu Demo',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubject(string $name, int $kelasId, int $guruId): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $kelasId,
            'guru_id' => $guruId,
            'semester' => 1,
            'is_muatan_lokal' => false,
            'allow_non_wali' => true,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ];
    }
}
