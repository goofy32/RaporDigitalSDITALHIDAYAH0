<?php

namespace App\Services;

class SlowRequestMetrics
{
    private int $queryCount = 0;

    private float $databaseMs = 0.0;

    private float $maxQueryMs = 0.0;

    public function recordQuery(float $durationMs): void
    {
        $durationMs = max(0.0, $durationMs);

        $this->queryCount++;
        $this->databaseMs += $durationMs;
        $this->maxQueryMs = max($this->maxQueryMs, $durationMs);
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function databaseMs(): float
    {
        return $this->databaseMs;
    }

    public function maxQueryMs(): float
    {
        return $this->maxQueryMs;
    }
}
