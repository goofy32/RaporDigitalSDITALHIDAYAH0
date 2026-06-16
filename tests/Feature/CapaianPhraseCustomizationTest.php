<?php

namespace Tests\Feature;

use App\Http\Controllers\CapaianKompetensiController;
use App\Models\Guru;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CapaianPhraseCustomizationTest extends TestCase
{
    private Guru $wali;

    private int $ganjilYearId;

    private int $genapYearId;

    private int $oldYearId;

    private int $ganjilClassId;

    private int $genapClassId;

    private int $otherClassId;

    private int $oldClassId;

    private int $mathGanjilSubjectId;

    private int $mathGenapSubjectId;

    private int $scienceSubjectId;

    private int $otherClassSubjectId;

    private int $oldYearSubjectId;

    private int $ahmadId;

    private int $sitiId;

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

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_existing_full_custom_capaian_tertinggi_still_wins_over_generated_text(): void
    {
        $this->insertFullCustom($this->ahmadId, $this->mathGanjilSubjectId, 'Ahmad memiliki uraian tertinggi khusus.', null);

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad memiliki uraian tertinggi khusus.', $result['tertinggi']);
    }

    public function test_existing_full_custom_capaian_terendah_still_wins(): void
    {
        $this->insertFullCustom($this->ahmadId, $this->mathGanjilSubjectId, null, 'Ahmad memiliki uraian terendah khusus.');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad memiliki uraian terendah khusus.', $result['terendah']);
    }

    public function test_existing_automatic_highest_lowest_lm_generation_remains_unchanged_when_no_new_settings_exist(): void
    {
        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman dalam Bilangan kuat.', $result['tertinggi']);
        $this->assertSame('Ahmad Fauzan berkembang dalam Pecahan dasar.', $result['terendah']);
    }

    public function test_zero_final_grade_is_still_treated_as_valid_where_existing_behavior_requires_it(): void
    {
        DB::table('nilais')
            ->where('siswa_id', $this->ahmadId)
            ->where('mata_pelajaran_id', $this->mathGanjilSubjectId)
            ->update(['nilai_akhir_rapor' => 0]);

        $result = CapaianKompetensiController::generateCapaianForRapor(
            $this->ahmadId,
            $this->mathGanjilSubjectId,
            $this->ganjilYearId
        );

        $this->assertNotSame('Nilai belum tersedia.', $result);
        $this->assertStringContainsString('perlu meningkatkan', $result);
    }

    public function test_null_final_grade_remains_excluded_where_existing_behavior_requires_it(): void
    {
        DB::table('nilais')
            ->where('siswa_id', $this->ahmadId)
            ->where('mata_pelajaran_id', $this->mathGanjilSubjectId)
            ->update(['nilai_akhir_rapor' => null]);

        $result = CapaianKompetensiController::generateCapaianForRapor(
            $this->ahmadId,
            $this->mathGanjilSubjectId,
            $this->ganjilYearId
        );

        $this->assertSame('Nilai belum tersedia.', $result);
    }

    public function test_default_tertinggi_applies_to_all_default_mode_students_in_same_context(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan penguasaan yang sangat baik dalam');

        $ahmad = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);
        $siti = $this->resolvedCapaian($this->sitiId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan penguasaan yang sangat baik dalam Bilangan kuat.', $ahmad['tertinggi']);
        $this->assertSame('Siti Aisyah menunjukkan penguasaan yang sangat baik dalam Bilangan kuat.', $siti['tertinggi']);
    }

    public function test_default_terendah_applies_to_all_default_mode_students_in_same_context(): void
    {
        $this->insertPhraseDefault('terendah', 'mulai berkembang dalam');

        $ahmad = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);
        $siti = $this->resolvedCapaian($this->sitiId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan mulai berkembang dalam Pecahan dasar.', $ahmad['terendah']);
        $this->assertSame('Siti Aisyah mulai berkembang dalam Pecahan dasar.', $siti['terendah']);
    }

    public function test_ganjil_and_genap_defaults_remain_separate(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan pemahaman dalam');
        $this->insertPhraseDefault('tertinggi', 'menunjukkan penguasaan semester genap dalam', $this->genapYearId, 2, $this->genapClassId, $this->mathGenapSubjectId);

        $ganjil = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);
        $genap = $this->resolvedCapaian($this->ahmadId, $this->mathGenapSubjectId, $this->genapYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman dalam Bilangan kuat.', $ganjil['tertinggi']);
        $this->assertSame('Ahmad Fauzan menunjukkan penguasaan semester genap dalam Geometri genap.', $genap['tertinggi']);
    }

    public function test_defaults_from_another_class_do_not_leak(): void
    {
        $this->insertPhraseDefault('tertinggi', 'frasa kelas lain dalam', $this->ganjilYearId, 1, $this->otherClassId, $this->otherClassSubjectId);

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman dalam Bilangan kuat.', $result['tertinggi']);
    }

    public function test_defaults_from_another_mata_pelajaran_do_not_leak(): void
    {
        $this->insertPhraseDefault('tertinggi', 'frasa IPAS dalam', $this->ganjilYearId, 1, $this->ganjilClassId, $this->scienceSubjectId);

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman dalam Bilangan kuat.', $result['tertinggi']);
    }

    public function test_defaults_from_another_academic_year_do_not_leak(): void
    {
        $this->insertPhraseDefault('tertinggi', 'frasa tahun lama dalam', $this->oldYearId, 1, $this->oldClassId, $this->oldYearSubjectId);

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman dalam Bilangan kuat.', $result['tertinggi']);
    }

    public function test_missing_contextual_default_falls_back_safely_to_current_hardcoded_text(): void
    {
        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman dalam Bilangan kuat.', $result['tertinggi']);
        $this->assertSame('Ahmad Fauzan berkembang dalam Pecahan dasar.', $result['terendah']);
    }

    public function test_student_can_override_tertinggi_only(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan pemahaman dalam');
        $this->insertPrefixOverride($this->ahmadId, $this->mathGanjilSubjectId, tertinggi: 'menunjukkan penguasaan pribadi dalam');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan penguasaan pribadi dalam Bilangan kuat.', $result['tertinggi']);
        $this->assertSame('Ahmad Fauzan berkembang dalam Pecahan dasar.', $result['terendah']);
    }

    public function test_student_can_override_terendah_only(): void
    {
        $this->insertPhraseDefault('terendah', 'mulai berkembang dalam');
        $this->insertPrefixOverride($this->ahmadId, $this->mathGanjilSubjectId, terendah: 'cukup berkembang dalam');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman dalam Bilangan kuat.', $result['tertinggi']);
        $this->assertSame('Ahmad Fauzan cukup berkembang dalam Pecahan dasar.', $result['terendah']);
    }

    public function test_student_can_override_both_prefixes(): void
    {
        $this->insertPrefixOverride(
            $this->ahmadId,
            $this->mathGanjilSubjectId,
            tertinggi: 'menunjukkan pemahaman khusus dalam',
            terendah: 'perlu penguatan dalam'
        );

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman khusus dalam Bilangan kuat.', $result['tertinggi']);
        $this->assertSame('Ahmad Fauzan perlu penguatan dalam Pecahan dasar.', $result['terendah']);
    }

    public function test_student_can_use_preset_prefix(): void
    {
        $this->insertPrefixOverride($this->ahmadId, $this->mathGanjilSubjectId, tertinggi: 'menunjukkan pemahaman yang sangat baik dalam', tertinggiMode: 'preset');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman yang sangat baik dalam Bilangan kuat.', $result['tertinggi']);
    }

    public function test_student_can_use_custom_prefix_text(): void
    {
        $this->insertPrefixOverride($this->ahmadId, $this->mathGanjilSubjectId, terendah: 'membutuhkan latihan terarah dalam', terendahMode: 'custom');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan membutuhkan latihan terarah dalam Pecahan dasar.', $result['terendah']);
    }

    public function test_changing_contextual_defaults_does_not_change_students_with_prefix_overrides(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan pemahaman awal dalam');
        $this->insertPrefixOverride($this->ahmadId, $this->mathGanjilSubjectId, tertinggi: 'menunjukkan pemahaman pribadi dalam');
        DB::table('capaian_phrase_defaults')->where('type', 'tertinggi')->update(['phrase' => 'menunjukkan default baru dalam']);

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman pribadi dalam Bilangan kuat.', $result['tertinggi']);
    }

    public function test_resetting_tertinggi_to_default_removes_only_tertinggi_prefix_override(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan default tertinggi dalam');
        $this->insertPrefixOverride(
            $this->ahmadId,
            $this->mathGanjilSubjectId,
            tertinggi: 'menunjukkan override tertinggi dalam',
            terendah: 'menunjukkan override terendah dalam'
        );
        DB::table('capaian_custom')->where('siswa_id', $this->ahmadId)->update([
            'tertinggi_prefix_mode' => 'default',
            'tertinggi_prefix_text' => null,
        ]);

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan default tertinggi dalam Bilangan kuat.', $result['tertinggi']);
        $this->assertSame('Ahmad Fauzan menunjukkan override terendah dalam Pecahan dasar.', $result['terendah']);
    }

    public function test_resetting_terendah_to_default_removes_only_terendah_prefix_override(): void
    {
        $this->insertPhraseDefault('terendah', 'menunjukkan default terendah dalam');
        $this->insertPrefixOverride(
            $this->ahmadId,
            $this->mathGanjilSubjectId,
            tertinggi: 'menunjukkan override tertinggi dalam',
            terendah: 'menunjukkan override terendah dalam'
        );
        DB::table('capaian_custom')->where('siswa_id', $this->ahmadId)->update([
            'terendah_prefix_mode' => 'default',
            'terendah_prefix_text' => null,
        ]);

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan override tertinggi dalam Bilangan kuat.', $result['tertinggi']);
        $this->assertSame('Ahmad Fauzan menunjukkan default terendah dalam Pecahan dasar.', $result['terendah']);
    }

    public function test_reset_does_not_delete_existing_unrelated_capaian_data(): void
    {
        $customId = $this->insertPrefixOverride($this->ahmadId, $this->mathGanjilSubjectId, tertinggi: 'menunjukkan override dalam');
        DB::table('capaian_custom')->where('id', $customId)->update([
            'custom_capaian' => 'Catatan legacy tetap ada.',
            'terendah_prefix_text' => 'frasa terendah tetap dalam',
            'tertinggi_prefix_mode' => 'default',
            'tertinggi_prefix_text' => null,
        ]);

        $this->assertDatabaseHas('capaian_custom', [
            'id' => $customId,
            'custom_capaian' => 'Catatan legacy tetap ada.',
            'terendah_prefix_text' => 'frasa terendah tetap dalam',
        ]);
    }

    public function test_existing_full_custom_text_still_wins_over_prefix_override(): void
    {
        $this->insertFullCustom($this->ahmadId, $this->mathGanjilSubjectId, 'Full custom tertinggi.', null);
        $this->insertPrefixOverride($this->ahmadId, $this->mathGanjilSubjectId, tertinggi: 'prefix yang tidak boleh menang dalam');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Full custom tertinggi.', $result['tertinggi']);
    }

    public function test_student_name_is_added_exactly_once(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan pemahaman mendalam dalam');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId)['tertinggi'];

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman mendalam dalam Bilangan kuat.', $result);
        $this->assertSame(1, substr_count($result, 'Ahmad Fauzan'));
    }

    public function test_prefix_is_added_exactly_once(): void
    {
        $prefix = 'menunjukkan penguasaan dalam';
        $this->insertPhraseDefault('tertinggi', $prefix);

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId)['tertinggi'];

        $this->assertSame(1, substr_count($result, $prefix));
    }

    public function test_lm_text_is_preserved(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan penguasaan dalam');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId)['tertinggi'];

        $this->assertStringContainsString('Bilangan kuat', $result);
    }

    public function test_no_duplicated_spaces(): void
    {
        $this->insertPhraseDefault('tertinggi', ' menunjukkan pemahaman dalam ');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId)['tertinggi'];

        $this->assertStringNotContainsString('  ', $result);
    }

    public function test_no_duplicated_final_periods(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan pemahaman dalam.');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId)['tertinggi'];

        $this->assertStringNotContainsString('..', $result);
        $this->assertStringEndsWith('.', $result);
    }

    public function test_empty_or_whitespace_only_custom_prefix_is_treated_safely(): void
    {
        $this->insertPrefixOverride($this->ahmadId, $this->mathGanjilSubjectId, tertinggi: '   ', tertinggiMode: 'custom');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan pemahaman dalam Bilangan kuat.', $result['tertinggi']);
    }

    public function test_prefix_cannot_silently_become_full_description_containing_another_student_name(): void
    {
        $this->insertPrefixOverride($this->ahmadId, $this->mathGanjilSubjectId, tertinggi: 'Siti Aisyah sudah menguasai penuh', tertinggiMode: 'custom');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertStringNotContainsString('Siti Aisyah', $result['tertinggi']);
    }

    public function test_wali_capaian_edit_preview_uses_resolved_final_text(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan penguasaan yang sangat baik dalam');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.capaian_kompetensi.edit', $this->mathGanjilSubjectId))
            ->assertOk()
            ->assertSee('Ahmad Fauzan menunjukkan penguasaan yang sangat baik dalam Bilangan kuat.');
    }

    public function test_report_preview_uses_resolved_final_text(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan penguasaan laporan dalam');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan menunjukkan penguasaan laporan dalam Bilangan kuat.', $result['tertinggi']);
    }

    public function test_html_print_uses_resolved_final_text(): void
    {
        $this->insertPhraseDefault('terendah', 'membutuhkan penguatan cetak dalam');

        $result = $this->resolvedCapaian($this->ahmadId, $this->mathGanjilSubjectId, $this->ganjilYearId);

        $this->assertSame('Ahmad Fauzan membutuhkan penguatan cetak dalam Pecahan dasar.', $result['terendah']);
    }

    public function test_rapor_template_processor_preload_uses_resolved_final_text_for_docx_pdf_placeholders(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan penguasaan placeholder dalam');

        $preloaded = CapaianKompetensiController::preloadCapaianData(
            $this->ahmadId,
            [$this->mathGanjilSubjectId],
            $this->ganjilYearId
        );

        $this->assertSame('Ahmad Fauzan menunjukkan penguasaan placeholder dalam Bilangan kuat.', $preloaded[$this->mathGanjilSubjectId]['tertinggi']);
    }

    public function test_existing_placeholder_names_remain_unchanged(): void
    {
        $preloaded = CapaianKompetensiController::preloadCapaianData(
            $this->ahmadId,
            [$this->mathGanjilSubjectId],
            $this->ganjilYearId
        );

        $this->assertSame(['tertinggi', 'terendah'], array_keys($preloaded[$this->mathGanjilSubjectId]));
    }

    public function test_requested_historical_year_semester_context_resolves_correct_contextual_defaults(): void
    {
        $this->insertPhraseDefault('tertinggi', 'menunjukkan default ganjil dalam');
        $this->insertPhraseDefault('tertinggi', 'menunjukkan default genap dalam', $this->genapYearId, 2, $this->genapClassId, $this->mathGenapSubjectId);

        $historical = CapaianKompetensiController::preloadCapaianData(
            $this->ahmadId,
            [$this->mathGenapSubjectId],
            $this->genapYearId
        );

        $this->assertSame('Ahmad Fauzan menunjukkan default genap dalam Geometri genap.', $historical[$this->mathGenapSubjectId]['tertinggi']);
    }

    private function actingAsWali(int $tahunAjaranId, int $semester): self
    {
        return $this->actingAs($this->wali, 'guru')
            ->withSession([
                'tahun_ajaran_id' => $tahunAjaranId,
                'selected_semester' => $semester,
                'selected_role' => 'wali_kelas',
            ]);
    }

    private function resolvedCapaian(int $studentId, int $subjectId, int $yearId): array
    {
        return CapaianKompetensiController::generateCapaianTertinggiTerendah($studentId, $subjectId, $yearId);
    }

    private function createSchema(): void
    {
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

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('email')->nullable();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->integer('semester')->default(1);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();
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
            $table->string('role')->default('wali_kelas');
            $table->timestamps();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->unique();
            $table->string('nisn')->nullable();
            $table->string('nama');
            $table->foreignId('kelas_id')->nullable();
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
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester'], 'siswa_kelas_semester_unique_context');
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id');
            $table->foreignId('guru_id')->nullable();
            $table->integer('semester')->default(1);
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->string('judul_lingkup_materi');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id');
            $table->string('tujuan_pembelajaran')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('capaian_custom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->text('custom_capaian')->nullable();
            $table->text('custom_capaian_tertinggi')->nullable();
            $table->text('custom_capaian_terendah')->nullable();
            $table->string('tertinggi_prefix_mode')->nullable();
            $table->text('tertinggi_prefix_text')->nullable();
            $table->string('terendah_prefix_mode')->nullable();
            $table->text('terendah_prefix_text')->nullable();
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'mata_pelajaran_id', 'tahun_ajaran_id', 'semester'], 'unique_capaian_custom');
        });

        Schema::create('capaian_phrase_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id');
            $table->unsignedTinyInteger('semester');
            $table->foreignId('kelas_id');
            $table->foreignId('mata_pelajaran_id');
            $table->string('type');
            $table->string('mode')->default('preset');
            $table->text('phrase');
            $table->timestamps();
            $table->unique(['tahun_ajaran_id', 'semester', 'kelas_id', 'mata_pelajaran_id', 'type'], 'capaian_phrase_defaults_context_unique');
        });

        Schema::create('capaian_templates', function (Blueprint $table) {
            $table->id();
            $table->string('mata_pelajaran');
            $table->decimal('nilai_min', 5, 2);
            $table->decimal('nilai_max', 5, 2);
            $table->text('template_text');
            $table->foreignId('tahun_ajaran_id');
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->ganjilYearId = $this->insertYear('2026/2027', 1, true);
        $this->genapYearId = $this->insertYear('2026/2027', 2, false);
        $this->oldYearId = $this->insertYear('2025/2026', 1, false);

        $this->wali = Guru::create([
            'nama' => 'Budi Santoso',
            'username' => 'wali-budi',
            'email' => 'wali-budi@example.test',
            'password' => Hash::make('secret'),
        ]);

        $this->ganjilClassId = $this->insertClass(5, 'A', $this->ganjilYearId);
        $this->genapClassId = $this->insertClass(5, 'A', $this->genapYearId);
        $this->otherClassId = $this->insertClass(5, 'B', $this->ganjilYearId);
        $this->oldClassId = $this->insertClass(5, 'A', $this->oldYearId);

        $this->insertWaliAssignment($this->wali->id, $this->ganjilClassId);
        $this->insertWaliAssignment($this->wali->id, $this->genapClassId);

        $this->ahmadId = $this->insertStudent('1001', 'Ahmad Fauzan', $this->ganjilClassId);
        $this->sitiId = $this->insertStudent('1002', 'Siti Aisyah', $this->ganjilClassId);

        foreach ([$this->ahmadId, $this->sitiId] as $studentId) {
            $this->insertEnrollment($studentId, $this->ganjilClassId, $this->ganjilYearId, 1);
            $this->insertEnrollment($studentId, $this->genapClassId, $this->genapYearId, 2);
        }

        $this->mathGanjilSubjectId = $this->insertSubject('Matematika', $this->ganjilClassId, $this->ganjilYearId, 1);
        $this->mathGenapSubjectId = $this->insertSubject('Matematika', $this->genapClassId, $this->genapYearId, 2);
        $this->scienceSubjectId = $this->insertSubject('IPAS', $this->ganjilClassId, $this->ganjilYearId, 1);
        $this->otherClassSubjectId = $this->insertSubject('Matematika', $this->otherClassId, $this->ganjilYearId, 1);
        $this->oldYearSubjectId = $this->insertSubject('Matematika', $this->oldClassId, $this->oldYearId, 1);

        foreach ([$this->ahmadId, $this->sitiId] as $studentId) {
            $this->insertLmScore($studentId, $this->mathGanjilSubjectId, $this->ganjilYearId, 'Bilangan kuat', 95, 88);
            $this->insertLmScore($studentId, $this->mathGanjilSubjectId, $this->ganjilYearId, 'Pecahan dasar', 72, 88);
            $this->insertLmScore($studentId, $this->mathGenapSubjectId, $this->genapYearId, 'Geometri genap', 91, 90);
            $this->insertLmScore($studentId, $this->mathGenapSubjectId, $this->genapYearId, 'Pengukuran genap', 74, 90);
        }

        $this->insertTemplate('Matematika', $this->ganjilYearId, 0, 100, '{nama_siswa} perlu meningkatkan penguasaan dalam mata pelajaran Matematika.');
        $this->insertTemplate('Matematika', $this->genapYearId, 0, 100, '{nama_siswa} perlu meningkatkan penguasaan dalam mata pelajaran Matematika.');
    }

    private function insertYear(string $year, int $semester, bool $active): int
    {
        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => $year,
            'semester' => $semester,
            'is_active' => $active,
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

    private function insertWaliAssignment(int $guruId, int $kelasId): void
    {
        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(string $nis, string $name, int $classId): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => 'NISN-'.$nis,
            'nama' => $name,
            'kelas_id' => $classId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEnrollment(int $studentId, int $classId, int $yearId, int $semester): void
    {
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $yearId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubject(string $name, int $classId, int $yearId, int $semester): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $classId,
            'guru_id' => $this->wali->id,
            'semester' => $semester,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLmScore(int $studentId, int $subjectId, int $yearId, string $lmTitle, float $lmScore, ?float $finalGrade): void
    {
        $lmId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => $lmTitle,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilais')->insert([
            'siswa_id' => $studentId,
            'mata_pelajaran_id' => $subjectId,
            'lingkup_materi_id' => $lmId,
            'nilai_lm' => $lmScore,
            'nilai_akhir_rapor' => $finalGrade,
            'is_submitted' => ! is_null($finalGrade),
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertFullCustom(int $studentId, int $subjectId, ?string $highest, ?string $lowest): int
    {
        return DB::table('capaian_custom')->updateOrInsert(
            [
                'siswa_id' => $studentId,
                'mata_pelajaran_id' => $subjectId,
                'tahun_ajaran_id' => $this->ganjilYearId,
                'semester' => 1,
            ],
            [
                'custom_capaian_tertinggi' => $highest,
                'custom_capaian_terendah' => $lowest,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        ) ? (int) DB::table('capaian_custom')->where('siswa_id', $studentId)->where('mata_pelajaran_id', $subjectId)->value('id') : 0;
    }

    private function insertPrefixOverride(
        int $studentId,
        int $subjectId,
        ?string $tertinggi = null,
        ?string $terendah = null,
        string $tertinggiMode = 'custom',
        string $terendahMode = 'custom'
    ): int {
        DB::table('capaian_custom')->updateOrInsert(
            [
                'siswa_id' => $studentId,
                'mata_pelajaran_id' => $subjectId,
                'tahun_ajaran_id' => $this->ganjilYearId,
                'semester' => 1,
            ],
            [
                'tertinggi_prefix_mode' => $tertinggi ? $tertinggiMode : null,
                'tertinggi_prefix_text' => $tertinggi,
                'terendah_prefix_mode' => $terendah ? $terendahMode : null,
                'terendah_prefix_text' => $terendah,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('capaian_custom')
            ->where('siswa_id', $studentId)
            ->where('mata_pelajaran_id', $subjectId)
            ->where('tahun_ajaran_id', $this->ganjilYearId)
            ->where('semester', 1)
            ->value('id');
    }

    private function insertPhraseDefault(
        string $type,
        string $phrase,
        ?int $yearId = null,
        ?int $semester = null,
        ?int $classId = null,
        ?int $subjectId = null
    ): void {
        DB::table('capaian_phrase_defaults')->updateOrInsert(
            [
                'tahun_ajaran_id' => $yearId ?? $this->ganjilYearId,
                'semester' => $semester ?? 1,
                'kelas_id' => $classId ?? $this->ganjilClassId,
                'mata_pelajaran_id' => $subjectId ?? $this->mathGanjilSubjectId,
                'type' => $type,
            ],
            [
                'mode' => 'preset',
                'phrase' => $phrase,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function insertTemplate(string $subject, int $yearId, float $min, float $max, string $text): void
    {
        DB::table('capaian_templates')->insert([
            'mata_pelajaran' => $subject,
            'nilai_min' => $min,
            'nilai_max' => $max,
            'template_text' => $text,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
