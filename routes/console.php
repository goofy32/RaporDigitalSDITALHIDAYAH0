<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;

Schedule::command('model:prune')->daily();
Schedule::command('cleanup:soft-deletes')->daily();
Schedule::command('reports:cleanup-batch-docx')->daily();


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();
