<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();
    }

    public function test_queue_support_migration_creates_missing_failed_jobs_and_job_batches_without_touching_jobs(): void
    {
        $this->createJobsTable();

        DB::table('jobs')->insert([
            'queue' => 'pdf',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => 1,
            'created_at' => 1,
        ]);

        $this->queueSupportMigration()->up();

        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertTrue(Schema::hasColumn('failed_jobs', 'uuid'));
        $this->assertTrue(Schema::hasColumn('failed_jobs', 'failed_at'));
        $this->assertTrue(Schema::hasColumn('job_batches', 'failed_job_ids'));
        $this->assertTrue(Schema::hasColumn('job_batches', 'finished_at'));
    }

    public function test_queue_support_migration_is_idempotent_when_support_tables_already_exist(): void
    {
        $this->createJobsTable();
        $this->createFailedJobsTable();
        $this->createJobBatchesTable();

        DB::table('failed_jobs')->insert([
            'uuid' => 'existing-failed-job',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'example',
            'failed_at' => now(),
        ]);
        DB::table('job_batches')->insert([
            'id' => 'existing-batch',
            'name' => 'Existing Batch',
            'total_jobs' => 1,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => null,
            'cancelled_at' => null,
            'created_at' => 1,
            'finished_at' => null,
        ]);

        $this->queueSupportMigration()->up();

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('job_batches')->count());
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'existing-failed-job']);
        $this->assertDatabaseHas('job_batches', ['id' => 'existing-batch']);
    }

    public function test_test_data_seeder_no_longer_requires_siswas_note_column(): void
    {
        $contents = file_get_contents(database_path('seeders/TestDataSeeder.php'));

        $this->assertStringNotContainsString("'note' =>", $contents);
        $this->assertStringNotContainsString('"note" =>', $contents);
    }

    private function queueSupportMigration(): object
    {
        return require database_path('migrations/2026_06_05_000000_create_missing_queue_support_tables.php');
    }

    private function createJobsTable(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    private function createFailedJobsTable(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    private function createJobBatchesTable(): void
    {
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }
}
