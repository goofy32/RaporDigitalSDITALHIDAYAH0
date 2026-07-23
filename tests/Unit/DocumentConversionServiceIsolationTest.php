<?php

namespace Tests\Unit;

use App\Services\DocumentConversionService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentConversionServiceIsolationTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRoot = storage_path('framework/testing/libreoffice-isolation-tests');
        File::deleteDirectory($this->testRoot);
        File::ensureDirectoryExists($this->testRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testRoot);

        parent::tearDown();
    }

    public function test_libreoffice_command_builder_includes_unique_user_installation_profiles(): void
    {
        $service = new DocumentConversionService();
        $source = $this->writeDocx('source-a.docx');
        $outputDir = $this->testRoot.DIRECTORY_SEPARATOR.'output';

        $first = $service->createLibreOfficeConversionContext($source, $outputDir);
        $second = $service->createLibreOfficeConversionContext($source, $outputDir);

        $firstCommand = $service->buildLibreOfficeCommand('/usr/local/bin/soffice-www', $first);
        $secondCommand = $service->buildLibreOfficeCommand('/usr/local/bin/soffice-www', $second);

        $firstProfileArg = $this->firstCommandArgStartingWith($firstCommand, '-env:UserInstallation=');
        $secondProfileArg = $this->firstCommandArgStartingWith($secondCommand, '-env:UserInstallation=');

        $this->assertNotNull($firstProfileArg);
        $this->assertNotNull($secondProfileArg);
        $this->assertStringStartsWith('-env:UserInstallation=file:///', $firstProfileArg);
        $this->assertStringStartsWith('-env:UserInstallation=file:///', $secondProfileArg);
        $this->assertNotSame($first['profile_dir'], $second['profile_dir']);
        $this->assertNotSame($firstProfileArg, $secondProfileArg);
    }

    public function test_libreoffice_conversion_context_uses_unique_temp_directories(): void
    {
        $service = new DocumentConversionService();
        $source = $this->writeDocx('source-b.docx');
        $outputDir = $this->testRoot.DIRECTORY_SEPARATOR.'output';

        $first = $service->createLibreOfficeConversionContext($source, $outputDir);
        $second = $service->createLibreOfficeConversionContext($source, $outputDir);

        $this->assertNotSame($first['root_dir'], $second['root_dir']);
        $this->assertNotSame($first['tmp_dir'], $second['tmp_dir']);
        $this->assertNotSame($first['output_dir'], $second['output_dir']);
        $this->assertStringContainsString('tmp/libreoffice', str_replace('\\', '/', $first['root_dir']));
    }

    public function test_cleanup_is_attempted_after_successful_conversion(): void
    {
        $service = new FakeDocumentConversionServiceForIsolation();
        $source = $this->writeDocx('success-source.docx');
        $outputDir = $this->testRoot.DIRECTORY_SEPARATOR.'success-output';

        $result = $service->convertDocxToPdf($source, $outputDir);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['cleanup_success']);
        $this->assertFileExists($outputDir.DIRECTORY_SEPARATOR.'success-source.pdf');
        $this->assertCount(1, $service->executions);
        $this->assertFalse(is_dir($this->rootFromExecution($service->executions[0])));
    }

    public function test_cleanup_is_attempted_after_failed_conversion_and_retry_uses_fresh_context(): void
    {
        $service = new FakeDocumentConversionServiceForIsolation();
        $service->shouldSucceed = false;
        $source = $this->writeDocx('failed-source.docx');
        $outputDir = $this->testRoot.DIRECTORY_SEPARATOR.'failed-output';

        $result = $service->convertDocxToPdf($source, $outputDir);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['cleanup_success']);
        $this->assertTrue($result['retried']);
        $this->assertCount(2, $service->executions);
        $this->assertNotSame(
            $this->profileArg($service->executions[0]),
            $this->profileArg($service->executions[1])
        );
        $this->assertFalse(is_dir($this->rootFromExecution($service->executions[0])));
        $this->assertFalse(is_dir($this->rootFromExecution($service->executions[1])));
        $this->assertFileDoesNotExist($outputDir.DIRECTORY_SEPARATOR.'failed-source.pdf');
    }

    private function writeDocx(string $filename): string
    {
        $path = $this->testRoot.DIRECTORY_SEPARATOR.$filename;
        File::put($path, 'fake docx bytes');

        return $path;
    }

    private function firstCommandArgStartingWith(array $command, string $prefix): ?string
    {
        foreach ($command as $arg) {
            if (str_starts_with((string) $arg, $prefix)) {
                return (string) $arg;
            }
        }

        return null;
    }

    private function profileArg(array $execution): string
    {
        return $this->firstCommandArgStartingWith($execution['command'], '-env:UserInstallation=');
    }

    private function rootFromExecution(array $execution): string
    {
        return dirname($execution['environment']['TMPDIR']);
    }
}

class FakeDocumentConversionServiceForIsolation extends DocumentConversionService
{
    /**
     * @var array<int, array{command: array<int, string>, environment: array<string, string>}>
     */
    public array $executions = [];

    public bool $shouldSucceed = true;

    public function isLibreOfficeAvailable(): bool
    {
        return true;
    }

    protected function resolveLibreOfficePathForConversion(): string
    {
        return '/usr/local/bin/soffice-www';
    }

    protected function executeLibreOfficeProcess(array $command, array $environment): array
    {
        $this->executions[] = [
            'command' => $command,
            'environment' => $environment,
        ];

        if (! $this->shouldSucceed) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => '',
                'successful' => false,
            ];
        }

        $outputDir = $this->argumentAfter($command, '--outdir');
        $sourcePath = (string) end($command);

        File::ensureDirectoryExists($outputDir);
        File::put($outputDir.DIRECTORY_SEPARATOR.pathinfo($sourcePath, PATHINFO_FILENAME).'.pdf', 'PDF');

        return [
            'exit_code' => 0,
            'stdout' => 'convert ok',
            'stderr' => '',
            'successful' => true,
        ];
    }

    private function argumentAfter(array $command, string $needle): string
    {
        $index = array_search($needle, $command, true);

        if ($index === false || ! isset($command[$index + 1])) {
            throw new \RuntimeException("Missing {$needle} argument.");
        }

        return (string) $command[$index + 1];
    }
}
