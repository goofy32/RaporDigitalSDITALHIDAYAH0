<?php

namespace App\Services;

use App\Models\TahunAjaran;
use Illuminate\Support\Collection;

class TahunAjaranContext
{
    private bool $initialized = false;

    private ?TahunAjaran $selected = null;

    private ?TahunAjaran $systemActive = null;

    private ?TahunAjaran $latest = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\TahunAjaran> */
    private Collection $selector;

    /** @var \Illuminate\Support\Collection<int, \App\Models\TahunAjaran> */
    private Collection $selectorIncludingArchived;

    private bool $showArchived = false;

    public function __construct()
    {
        $this->selector = collect();
        $this->selectorIncludingArchived = collect();
    }

    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\TahunAjaran> $selector
     * @param \Illuminate\Support\Collection<int, \App\Models\TahunAjaran> $selectorIncludingArchived
     */
    public function initialize(
        ?TahunAjaran $selected,
        ?TahunAjaran $systemActive,
        ?TahunAjaran $latest,
        Collection $selector,
        Collection $selectorIncludingArchived,
        bool $showArchived
    ): void {
        $this->initialized = true;
        $this->selected = $selected;
        $this->systemActive = $systemActive;
        $this->latest = $latest;
        $this->selector = $selector;
        $this->selectorIncludingArchived = $selectorIncludingArchived;
        $this->showArchived = $showArchived;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function selected(): ?TahunAjaran
    {
        return $this->selected;
    }

    public function selectedId(): ?int
    {
        return $this->selected?->id;
    }

    public function semester(): ?int
    {
        return $this->selected?->semester;
    }

    public function systemActive(): ?TahunAjaran
    {
        return $this->systemActive;
    }

    public function latest(): ?TahunAjaran
    {
        return $this->latest;
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\TahunAjaran>
     */
    public function selector(): Collection
    {
        return $this->selector;
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\TahunAjaran>
     */
    public function selectorIncludingArchived(): Collection
    {
        return $this->selectorIncludingArchived;
    }

    public function showArchived(): bool
    {
        return $this->showArchived;
    }

    public function hasActiveTahunAjaran(): bool
    {
        return $this->systemActive !== null;
    }

    public function hasAnyTahunAjaran(): bool
    {
        return $this->selectorIncludingArchived->isNotEmpty();
    }
}
