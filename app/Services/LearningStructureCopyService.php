<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\LingkupMateri;
use App\Models\MataPelajaran;
use App\Models\TujuanPembelajaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LearningStructureCopyService
{
    public function copyableSourceCandidates(int $tahunAjaranId, int $semester, ?Guru $guru = null): Collection
    {
        return MataPelajaran::with(['kelas', 'lingkupMateris.tujuanPembelajarans'])
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->whereHas('kelas')
            ->whereHas('lingkupMateris.tujuanPembelajarans')
            ->when($guru, fn ($query) => $query->where('guru_id', $guru->id))
            ->get()
            ->filter(fn (MataPelajaran $candidate) => $candidate->kelas && $candidate->scoreTemplateReadinessMessages() === [])
            ->sortBy(fn (MataPelajaran $candidate) => sprintf(
                '%06d|%s|%s|%010d',
                (int) ($candidate->kelas?->nomor_kelas ?? 999999),
                $this->normalize((string) ($candidate->kelas?->nama_kelas ?? '')),
                $this->normalize($candidate->nama_pelajaran),
                (int) $candidate->id
            ))
            ->values();
    }

    public function sourceCandidatesForContext(
        string $subjectName,
        int $kelasId,
        int $tahunAjaranId,
        int $semester,
        ?Guru $guru = null,
        ?int $excludeSubjectId = null
    ): Collection {
        $targetClass = Kelas::where('id', $kelasId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first();

        if (! $targetClass || trim($subjectName) === '') {
            return collect();
        }

        return $this->copyableSourceCandidates($tahunAjaranId, $semester, $guru)
            ->reject(fn (MataPelajaran $candidate) => $excludeSubjectId && (int) $candidate->id === (int) $excludeSubjectId)
            ->filter(function (MataPelajaran $candidate) use ($subjectName, $targetClass) {
                return (int) $candidate->kelas_id !== (int) $targetClass->id
                    && (int) $candidate->kelas?->nomor_kelas === (int) $targetClass->nomor_kelas
                    && $this->normalize($candidate->nama_pelajaran) === $this->normalize($subjectName);
            })
            ->values();
    }

    public function sourceCandidates(MataPelajaran $target, ?Guru $guru = null): Collection
    {
        $target->loadMissing('kelas');

        if (! $target->kelas || ! $target->tahun_ajaran_id || ! $target->semester) {
            return collect();
        }

        return MataPelajaran::with(['kelas', 'lingkupMateris.tujuanPembelajarans'])
            ->where('id', '!=', $target->id)
            ->where('tahun_ajaran_id', $target->tahun_ajaran_id)
            ->where('semester', $target->semester)
            ->where('nama_pelajaran', $target->nama_pelajaran)
            ->whereHas('kelas', fn ($query) => $query->where('nomor_kelas', $target->kelas->nomor_kelas))
            ->whereHas('lingkupMateris.tujuanPembelajarans')
            ->when($guru, fn ($query) => $query->where('guru_id', $guru->id))
            ->get()
            ->filter(fn (MataPelajaran $candidate) => $candidate->scoreTemplateReadinessMessages() === [] && $this->isSameCopyContext($candidate, $target))
            ->sortBy(fn (MataPelajaran $candidate) => sprintf(
                '%06d|%s|%s|%010d',
                (int) ($candidate->kelas?->nomor_kelas ?? 999999),
                $this->normalize((string) ($candidate->kelas?->nama_kelas ?? '')),
                $this->normalize($candidate->nama_pelajaran),
                (int) $candidate->id
            ))
            ->values();
    }

    public function preview(MataPelajaran $source, MataPelajaran $target): array
    {
        $this->assertValidCopyContext($source, $target);

        $source->loadMissing(['kelas', 'lingkupMateris.tujuanPembelajarans']);
        $target->loadMissing(['kelas', 'lingkupMateris.tujuanPembelajarans']);

        $targetLingkupByTitle = $target->lingkupMateris
            ->keyBy(fn (LingkupMateri $lingkupMateri) => $this->normalize($lingkupMateri->judul_lingkup_materi));

        $items = [];
        $summary = [
            'lm_total' => $source->lingkupMateris->count(),
            'tp_total' => $source->lingkupMateris->sum(fn (LingkupMateri $lm) => $lm->tujuanPembelajarans->count()),
            'lm_to_copy' => 0,
            'lm_skipped' => 0,
            'tp_to_copy' => 0,
            'tp_skipped' => 0,
        ];

        foreach ($source->lingkupMateris->sortBy('id')->values() as $sourceLingkupMateri) {
            $targetLingkupMateri = $targetLingkupByTitle->get($this->normalize($sourceLingkupMateri->judul_lingkup_materi));
            $lingkupWillCopy = ! $targetLingkupMateri;
            $tpItems = [];

            if ($lingkupWillCopy) {
                $summary['lm_to_copy']++;
            } else {
                $summary['lm_skipped']++;
            }

            foreach ($sourceLingkupMateri->tujuanPembelajarans->sortBy('id')->values() as $sourceTujuanPembelajaran) {
                $tpDuplicate = $targetLingkupMateri
                    ? $this->hasDuplicateTujuanPembelajaran($targetLingkupMateri, $sourceTujuanPembelajaran)
                    : false;

                if ($tpDuplicate) {
                    $summary['tp_skipped']++;
                } else {
                    $summary['tp_to_copy']++;
                }

                $tpItems[] = [
                    'kode_tp' => $sourceTujuanPembelajaran->kode_tp,
                    'deskripsi_tp' => $sourceTujuanPembelajaran->deskripsi_tp,
                    'will_copy' => ! $tpDuplicate,
                    'duplicate' => $tpDuplicate,
                ];
            }

            $items[] = [
                'judul_lingkup_materi' => $sourceLingkupMateri->judul_lingkup_materi,
                'will_copy_lm' => $lingkupWillCopy,
                'duplicate_lm' => ! $lingkupWillCopy,
                'tujuan_pembelajarans' => $tpItems,
            ];
        }

        return [
            'source' => $source,
            'target' => $target,
            'summary' => $summary,
            'items' => $items,
            'will_copy_anything' => $summary['lm_to_copy'] > 0 || $summary['tp_to_copy'] > 0,
        ];
    }

    public function copy(MataPelajaran $source, MataPelajaran $target): array
    {
        return DB::transaction(function () use ($source, $target) {
            $preview = $this->preview($source->fresh(), $target->fresh());
            $createdLingkupMateri = [];

            $targetLingkupByTitle = $target->fresh(['lingkupMateris.tujuanPembelajarans'])
                ->lingkupMateris
                ->keyBy(fn (LingkupMateri $lingkupMateri) => $this->normalize($lingkupMateri->judul_lingkup_materi));

            foreach ($source->fresh(['lingkupMateris.tujuanPembelajarans'])->lingkupMateris->sortBy('id')->values() as $sourceLingkupMateri) {
                $targetLingkupMateri = $targetLingkupByTitle->get($this->normalize($sourceLingkupMateri->judul_lingkup_materi));

                if (! $targetLingkupMateri) {
                    $targetLingkupMateri = LingkupMateri::create([
                        'mata_pelajaran_id' => $target->id,
                        'judul_lingkup_materi' => $sourceLingkupMateri->judul_lingkup_materi,
                    ]);
                    $targetLingkupMateri->setRelation('tujuanPembelajarans', collect());
                    $targetLingkupByTitle->put($this->normalize($targetLingkupMateri->judul_lingkup_materi), $targetLingkupMateri);
                    $createdLingkupMateri[] = $targetLingkupMateri->judul_lingkup_materi;
                }

                foreach ($sourceLingkupMateri->tujuanPembelajarans->sortBy('id')->values() as $sourceTujuanPembelajaran) {
                    if ($this->hasDuplicateTujuanPembelajaran($targetLingkupMateri, $sourceTujuanPembelajaran)) {
                        continue;
                    }

                    $createdTp = TujuanPembelajaran::create([
                        'lingkup_materi_id' => $targetLingkupMateri->id,
                        'kode_tp' => $sourceTujuanPembelajaran->kode_tp,
                        'deskripsi_tp' => $sourceTujuanPembelajaran->deskripsi_tp,
                    ]);

                    $targetLingkupMateri->setRelation(
                        'tujuanPembelajarans',
                        $targetLingkupMateri->tujuanPembelajarans->push($createdTp)
                    );
                }
            }

            $afterPreview = $this->preview($source->fresh(), $target->fresh());

            return [
                ...$afterPreview,
                'created_lingkup_materi' => $createdLingkupMateri,
                'copied_lm_count' => $preview['summary']['lm_to_copy'],
                'copied_tp_count' => $preview['summary']['tp_to_copy'],
                'skipped_lm_count' => $preview['summary']['lm_skipped'],
                'skipped_tp_count' => $preview['summary']['tp_skipped'],
            ];
        });
    }

    public function assertValidCopyContext(MataPelajaran $source, MataPelajaran $target): void
    {
        $source->loadMissing('kelas');
        $target->loadMissing('kelas');

        if ((int) $source->id === (int) $target->id) {
            throw new InvalidArgumentException('Pembelajaran sumber dan tujuan harus berbeda.');
        }

        if (
            (int) $source->tahun_ajaran_id !== (int) $target->tahun_ajaran_id
            || (int) $source->semester !== (int) $target->semester
            || $this->normalize($source->nama_pelajaran) !== $this->normalize($target->nama_pelajaran)
        ) {
            throw new InvalidArgumentException('LM/TP hanya dapat disalin dari mata pelajaran yang sama pada tahun ajaran dan semester yang sama.');
        }

        if (! $source->kelas || ! $target->kelas || (int) $source->kelas->nomor_kelas !== (int) $target->kelas->nomor_kelas) {
            throw new InvalidArgumentException('LM/TP hanya dapat disalin antar kelas paralel pada tingkat yang sama.');
        }
    }

    private function isSameCopyContext(MataPelajaran $source, MataPelajaran $target): bool
    {
        try {
            $this->assertValidCopyContext($source, $target);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function hasDuplicateTujuanPembelajaran(LingkupMateri $targetLingkupMateri, TujuanPembelajaran $sourceTujuanPembelajaran): bool
    {
        $sourceCode = $this->normalize((string) $sourceTujuanPembelajaran->kode_tp);
        $sourceDescription = $this->normalize((string) $sourceTujuanPembelajaran->deskripsi_tp);

        return $targetLingkupMateri->tujuanPembelajarans->contains(function (TujuanPembelajaran $targetTujuanPembelajaran) use ($sourceCode, $sourceDescription) {
            $targetCode = $this->normalize((string) $targetTujuanPembelajaran->kode_tp);
            $targetDescription = $this->normalize((string) $targetTujuanPembelajaran->deskripsi_tp);

            if ($sourceCode !== '' && $sourceCode === $targetCode) {
                return true;
            }

            return $sourceDescription !== '' && $sourceDescription === $targetDescription;
        });
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''), 'UTF-8');
    }
}
