<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GuruEmailRecoveryMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique();
        });
        Schema::create('gurus', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
        });
    }

    public function test_migration_adds_and_rolls_back_guru_recovery_schema(): void
    {
        $guruId = DB::table('gurus')->insertGetId([
            'username' => 'guru-existing',
            'email' => 'guru-existing@example.test',
        ]);
        $migration = $this->migration();

        $migration->up();

        $this->assertTrue(Schema::hasColumn('gurus', 'email_verified_at'));
        $this->assertTrue(Schema::hasTable('guru_password_reset_tokens'));
        $this->assertNull(DB::table('gurus')->where('id', $guruId)->value('email_verified_at'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('gurus', 'email_verified_at'));
        $this->assertFalse(Schema::hasTable('guru_password_reset_tokens'));
    }

    public function test_migration_fails_before_schema_change_when_login_identifiers_overlap(): void
    {
        DB::table('users')->insert([
            'username' => 'admin-sekolah',
            'email' => 'shared@example.test',
        ]);
        DB::table('gurus')->insert([
            'username' => 'shared@example.test',
            'email' => 'guru@example.test',
        ]);

        try {
            $this->migration()->up();
            $this->fail('Migration should reject ambiguous Admin/Guru identifiers.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('identifiers overlap', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('gurus', 'email_verified_at'));
        $this->assertFalse(Schema::hasTable('guru_password_reset_tokens'));
    }

    public function test_migration_rejects_ambiguous_identifiers_between_gurus(): void
    {
        DB::table('gurus')->insert([
            [
                'username' => 'guru-pertama',
                'email' => 'shared-guru@example.test',
            ],
            [
                'username' => 'shared-guru@example.test',
                'email' => 'guru-kedua@example.test',
            ],
        ]);

        $this->expectException(RuntimeException::class);

        $this->migration()->up();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_12_000000_add_guru_email_recovery.php');
    }
}
