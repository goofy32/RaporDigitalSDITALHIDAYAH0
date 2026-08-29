<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SimplifyGuruUsernamesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->createSchema();
    }

    public function test_dry_run_previews_short_usernames_without_updating_gurus(): void
    {
        $this->insertGuru('Eneng Syarah Fatimah Al Zahro, S.Pd.', 'eneng_syarah_fatimah_al_zahro_spd');
        $this->insertGuru('Hetty Herdiani Rahayu, S.Pd.I.', 'hetty_herdiani_rahayu_spdi');
        $this->insertGuru('M. Robby A. Saputra, S.Pd.', 'm_robby_a_saputra_spd');
        $this->insertGuru('Rr. Ria Pujiasih, S.T.P.', 'rr_ria_pujiasih_stp');

        $this->artisan('initial-data:simplify-guru-usernames')
            ->expectsOutput('#1: eneng_syarah_fatimah_al_zahro_spd -> eneng_syarah')
            ->expectsOutput('#2: hetty_herdiani_rahayu_spdi -> hetty_herdiani')
            ->expectsOutput('#3: m_robby_a_saputra_spd -> m_robby')
            ->expectsOutput('#4: rr_ria_pujiasih_stp -> rr_ria')
            ->expectsOutput('Total guru: 4')
            ->expectsOutput('Perubahan: 4')
            ->expectsOutput('Tidak berubah: 0')
            ->assertExitCode(0);

        $this->assertDatabaseHas('gurus', ['username' => 'eneng_syarah_fatimah_al_zahro_spd']);
        $this->assertDatabaseHas('gurus', ['username' => 'hetty_herdiani_rahayu_spdi']);
        $this->assertDatabaseHas('gurus', ['username' => 'm_robby_a_saputra_spd']);
        $this->assertDatabaseHas('gurus', ['username' => 'rr_ria_pujiasih_stp']);
    }

    public function test_apostrophes_inside_name_words_do_not_split_username_tokens(): void
    {
        $this->insertGuru("Silvia Ma'rifatunnisa, S.Pd.", 'silvia_lama');
        $this->insertGuru("Nadia Ma\u{2019}rifatunnisa, S.Pd.", 'nadia_lama');

        $this->artisan('initial-data:simplify-guru-usernames')
            ->expectsOutput('#1: silvia_lama -> silvia_marifatunnisa')
            ->expectsOutput('#2: nadia_lama -> nadia_marifatunnisa')
            ->assertExitCode(0);

        $this->assertDatabaseHas('gurus', ['id' => 1, 'username' => 'silvia_lama']);
        $this->assertDatabaseHas('gurus', ['id' => 2, 'username' => 'nadia_lama']);
    }

    public function test_apply_updates_only_usernames_and_preserves_other_guru_data(): void
    {
        $guruId = $this->insertGuru(
            'Eneng Syarah Fatimah Al Zahro, S.Pd.',
            'eneng_syarah_fatimah_al_zahro_spd',
            [
                'nuptk' => '1234567890',
                'jenis_kelamin' => 'Perempuan',
                'email' => 'eneng@example.test',
                'jabatan' => 'guru_wali',
                'password' => 'hashed-password',
            ]
        );

        $this->artisan('initial-data:simplify-guru-usernames', ['--apply' => true])
            ->expectsOutput('#1: eneng_syarah_fatimah_al_zahro_spd -> eneng_syarah')
            ->expectsOutput('Username guru berhasil diperbarui.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('gurus', [
            'id' => $guruId,
            'nama' => 'Eneng Syarah Fatimah Al Zahro, S.Pd.',
            'username' => 'eneng_syarah',
            'nuptk' => '1234567890',
            'jenis_kelamin' => 'Perempuan',
            'email' => 'eneng@example.test',
            'jabatan' => 'guru_wali',
            'password' => 'hashed-password',
        ]);
    }

    public function test_apply_keeps_usernames_unique_with_numeric_suffixes(): void
    {
        $this->insertGuru('Siti Martiyani Dewi, S.Pd.', 'siti_martiyani_dewi_spd');
        $this->insertGuru('Siti Martiyani Lestari, S.Pd.I.', 'siti_martiyani_lestari_spdi');
        $this->insertGuru('Siti Martiyani Rahma, S.T.P.', 'siti_martiyani_rahma_stp');

        $this->artisan('initial-data:simplify-guru-usernames', ['--apply' => true])
            ->expectsOutput('#1: siti_martiyani_dewi_spd -> siti_martiyani')
            ->expectsOutput('#2: siti_martiyani_lestari_spdi -> siti_martiyani_2')
            ->expectsOutput('#3: siti_martiyani_rahma_stp -> siti_martiyani_3')
            ->assertExitCode(0);

        $this->assertDatabaseHas('gurus', ['id' => 1, 'username' => 'siti_martiyani']);
        $this->assertDatabaseHas('gurus', ['id' => 2, 'username' => 'siti_martiyani_2']);
        $this->assertDatabaseHas('gurus', ['id' => 3, 'username' => 'siti_martiyani_3']);
    }

    public function test_command_reserves_admin_username_and_email_identifiers(): void
    {
        DB::table('users')->insert([
            'name' => 'Admin',
            'username' => 'eneng_syarah',
            'email' => 'budi_santoso@example.test',
            'password' => 'hashed-password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertGuru('Eneng Syarah Fatimah, S.Pd.', 'eneng_syarah_lama');
        $this->insertGuru('Budi Santoso, S.Pd.', 'budi_santoso_lama');

        $this->artisan('initial-data:simplify-guru-usernames', ['--apply' => true])
            ->expectsOutput('#1: eneng_syarah_lama -> eneng_syarah_2')
            ->expectsOutput('#2: budi_santoso_lama -> budi_santoso')
            ->assertExitCode(0);

        $this->assertDatabaseHas('gurus', ['id' => 1, 'username' => 'eneng_syarah_2']);
        $this->assertDatabaseHas('gurus', ['id' => 2, 'username' => 'budi_santoso']);
    }

    public function test_apply_handles_target_username_that_is_currently_used_by_another_guru(): void
    {
        $this->insertGuru('Ahmad Hasan, S.Pd.', 'ahmad_hasan_full');
        $this->insertGuru('Budi Santoso, S.Pd.', 'ahmad_hasan');

        $this->artisan('initial-data:simplify-guru-usernames', ['--apply' => true])
            ->expectsOutput('#1: ahmad_hasan_full -> ahmad_hasan')
            ->expectsOutput('#2: ahmad_hasan -> budi_santoso')
            ->assertExitCode(0);

        $this->assertDatabaseHas('gurus', ['id' => 1, 'username' => 'ahmad_hasan']);
        $this->assertDatabaseHas('gurus', ['id' => 2, 'username' => 'budi_santoso']);
    }

    public function test_command_is_safe_to_rerun_after_apply(): void
    {
        $this->insertGuru('M. Robby A. Saputra, S.Pd.', 'm_robby_a_saputra_spd');
        $this->insertGuru('Rr. Ria Pujiasih, S.T.P.', 'rr_ria_pujiasih_stp');

        $this->artisan('initial-data:simplify-guru-usernames', ['--apply' => true])
            ->assertExitCode(0);

        $this->artisan('initial-data:simplify-guru-usernames', ['--apply' => true])
            ->expectsOutput('Tidak ada username guru yang perlu diperbarui.')
            ->expectsOutput('Total guru: 2')
            ->expectsOutput('Perubahan: 0')
            ->expectsOutput('Tidak berubah: 2')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('gurus')->where('username', 'm_robby')->count());
        $this->assertSame(1, DB::table('gurus')->where('username', 'rr_ria')->count());
        $this->assertSame(2, DB::table('gurus')->count());
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
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
            $table->string('username')->unique();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertGuru(string $name, string $username, array $attributes = []): int
    {
        return (int) DB::table('gurus')->insertGetId(array_merge([
            'nuptk' => null,
            'nama' => $name,
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => null,
            'no_handphone' => null,
            'email' => null,
            'alamat' => null,
            'jabatan' => 'guru',
            'username' => $username,
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
