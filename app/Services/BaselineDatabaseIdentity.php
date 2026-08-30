<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BaselineDatabaseIdentity
{
    /**
     * @return array{configured: string, physical: string}
     */
    public function inspect(): array
    {
        $connection = DB::connection();
        $identity = $connection->selectOne('SELECT DATABASE() AS physical_database');

        return [
            'configured' => (string) ($connection->getConfig('database') ?? ''),
            'physical' => (string) ($identity->physical_database ?? ''),
        ];
    }
}
