<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DummyCloneDatabaseIdentity
{
    /**
     * @return array{configured: string, physical: string, current_user: string}
     */
    public function inspect(): array
    {
        $connection = DB::connection();
        $identity = $connection->selectOne(
            'SELECT DATABASE() AS physical_database, CURRENT_USER() AS current_database_user'
        );

        return [
            'configured' => (string) ($connection->getConfig('database') ?? ''),
            'physical' => (string) ($identity->physical_database ?? ''),
            'current_user' => (string) ($identity->current_database_user ?? ''),
        ];
    }
}
