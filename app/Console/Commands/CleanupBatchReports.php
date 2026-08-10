<?php

namespace App\Console\Commands;

use App\Services\BatchDocxArchiveService;
use Illuminate\Console\Command;

class CleanupBatchReports extends Command
{
    protected $signature = 'reports:cleanup-batch-docx';

    protected $description = 'Remove expired private batch DOCX report workspaces.';

    public function handle(BatchDocxArchiveService $archives): int
    {
        $deleted = $archives->cleanupExpired();

        $this->info("Workspace batch rapor kedaluwarsa yang dihapus: {$deleted}.");

        return self::SUCCESS;
    }
}
