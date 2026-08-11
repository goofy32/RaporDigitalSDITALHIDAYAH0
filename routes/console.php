<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:cleanup-batch-docx')->daily();
