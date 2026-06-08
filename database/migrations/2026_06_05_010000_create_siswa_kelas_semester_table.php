<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'siswa_kelas_semester';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->foreignId('siswa_id')->constrained('siswas')->restrictOnDelete();
                $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
                $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajarans')->restrictOnDelete();
                $table->unsignedTinyInteger('semester');
                $table->timestamps();

                $table->unique(
                    ['siswa_id', 'tahun_ajaran_id', 'semester'],
                    'siswa_kelas_semester_unique_context'
                );
                $table->index(
                    ['kelas_id', 'tahun_ajaran_id', 'semester'],
                    'siswa_kelas_semester_class_context_index'
                );
            });

            return;
        }

        $this->assertCompatibleExistingTable();
    }

    /**
     * Reverse the migrations.
     *
     * This migration intentionally preserves enrollment history on rollback. A
     * future destructive cleanup must be a deliberate data-migration decision.
     */
    public function down(): void
    {
        //
    }

    private function assertCompatibleExistingTable(): void
    {
        $columns = $this->columns();

        $this->assertColumn($columns, 'id', ['integer', 'bigint'], false);
        $this->assertColumn($columns, 'siswa_id', ['integer', 'bigint'], false);
        $this->assertColumn($columns, 'kelas_id', ['integer', 'bigint'], false);
        $this->assertColumn($columns, 'tahun_ajaran_id', ['integer', 'bigint'], false);
        $this->assertColumn($columns, 'semester', ['integer', 'tinyint', 'smallint'], false);
        $this->assertColumn($columns, 'created_at', ['timestamp', 'datetime'], true);
        $this->assertColumn($columns, 'updated_at', ['timestamp', 'datetime'], true);

        $this->assertIndex(['siswa_id', 'tahun_ajaran_id', 'semester'], true);
        $this->assertIndex(['kelas_id', 'tahun_ajaran_id', 'semester'], false);

        $this->assertForeignKey('siswa_id', 'siswas');
        $this->assertForeignKey('kelas_id', 'kelas');
        $this->assertForeignKey('tahun_ajaran_id', 'tahun_ajarans');
    }

    /**
     * @return array<string, array{type: string, nullable: bool, primary: bool}>
     */
    private function columns(): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA table_info('".self::TABLE."')"))
                ->mapWithKeys(fn (object $column) => [
                    $column->name => [
                        'type' => strtolower((string) $column->type),
                        'nullable' => (int) $column->notnull === 0,
                        'primary' => (int) $column->pk === 1,
                    ],
                ])
                ->all();
        }

        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.columns')
            ->select([
                'COLUMN_NAME as column_name',
                'COLUMN_TYPE as column_type',
                'IS_NULLABLE as is_nullable',
                'COLUMN_KEY as column_key',
            ])
            ->where('table_schema', $database)
            ->where('table_name', self::TABLE)
            ->get()
            ->mapWithKeys(fn (object $column) => [
                $column->column_name => [
                    'type' => strtolower((string) $column->column_type),
                    'nullable' => $column->is_nullable === 'YES',
                    'primary' => $column->column_key === 'PRI',
                ],
            ])
            ->all();
    }

    /**
     * @param  array<string, array{type: string, nullable: bool, primary: bool}>  $columns
     * @param  array<int, string>  $acceptedTypes
     */
    private function assertColumn(array $columns, string $column, array $acceptedTypes, bool $nullable): void
    {
        if (! isset($columns[$column])) {
            throw new RuntimeException('Existing '.self::TABLE." table is missing required column [{$column}].");
        }

        $typeMatches = collect($acceptedTypes)->contains(
            fn (string $type) => str_contains($columns[$column]['type'], $type)
        );

        if (! $typeMatches) {
            throw new RuntimeException(
                'Existing '.self::TABLE." column [{$column}] has incompatible type [{$columns[$column]['type']}]."
            );
        }

        if (! $nullable && $columns[$column]['nullable'] && ! $columns[$column]['primary']) {
            throw new RuntimeException('Existing '.self::TABLE." column [{$column}] must be NOT NULL.");
        }
    }

    /**
     * @param  array<int, string>  $expectedColumns
     */
    private function assertIndex(array $expectedColumns, bool $unique): void
    {
        $indexes = DB::connection()->getDriverName() === 'sqlite'
            ? $this->sqliteIndexes()
            : $this->mysqlIndexes();

        $found = collect($indexes)->contains(function (array $index) use ($expectedColumns, $unique) {
            return $index['columns'] === $expectedColumns
                && (! $unique || $index['unique']);
        });

        if (! $found) {
            $type = $unique ? 'unique constraint' : 'index';
            throw new RuntimeException(
                'Existing '.self::TABLE." table is missing required {$type} on [".implode(', ', $expectedColumns).'].'
            );
        }
    }

    /**
     * @return array<int, array{columns: array<int, string>, unique: bool}>
     */
    private function mysqlIndexes(): array
    {
        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->select([
                'INDEX_NAME as index_name',
                'COLUMN_NAME as column_name',
                'SEQ_IN_INDEX as seq_in_index',
                'NON_UNIQUE as non_unique',
            ])
            ->where('table_schema', $database)
            ->where('table_name', self::TABLE)
            ->orderBy('index_name')
            ->orderBy('seq_in_index')
            ->get()
            ->groupBy('index_name')
            ->map(fn ($rows) => [
                'columns' => $rows->pluck('column_name')->values()->all(),
                'unique' => (int) $rows->first()->non_unique === 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{columns: array<int, string>, unique: bool}>
     */
    private function sqliteIndexes(): array
    {
        return collect(DB::select("PRAGMA index_list('".self::TABLE."')"))
            ->map(function (object $index) {
                $columns = collect(DB::select("PRAGMA index_info('{$index->name}')"))
                    ->sortBy('seqno')
                    ->pluck('name')
                    ->values()
                    ->all();

                return [
                    'columns' => $columns,
                    'unique' => (bool) $index->unique,
                ];
            })
            ->all();
    }

    private function assertForeignKey(string $column, string $referencedTable): void
    {
        $foreignKeys = DB::connection()->getDriverName() === 'sqlite'
            ? $this->sqliteForeignKeys()
            : $this->mysqlForeignKeys();

        $foreignKey = collect($foreignKeys)->first(
            fn (array $key) => $key['column'] === $column && $key['referenced_table'] === $referencedTable
        );

        if (! $foreignKey) {
            throw new RuntimeException(
                'Existing '.self::TABLE." table is missing required foreign key from [{$column}] to [{$referencedTable}]."
            );
        }

        if (strtoupper($foreignKey['delete_rule']) === 'CASCADE') {
            throw new RuntimeException(
                'Existing '.self::TABLE." foreign key [{$column}] uses CASCADE delete, which is unsafe for enrollment history."
            );
        }
    }

    /**
     * @return array<int, array{column: string, referenced_table: string, delete_rule: string}>
     */
    private function mysqlForeignKeys(): array
    {
        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.key_column_usage as kcu')
            ->leftJoin('information_schema.referential_constraints as rc', function ($join) use ($database) {
                $join->on('kcu.constraint_schema', '=', 'rc.constraint_schema')
                    ->on('kcu.constraint_name', '=', 'rc.constraint_name')
                    ->where('rc.constraint_schema', $database);
            })
            ->select([
                'kcu.COLUMN_NAME as column_name',
                'kcu.REFERENCED_TABLE_NAME as referenced_table_name',
                'rc.DELETE_RULE as delete_rule',
            ])
            ->where('kcu.table_schema', $database)
            ->where('kcu.table_name', self::TABLE)
            ->whereNotNull('kcu.referenced_table_name')
            ->get()
            ->map(fn (object $key) => [
                'column' => $key->column_name,
                'referenced_table' => $key->referenced_table_name,
                'delete_rule' => $key->delete_rule ?: 'RESTRICT',
            ])
            ->all();
    }

    /**
     * @return array<int, array{column: string, referenced_table: string, delete_rule: string}>
     */
    private function sqliteForeignKeys(): array
    {
        return collect(DB::select("PRAGMA foreign_key_list('".self::TABLE."')"))
            ->map(fn (object $key) => [
                'column' => $key->from,
                'referenced_table' => $key->table,
                'delete_rule' => $key->on_delete ?: 'NO ACTION',
            ])
            ->all();
    }
};
