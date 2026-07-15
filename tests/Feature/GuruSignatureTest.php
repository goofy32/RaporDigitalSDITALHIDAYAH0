<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\ReportTemplate;
use App\Models\Siswa;
use App\Models\User;
use App\Services\PdfCacheService;
use App\Services\RaporTemplateProcessor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class GuruSignatureTest extends TestCase
{
    private User $admin;

    private Guru $wali;

    private Guru $subjectTeacher;

    private int $activeYearId;

    private int $otherYearId;

    private int $classId;

    private int $otherClassId;

    private int $studentId;

    private int $otherStudentId;

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

        Storage::fake('local');
        Storage::fake('public');

        $this->createSchema();
        $this->runSignatureMigration();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory(storage_path('app/public/test-templates'));
        $this->deleteDirectory(storage_path('app/public/generated'));

        parent::tearDown();
    }

    public function test_signature_field_is_nullable_and_placeholder_is_registered(): void
    {
        $this->assertTrue(Schema::hasColumn('gurus', 'signature_path'));
        $this->assertNull($this->wali->signature_path);

        $this->assertDatabaseHas('report_placeholders', [
            'placeholder_key' => 'ttd_wali_kelas',
            'description' => 'Gambar tanda tangan wali kelas',
            'is_required' => false,
        ]);
    }

    public function test_admin_can_upload_valid_png_signature_to_private_storage(): void
    {
        $this->assertStringNotContainsString(
            'file_get_contents($file->getRealPath())',
            file_get_contents(app_path('Http/Controllers/GuruSignatureController.php'))
        );
        $this->assertStringNotContainsString(
            'getRealPath',
            file_get_contents(app_path('Http/Controllers/GuruSignatureController.php'))
        );
        $this->assertStringNotContainsString(
            'storeAs',
            file_get_contents(app_path('Http/Controllers/GuruSignatureController.php'))
        );
        $this->assertStringNotContainsString(
            'putFileAs',
            file_get_contents(app_path('Http/Controllers/GuruSignatureController.php'))
        );

        $this->actingAsAdmin()
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->image('wali-signature.png', 240, 80)->size(100),
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('signatureUploadSuccess');

        $path = $this->wali->fresh()->signature_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith('private/guru-signatures/', $path);
        $this->assertStringNotContainsString('wali-signature', $path);
        $this->assertFalse(str_starts_with($path, storage_path()));
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_upload_succeeds_when_realpath_is_empty_but_pathname_is_readable(): void
    {
        $this->actingAsAdmin()
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => $this->realpathUnavailableUpload('windows-laragon-signature.png'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('signatureUploadSuccess');

        $path = $this->wali->fresh()->signature_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith('private/guru-signatures/', $path);
        $this->assertStringEndsWith('.png', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_admin_can_upload_valid_jpg_and_jpeg_signatures(): void
    {
        foreach (['jpg', 'jpeg'] as $extension) {
            $this->actingAsAdmin()
                ->post(route('teacher.signature.store', $this->wali), [
                    'signature' => UploadedFile::fake()->image("signature.{$extension}", 240, 80)->size(100),
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            Storage::disk('local')->assertExists($this->wali->fresh()->signature_path);
        }
    }

    public function test_admin_can_upload_valid_webp_signature(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available in this PHP runtime.');
        }

        $this->actingAsAdmin()
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => $this->webpUpload('signature.webp'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Storage::disk('local')->assertExists($this->wali->fresh()->signature_path);
    }

    public function test_invalid_signature_files_are_rejected_without_replacing_existing_file(): void
    {
        $existingPath = $this->putSignature($this->wali, 'private/guru-signatures/existing.png');

        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->createWithContent('signature.svg', '<svg></svg>'),
            ])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors('signature', null, 'signatureUpload');

        $this->assertSame($existingPath, $this->wali->fresh()->signature_path);
        Storage::disk('local')->assertExists($existingPath);

        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->create('signature.txt', 10, 'text/plain'),
            ])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors('signature', null, 'signatureUpload');

        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->image('large.png', 240, 80)->size(1100),
            ])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors('signature', null, 'signatureUpload');

        $invalidUploadPath = tempnam(sys_get_temp_dir(), 'invalid-signature-upload-');
        file_put_contents($invalidUploadPath, $this->pngBytes([0, 120, 80]));

        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => new UploadedFile($invalidUploadPath, 'signature.png', 'image/png', UPLOAD_ERR_CANT_WRITE, true),
            ])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors('signature', null, 'signatureUpload');

        $this->assertSame($existingPath, $this->wali->fresh()->signature_path);
        Storage::disk('local')->assertExists($existingPath);
    }

    public function test_signature_upload_card_has_single_visible_upload_action_and_hidden_file_input(): void
    {
        $response = $this->actingAsAdmin()
            ->get(route('teacher.edit', $this->wali))
            ->assertOk()
            ->assertSee('Tanda Tangan Digital')
            ->assertDontSee('Tanda tangan guru berhasil disimpan.')
            ->assertSee('id="signatureUploadForm"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('data-turbo="false"', false)
            ->assertSee('data-signature-upload-form', false)
            ->assertSee('name="signature"', false)
            ->assertSee('accept="image/png,image/jpeg,image/webp"', false)
            ->assertSee('data-signature-upload-input', false)
            ->assertSee('class="sr-only"', false)
            ->assertSee('data-signature-upload-label', false)
            ->assertSee('data-signature-upload-client-error', false)
            ->assertSee('Format PNG, JPG, JPEG, atau WebP. Maksimal 1 MB.')
            ->assertSee('Pilih dan Unggah Tanda Tangan')
            ->assertDontSee('Pilih Gambar');

        $this->assertSame(1, substr_count($response->getContent(), 'Pilih dan Unggah Tanda Tangan'));

        $this->assertSame(1, preg_match('/<form[^>]+id="signatureUploadForm"[\s\S]*?<\/form>/', $response->getContent(), $matches));

        $signatureForm = $matches[0];

        $this->assertStringContainsString('class="sr-only"', $signatureForm);
        $this->assertStringContainsString('data-turbo="false"', $signatureForm);
        $this->assertStringContainsString('data-signature-upload-input', $signatureForm);
        $this->assertStringContainsString('data-signature-upload-label', $signatureForm);
        $this->assertStringNotContainsString('onchange=', $signatureForm);
        $this->assertStringNotContainsString('type="submit"', $signatureForm);
        $this->assertStringNotContainsString('file:mr-4', $signatureForm);

        $editTeacherJs = file_get_contents(resource_path('js/pages/edit-teacher.js'));
        $this->assertStringContainsString('function bindSignatureUploadForm()', $editTeacherJs);
        $this->assertStringContainsString("const signatureUploadAllowedTypes = ['image/png', 'image/jpeg', 'image/webp'];", $editTeacherJs);
        $this->assertStringContainsString('const signatureUploadMaxBytes = 1024 * 1024;', $editTeacherJs);
        $this->assertStringContainsString('function validateSignatureUploadFile(file)', $editTeacherJs);
        $this->assertStringContainsString('Ukuran tanda tangan maksimal 1 MB.', $editTeacherJs);
        $this->assertStringContainsString('Format tanda tangan harus PNG, JPG, JPEG, atau WebP.', $editTeacherJs);
        $this->assertStringContainsString('showSignatureUploadClientError(form, validationMessage);', $editTeacherJs);
        $this->assertStringContainsString("form.dataset.signatureUploadBound === 'true'", $editTeacherJs);
        $this->assertStringContainsString("input.addEventListener('change'", $editTeacherJs);
        $this->assertStringContainsString('form.submit();', $editTeacherJs);

        $responseWithError = $this->actingAsAdmin()
            ->withSession([
                'errors' => (new ViewErrorBag())->put('signatureUpload', new MessageBag([
                    'signature' => ['Upload tanda tangan tidak valid. Silakan pilih file lain.'],
                ])),
            ])
            ->get(route('teacher.edit', $this->wali))
            ->assertOk()
            ->assertSee('Upload tanda tangan tidak valid. Silakan pilih file lain.');

        $this->assertStringContainsString('signatureUploadForm', $responseWithError->getContent());

        $this->actingAsAdmin()
            ->withSession(['signatureUploadSuccess' => 'Tanda tangan guru berhasil disimpan.'])
            ->get(route('teacher.edit', $this->wali))
            ->assertOk()
            ->assertSee('Tanda tangan guru berhasil disimpan.');

        $this->putSignature($this->wali, 'private/guru-signatures/existing.png');

        $this->actingAsAdmin()
            ->get(route('teacher.edit', $this->wali))
            ->assertOk()
            ->assertSee('Ganti Tanda Tangan')
            ->assertSee('Hapus')
            ->assertDontSee('Pilih dan Unggah Tanda Tangan');
    }

    public function test_admin_can_replace_and_delete_signature_safely(): void
    {
        $oldPath = $this->putSignature($this->wali, 'private/guru-signatures/old.png');

        $this->actingAsAdmin()
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->image('new.png', 240, 80)->size(100),
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('signatureUploadSuccess');

        $newPath = $this->wali->fresh()->signature_path;

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);

        $this->actingAsAdmin()
            ->delete(route('teacher.signature.destroy', $this->wali))
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('signatureUploadSuccess');

        $this->assertNull($this->wali->fresh()->signature_path);
        Storage::disk('local')->assertMissing($newPath);
    }

    public function test_missing_upload_oversized_upload_and_svg_return_visible_signature_errors(): void
    {
        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors('signature', null, 'signatureUpload');

        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->image('large.png', 240, 80)->size(1100),
            ])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors(['signature' => 'Ukuran tanda tangan maksimal 1 MB.'], null, 'signatureUpload');

        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->createWithContent('signature.svg', '<svg></svg>'),
            ])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors('signature', null, 'signatureUpload');
    }

    public function test_missing_temporary_upload_file_is_reported_without_replacing_existing_signature(): void
    {
        Log::spy();

        $existingPath = $this->putSignature($this->wali, 'private/guru-signatures/existing.png');
        $path = tempnam(sys_get_temp_dir(), 'missing-signature-upload-');
        file_put_contents($path, $this->pngBytes([0, 120, 80]));
        $upload = new UploadedFile($path, 'signature.png', 'image/png', null, true);
        unlink($path);

        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => $upload,
            ])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors([
                'signature' => 'File tanda tangan tidak dapat dibaca dari penyimpanan sementara. Silakan pilih ulang file atau periksa konfigurasi folder temporary PHP.',
            ], null, 'signatureUpload');

        $this->assertSame($existingPath, $this->wali->fresh()->signature_path);
        Storage::disk('local')->assertExists($existingPath);

        Log::shouldHaveReceived('warning')
            ->with('File sementara tanda tangan guru tidak dapat dibaca.', Mockery::on(function (array $context): bool {
                return $context['pathname_present'] === true
                    && $context['pathname_exists'] === false
                    && $context['pathname_readable'] === false
                    && ! array_key_exists('path', $context);
            }))
            ->once();
    }

    public function test_storage_false_preserves_old_signature(): void
    {
        $oldPath = $this->putSignature($this->wali, 'private/guru-signatures/existing.png');
        $disk = Mockery::mock();
        $disk->shouldReceive('writeStream')
            ->once()
            ->with(
                Mockery::on(fn (string $path): bool => str_starts_with($path, 'private/guru-signatures/') && str_ends_with($path, '.png')),
                Mockery::on(fn ($stream): bool => is_resource($stream))
            )
            ->andReturn(false);

        Storage::shouldReceive('disk')
            ->once()
            ->with('local')
            ->andReturn($disk);

        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->image('signature.png', 240, 80)->size(100),
            ])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors('signature', null, 'signatureUpload');

        $this->assertSame($oldPath, $this->wali->fresh()->signature_path);
    }

    public function test_write_stream_exception_logs_safe_diagnostics_without_paths_or_original_filename(): void
    {
        $oldPath = $this->putSignature($this->wali, 'private/guru-signatures/existing.png');
        $disk = Mockery::mock();
        $disk->shouldReceive('writeStream')
            ->once()
            ->andThrow(new RuntimeException('Simulated private storage failure for very-private-signature-name.png'));

        Storage::shouldReceive('disk')
            ->once()
            ->with('local')
            ->andReturn($disk);

        Log::shouldReceive('warning')
            ->once()
            ->with('Gagal menyimpan file tanda tangan guru.', Mockery::on(function (array $context): bool {
                $encodedContext = json_encode($context);

                return $context['exception'] === RuntimeException::class
                    && str_contains($context['message'], 'Simulated private storage failure')
                    && isset($context['source_file'], $context['source_line'])
                    && basename($context['source_file']) === $context['source_file']
                    && ! str_contains($encodedContext, base_path())
                    && ! str_contains($encodedContext, storage_path())
                    && ! str_contains($encodedContext, 'very-private-signature-name.png');
            }));

        $this->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->image('very-private-signature-name.png', 240, 80)->size(100),
            ])
            ->assertRedirect(route('teacher.edit', $this->wali))
            ->assertSessionHasErrors('signature', null, 'signatureUpload');

        $this->assertSame($oldPath, $this->wali->fresh()->signature_path);
    }

    public function test_database_failure_preserves_old_signature_and_cleans_new_file(): void
    {
        $oldPath = $this->putSignature($this->wali, 'private/guru-signatures/existing.png');

        Guru::saving(function (Guru $guru) {
            if ($guru->id === $this->wali->id && $guru->isDirty('signature_path')) {
                throw new RuntimeException('Simulated signature database failure.');
            }
        });

        try {
            $this->actingAsAdmin()
                ->from(route('teacher.edit', $this->wali))
                ->post(route('teacher.signature.store', $this->wali), [
                    'signature' => UploadedFile::fake()->image('new.png', 240, 80)->size(100),
                ])
                ->assertRedirect(route('teacher.edit', $this->wali))
                ->assertSessionHasErrors('signature', null, 'signatureUpload');

            $this->assertSame($oldPath, $this->wali->fresh()->signature_path);
            Storage::disk('local')->assertExists($oldPath);
            $this->assertCount(1, Storage::disk('local')->files('private/guru-signatures'));
        } finally {
            Guru::flushEventListeners();
        }
    }

    public function test_successful_upload_renders_preview_after_redirect(): void
    {
        $response = $this->followingRedirects()
            ->actingAsAdmin()
            ->from(route('teacher.edit', $this->wali))
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->image('signature.png', 240, 80)->size(100),
            ])
            ->assertOk()
            ->assertSee('Ganti Tanda Tangan')
            ->assertSee('Tanda tangan guru berhasil disimpan.');

        $this->assertStringContainsString('alt="Preview tanda tangan', $response->getContent());
    }

    public function test_preview_is_admin_only_and_served_from_private_storage(): void
    {
        $path = $this->putSignature($this->wali, 'private/guru-signatures/preview.png');

        $this->get(route('teacher.signature.show', $this->wali))
            ->assertRedirect(route('login'));

        $this->actingAs($this->wali, 'guru')
            ->withSession($this->guruSession())
            ->get(route('teacher.signature.show', $this->wali))
            ->assertRedirect(route('login'));

        $response = $this->actingAsAdmin()
            ->get(route('teacher.signature.show', $this->wali))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/png');

        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        Storage::disk('local')->assertExists($path);
    }

    public function test_soft_deleted_guru_signature_cannot_be_modified(): void
    {
        $this->wali->delete();

        $this->actingAsAdmin()
            ->post(route('teacher.signature.store', $this->wali->id), [
                'signature' => UploadedFile::fake()->image('signature.png', 240, 80)->size(100),
            ])
            ->assertNotFound();
    }

    public function test_signature_upload_invalidates_only_related_pdf_cache_without_deleting_history(): void
    {
        $relatedStudent = Siswa::findOrFail($this->studentId);
        $unrelatedStudent = Siswa::findOrFail($this->otherStudentId);

        Storage::disk('public')->put('pdf_reports/related.pdf', 'related');
        Storage::disk('public')->put('pdf_reports/unrelated.pdf', 'unrelated');
        Cache::put(PdfCacheService::getCacheKey($relatedStudent, 'UTS', $this->activeYearId), [
            'path' => 'pdf_reports/related.pdf',
            'generated_at' => now()->toISOString(),
        ], now()->addHour());
        Cache::put(PdfCacheService::getCacheKey($unrelatedStudent, 'UTS', $this->activeYearId), [
            'path' => 'pdf_reports/unrelated.pdf',
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        DB::table('report_generations')->insert([
            'siswa_id' => $this->studentId,
            'kelas_id' => $this->classId,
            'tahun_ajaran_id' => $this->activeYearId,
            'type' => 'UTS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->post(route('teacher.signature.store', $this->wali), [
                'signature' => UploadedFile::fake()->image('signature.png', 240, 80)->size(100),
            ])
            ->assertRedirect();

        $this->assertNull(Cache::get(PdfCacheService::getCacheKey($relatedStudent, 'UTS', $this->activeYearId)));
        $this->assertNotNull(Cache::get(PdfCacheService::getCacheKey($unrelatedStudent, 'UTS', $this->activeYearId)));
        $this->assertDatabaseCount('report_generations', 1);
    }

    public function test_report_template_replaces_signature_placeholder_with_contextual_wali_image(): void
    {
        $waliBytes = $this->pngBytes([0, 120, 80]);
        $subjectBytes = $this->pngBytes([200, 0, 0]);
        $this->putSignature($this->wali, 'private/guru-signatures/wali.png', $waliBytes);
        $this->putSignature($this->subjectTeacher, 'private/guru-signatures/mapel.png', $subjectBytes);

        $template = $this->createDocxTemplate('${ttd_wali_kelas}');
        $result = (new RaporTemplateProcessor($template, Siswa::findOrFail($this->studentId), 'UTS', $this->activeYearId))
            ->generate(true);

        $outputPath = storage_path('app/public/'.$result['path']);
        $this->assertFileExists($outputPath);
        $this->assertStringNotContainsString('ttd_wali_kelas', $this->docxXml($outputPath));
        $this->assertTrue($this->docxMediaContains($outputPath, md5($waliBytes)));
        $this->assertFalse($this->docxMediaContains($outputPath, md5($subjectBytes)));
    }

    public function test_report_template_clears_signature_placeholder_when_signature_missing_or_file_missing(): void
    {
        $template = $this->createDocxTemplate('${ttd_wali_kelas}');

        $result = (new RaporTemplateProcessor($template, Siswa::findOrFail($this->studentId), 'UTS', $this->activeYearId))
            ->generate(true);

        $this->assertStringNotContainsString('ttd_wali_kelas', $this->docxXml(storage_path('app/public/'.$result['path'])));

        $missingPath = 'private/guru-signatures/missing.png';
        $this->wali->forceFill(['signature_path' => $missingPath])->save();

        $result = (new RaporTemplateProcessor($template->fresh(), Siswa::findOrFail($this->studentId), 'UTS', $this->activeYearId))
            ->generate(true);

        $this->assertStringNotContainsString('ttd_wali_kelas', $this->docxXml(storage_path('app/public/'.$result['path'])));
    }

    public function test_template_without_signature_placeholder_and_existing_foto_siswa_placeholder_still_generates(): void
    {
        $template = $this->createDocxTemplate('${nama_siswa} ${foto_siswa}');

        $result = (new RaporTemplateProcessor($template, Siswa::findOrFail($this->studentId), 'UTS', $this->activeYearId))
            ->generate(true);

        $xml = $this->docxXml(storage_path('app/public/'.$result['path']));

        $this->assertStringNotContainsString('ttd_wali_kelas', $xml);
        $this->assertStringNotContainsString('foto_siswa', $xml);
        $this->assertStringContainsString('Ahmad', $xml);
    }

    public function test_report_template_replaces_table_cell_student_photo_with_centered_inline_3x4_image(): void
    {
        Storage::disk('public')->put('student-photos/landscape.png', $this->pngBytesWithSize([20, 80, 180], 800, 400));
        Siswa::findOrFail($this->studentId)->forceFill(['photo' => 'student-photos/landscape.png'])->save();

        $template = $this->createDocxTemplateWithPhotoTable();

        $result = (new RaporTemplateProcessor($template, Siswa::findOrFail($this->studentId), 'UTS', $this->activeYearId))
            ->generate(true);

        $outputPath = storage_path('app/public/'.$result['path']);
        $xml = $this->docxXml($outputPath);

        $this->assertStringContainsString('<w:tbl>', $xml);
        $this->assertStringNotContainsString('foto_siswa', $xml);
        $this->assertStringContainsString('width:3cm;height:4cm', $xml);
        $this->assertStringContainsString('<w:jc w:val="center"/>', $xml);
        $this->assertStringContainsString('<w:pict>', $xml);
        $this->assertStringNotContainsString('<wp:anchor', $xml);
        $this->assertStringContainsString('Bandung, tanggal', $xml);
        $this->assertStringContainsString('Kepala Sekolah', $xml);
        $this->assertStringContainsString('NUPTK', $xml);

        $this->assertContains([450, 600], $this->docxMediaDimensions($outputPath));
    }

    public function test_report_template_cleans_table_cell_student_photo_placeholder_when_photo_missing(): void
    {
        Siswa::findOrFail($this->studentId)->forceFill(['photo' => 'student-photos/missing.png'])->save();

        $template = $this->createDocxTemplateWithPhotoTable();

        $result = (new RaporTemplateProcessor($template, Siswa::findOrFail($this->studentId), 'UTS', $this->activeYearId))
            ->generate(true);

        $xml = $this->docxXml(storage_path('app/public/'.$result['path']));

        $this->assertStringContainsString('<w:tbl>', $xml);
        $this->assertStringNotContainsString('foto_siswa', $xml);
        $this->assertStringNotContainsString('[FOTO TIDAK TERSEDIA]', $xml);
        $this->assertStringNotContainsString('[ERROR MEMUAT FOTO]', $xml);
        $this->assertStringContainsString('Bandung, tanggal', $xml);
        $this->assertStringContainsString('Kepala Sekolah', $xml);
        $this->assertStringContainsString('NUPTK', $xml);
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'report_generations',
            'report_template_kelas',
            'report_templates',
            'report_placeholders',
            'capaian_custom',
            'nilai_ekstrakurikuler',
            'ekstrakurikulers',
            'absensis',
            'catatan_siswa',
            'kkms',
            'nilais',
            'mata_pelajarans',
            'siswa_kelas_semester',
            'siswas',
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
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('no_handphone')->nullable();
            $table->string('email')->nullable();
            $table->text('alamat')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->string('password_plain')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->integer('semester')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->string('kepala_sekolah')->nullable();
            $table->string('nip_kepala_sekolah')->nullable();
            $table->string('tempat_terbit')->nullable();
            $table->string('telepon')->nullable();
            $table->text('alamat')->nullable();
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

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->nullable();
            $table->string('nisn')->nullable();
            $table->string('nama');
            $table->foreignId('kelas_id')->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('nilai')->default(70);
            $table->timestamps();
        });

        Schema::create('catatan_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->string('type')->default('umum');
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('sakit')->default(0);
            $table->integer('izin')->default(0);
            $table->integer('tanpa_keterangan')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama_ekstrakurikuler');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilai_ekstrakurikuler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('ekstrakurikuler_id')->nullable();
            $table->string('predikat')->nullable();
            $table->text('deskripsi')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('capaian_custom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->text('custom_capaian_tertinggi')->nullable();
            $table->text('custom_capaian_terendah')->nullable();
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
        });

        Schema::create('report_placeholders', function (Blueprint $table) {
            $table->id();
            $table->string('placeholder_key');
            $table->string('description');
            $table->string('category');
            $table->string('sample_value');
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->nullable();
            $table->string('path')->nullable();
            $table->string('type');
            $table->boolean('is_active')->default(false);
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('report_template_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id');
            $table->foreignId('kelas_id');
            $table->timestamps();
        });

        Schema::create('report_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('report_template_id')->nullable();
            $table->string('generated_file')->nullable();
            $table->string('type')->nullable();
            $table->integer('semester')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->foreignId('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    private function runSignatureMigration(): void
    {
        $migration = include database_path('migrations/2026_06_16_010000_add_signature_path_to_gurus_and_report_placeholders.php');
        $migration->up();
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->activeYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->otherYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->classId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->otherClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'B',
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $waliId = DB::table('gurus')->insertGetId([
            'nuptk' => '12345',
            'nama' => 'Wali Budi',
            'email' => 'wali@example.test',
            'username' => 'wali',
            'password' => Hash::make('password'),
            'jabatan' => 'guru_wali',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subjectTeacherId = DB::table('gurus')->insertGetId([
            'nuptk' => '67890',
            'nama' => 'Guru Mapel',
            'email' => 'mapel@example.test',
            'username' => 'mapel',
            'password' => Hash::make('password'),
            'jabatan' => 'guru',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            [
                'guru_id' => $waliId,
                'kelas_id' => $this->classId,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $subjectTeacherId,
                'kelas_id' => $this->classId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->studentId = DB::table('siswas')->insertGetId([
            'nis' => '1001',
            'nisn' => '9001',
            'nama' => 'Ahmad',
            'kelas_id' => $this->classId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->otherStudentId = DB::table('siswas')->insertGetId([
            'nis' => '1002',
            'nisn' => '9002',
            'nama' => 'Bilal',
            'kelas_id' => $this->otherClassId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswa_kelas_semester')->insert([
            [
                'siswa_id' => $this->studentId,
                'kelas_id' => $this->classId,
                'tahun_ajaran_id' => $this->activeYearId,
                'semester' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'siswa_id' => $this->otherStudentId,
                'kelas_id' => $this->otherClassId,
                'tahun_ajaran_id' => $this->activeYearId,
                'semester' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $this->classId,
            'guru_id' => $subjectTeacherId,
            'semester' => 1,
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->wali = Guru::findOrFail($waliId);
        $this->subjectTeacher = Guru::findOrFail($subjectTeacherId);
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession());
    }

    private function adminSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->activeYearId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
            'last_activity' => time(),
        ];
    }

    private function guruSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->activeYearId,
            'selected_semester' => 1,
            'selected_role' => 'wali_kelas',
            'no_tahun_ajaran' => false,
            'last_activity' => time(),
        ];
    }

    private function putSignature(Guru $guru, string $path, ?string $bytes = null): string
    {
        Storage::disk('local')->put($path, $bytes ?? $this->pngBytes([0, 120, 80]));
        $guru->forceFill(['signature_path' => $path])->save();

        return $path;
    }

    private function pngBytes(array $rgb): string
    {
        $image = imagecreatetruecolor(120, 60);
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($image, 0, 0, 119, 59, $color);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function pngBytesWithSize(array $rgb, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function webpUpload(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(120, 60);
        $color = imagecolorallocate($image, 0, 120, 80);
        imagefilledrectangle($image, 0, 0, 119, 59, $color);

        $path = tempnam(sys_get_temp_dir(), 'webp-signature-');
        imagewebp($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/webp', null, true);
    }

    private function realpathUnavailableUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'realpathless-signature-upload-');
        file_put_contents($path, $this->pngBytes([0, 120, 80]));

        return new class($path, $name, 'image/png', null, true) extends UploadedFile
        {
            public function getRealPath(): string|false
            {
                return false;
            }
        };
    }

    private function createDocxTemplate(string $text): ReportTemplate
    {
        $directory = storage_path('app/public/test-templates');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = 'signature-template-'.uniqid().'.docx';
        $path = $directory.'/'.$filename;

        $phpWord = new PhpWord;
        $phpWord->addSection()->addText($text);
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return ReportTemplate::create([
            'filename' => $filename,
            'path' => 'test-templates/'.$filename,
            'type' => 'UTS',
            'is_active' => true,
            'kelas_id' => $this->classId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);
    }

    private function createDocxTemplateWithPhotoTable(): ReportTemplate
    {
        $directory = storage_path('app/public/test-templates');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = 'photo-table-template-'.uniqid().'.docx';
        $path = $directory.'/'.$filename;

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $table = $section->addTable();
        $table->addRow();
        $table->addCell(2400)->addText('${foto_siswa}');
        $signatureCell = $table->addCell(5200);
        $signatureCell->addText('Bandung, tanggal');
        $signatureCell->addText('Kepala Sekolah');
        $signatureCell->addText('Nama Kepala Sekolah');
        $signatureCell->addText('NUPTK');

        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return ReportTemplate::create([
            'filename' => $filename,
            'path' => 'test-templates/'.$filename,
            'type' => 'UTS',
            'is_active' => true,
            'kelas_id' => $this->classId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);
    }

    private function docxXml(string $path): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();

        return $xml;
    }

    private function docxMediaContains(string $path, string $expectedMd5): bool
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! str_starts_with($name, 'word/media/')) {
                continue;
            }

            if (md5($zip->getFromIndex($index)) === $expectedMd5) {
                $zip->close();

                return true;
            }
        }

        $zip->close();

        return false;
    }

    private function docxMediaDimensions(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        $dimensions = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! str_starts_with($name, 'word/media/')) {
                continue;
            }

            $imageSize = @getimagesizefromstring($zip->getFromIndex($index));
            if (is_array($imageSize)) {
                $dimensions[] = [$imageSize[0], $imageSize[1]];
            }
        }

        $zip->close();

        return $dimensions;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (glob($path.'/*') ?: [] as $item) {
            is_dir($item) ? $this->deleteDirectory($item) : @unlink($item);
        }

        @rmdir($path);
    }
}
