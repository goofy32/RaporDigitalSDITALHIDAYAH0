<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasCrossProviderCollision = DB::table('users')
            ->join('gurus', function ($join): void {
                $join->where(function ($query): void {
                    $query->whereRaw('LOWER(users.username) = LOWER(gurus.username)')
                        ->orWhereRaw('LOWER(users.username) = LOWER(gurus.email)')
                        ->orWhereRaw('LOWER(users.email) = LOWER(gurus.username)')
                        ->orWhereRaw('LOWER(users.email) = LOWER(gurus.email)');
                });
            })
            ->exists();

        $hasGuruCollision = DB::table('gurus as first_guru')
            ->join('gurus as second_guru', function ($join): void {
                $join->on('first_guru.id', '<', 'second_guru.id')
                    ->where(function ($query): void {
                        $query->whereRaw('LOWER(first_guru.username) = LOWER(second_guru.username)')
                            ->orWhereRaw('LOWER(first_guru.username) = LOWER(second_guru.email)')
                            ->orWhereRaw('LOWER(first_guru.email) = LOWER(second_guru.username)')
                            ->orWhereRaw('LOWER(first_guru.email) = LOWER(second_guru.email)');
                    });
            })
            ->exists();

        if ($hasCrossProviderCollision || $hasGuruCollision) {
            throw new RuntimeException('Guru email recovery cannot be enabled while Admin and Guru login identifiers overlap.');
        }

        Schema::table('gurus', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        Schema::create('guru_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guru_password_reset_tokens');

        Schema::table('gurus', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
    }
};
