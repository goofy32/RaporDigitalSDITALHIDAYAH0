<?php
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune')->daily();
Schedule::command('cleanup:soft-deletes')->daily();
Schedule::command('reports:cleanup-batch-docx')->daily();
;
