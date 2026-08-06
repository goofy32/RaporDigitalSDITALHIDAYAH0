<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Absensi;
use App\Models\Ekstrakurikuler;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\LingkupMateri;
use App\Models\MataPelajaran;
use App\Models\Nilai;
use App\Services\ScoreAggregateRecalculationService;
use App\Models\NilaiEkstrakurikuler;
use App\Models\Prestasi;
use App\Models\Siswa;
use App\Models\TujuanPembelajaran;
use App\Models\User;
use App\Services\SiswaPurgeException;
use App\Services\SiswaPurgeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecycleBinController extends Controller
{
    public function index(Request $request)
    {
        $flatItems = $this->collectDeletedItems($request);
        $items = $this->groupDeletedItems($flatItems);

        $totalItems = $flatItems->count();
        $expiringSoonCount = $flatItems->filter(function (array $item) {
            return $item['expires_at']->isFuture() && $item['expires_at']->lessThanOrEqualTo(now()->addDays(7));
        })->count();

        $paginatedItems = $this->paginateCollection($items, 15, $request);
        $deletedByMap = $this->resolveDeletedByMap($this->flattenItems($paginatedItems->getCollection()));

        $paginatedItems->setCollection(
            $paginatedItems->getCollection()->map(fn (array $item) => $this->attachDeletedBy($item, $deletedByMap))
        );

        return view('admin.recycle_bin.index', [
            'items' => $paginatedItems,
            'totalItems' => $totalItems,
            'expiringSoonCount' => $expiringSoonCount,
            'typeOptions' => collect($this->typeMap())->mapWithKeys(fn (array $config, string $type) => [$type => $config['label']]),
            'filters' => [
                'type' => $request->string('type')->toString(),
                'date_from' => $request->string('date_from')->toString(),
                'date_to' => $request->string('date_to')->toString(),
                'search' => $request->string('search')->toString(),
            ],
        ]);
    }

    public function restore(Request $request, string $type, int $id): JsonResponse|RedirectResponse
    {
        try {
            $message = DB::transaction(fn () => match ($type) {
                'kelas' => $this->restoreKelas($id),
                'lingkup-materi' => $this->restoreLingkupMateri($id),
                'mata-pelajaran' => $this->restoreMataPelajaran($id),
                'tujuan-pembelajaran' => $this->restoreTujuanPembelajaran($id),
                'ekstrakurikuler' => $this->restoreEkstrakurikuler($id),
                'prestasi' => $this->restorePrestasi($id),
                'absensi' => $this->restoreAbsensi($id),
                'siswa' => $this->restoreSiswa($id),
                'guru' => $this->restoreGuru($id),
                default => abort(404),
            });

            return $this->successResponse($request, $message);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($request, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('[RecycleBinController] Restore failed', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id() ?? auth()->guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->errorResponse($request, 'Terjadi kesalahan saat memulihkan data. Silakan coba lagi.', 500);
        }
    }

    public function forceDelete(Request $request, string $type, int $id): JsonResponse|RedirectResponse
    {
        try {
            if ($type === 'siswa') {
                $purgeService = app(SiswaPurgeService::class);
                $purgeResult = $purgeService->purge(
                    $id,
                    (string) $request->input('purge_confirmation', '')
                );
                $cleanupComplete = $purgeService->runPostCommitCleanupSafely($purgeResult);
                $message = $cleanupComplete
                    ? 'Siswa '.$purgeResult['siswa_name'].' berhasil dihapus permanen.'
                    : SiswaPurgeService::FILE_CLEANUP_WARNING;

                return $this->successResponse($request, $message);
            }

            $message = $this->forceDeleteItem($type, $id);

            return $this->successResponse($request, $message);
        } catch (SiswaPurgeException $e) {
            Log::warning('[RecycleBinController] Siswa purge blocked', [
                'type' => $type,
                'id' => $id,
                'context' => $e->context(),
                'user_id' => auth()->id() ?? auth()->guard('guru')->id(),
            ]);

            return $this->errorResponse($request, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            if ($type === 'siswa') {
                Log::error('[RecycleBinController] Siswa purge failed', [
                    'type' => $type,
                    'id' => $id,
                    'exception_class' => get_class($e),
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id() ?? auth()->guard('guru')->id(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return $this->errorResponse($request, 'Terjadi kesalahan saat menghapus permanen data. Silakan coba lagi.', 500);
            }

            return $this->errorResponse($request, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('[RecycleBinController] Force delete failed', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id() ?? auth()->guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->errorResponse($request, 'Terjadi kesalahan saat menghapus permanen data. Silakan coba lagi.', 500);
        }
    }

    public function forceDeleteAll(Request $request): JsonResponse|RedirectResponse
    {
        $items = collect($request->input('items', []))
            ->filter()
            ->values();

        $request->validate([
            'confirmation' => 'required|string|in:'.SiswaPurgeService::BULK_CONFIRMATION,
        ]);

        try {
            $targets = $items->isEmpty()
                ? $this->allForceDeleteTargets()
                : $this->selectedForceDeleteTargets($items);

            $summary = $this->processBulkForceDeleteTargets(
                $targets,
                (string) $request->input('confirmation', '')
            );
            $message = $this->bulkForceDeleteMessage($summary);

            if (
                $summary['deleted_count'] === 0
                && $summary['skipped_already_deleted_count'] === 0
                && $summary['failed_count'] > 0
            ) {
                return $this->errorResponse($request, $message, 422);
            }

            return $this->successResponse($request, $message);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($request, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('[RecycleBinController] Bulk force delete failed', [
                'items' => $items->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id() ?? auth()->guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->errorResponse($request, 'Terjadi kesalahan saat menghapus data dari recycle bin. Silakan coba lagi.', 500);
        }
    }

    protected function allForceDeleteTargets(): Collection
    {
        $targets = collect();

        foreach (array_keys($this->typeMap()) as $type) {
            $modelClass = $this->typeMap()[$type]['class'];

            $modelClass::onlyTrashed()
                ->select('id')
                ->orderBy('id')
                ->get()
                ->each(function (Model $model) use ($type, $targets) {
                    $targets->push([
                        'type' => $type,
                        'id' => (int) $model->getKey(),
                    ]);
                });
        }

        return $this->normalizeBulkForceDeleteTargets($targets);
    }

    protected function selectedForceDeleteTargets(Collection $items): Collection
    {
        return $items
            ->map(function (string $item) {
                [$type, $id] = array_pad(explode(':', $item, 2), 2, null);

                return [
                    'type' => (string) $type,
                    'id' => (int) $id,
                ];
            })
            ->filter(fn (array $target) => $target['type'] !== '' && $target['id'] > 0)
            ->values();
    }

    protected function processBulkForceDeleteTargets(Collection $targets, string $bulkConfirmation): array
    {
        $targets = $this->normalizeBulkForceDeleteTargets($targets);

        $summary = [
            'deleted_count' => 0,
            'skipped_already_deleted_count' => 0,
            'failed_count' => 0,
            'cleanup_warning_count' => 0,
            'failure_reasons' => [],
            'skipped_reasons' => [],
        ];

        $siswaIds = $targets
            ->filter(fn (array $target) => $target['type'] === 'siswa')
            ->pluck('id')
            ->all();

        if ($siswaIds !== []) {
            $siswaSummary = app(SiswaPurgeService::class)->purgeBulk($siswaIds, $bulkConfirmation);

            foreach ($siswaSummary['successes'] as $success) {
                $summary['deleted_count']++;

                if (! ($success['cleanup_complete'] ?? true)) {
                    $summary['cleanup_warning_count']++;
                }
            }

            foreach ($siswaSummary['failures'] as $failure) {
                $summary['failed_count']++;
                $summary['failure_reasons'][] = $failure['message'];

                Log::warning('[RecycleBinController] Bulk Siswa purge item failed', [
                    'siswa_id' => $failure['siswa_id'] ?? null,
                    'message' => $failure['message'] ?? null,
                    'context' => $failure['context'] ?? [],
                    'user_id' => auth()->id() ?? auth()->guard('guru')->id(),
                ]);
            }
        }

        $targets
            ->reject(fn (array $target) => $target['type'] === 'siswa')
            ->each(function (array $target) use (&$summary) {
                try {
                    $result = $this->forceDeleteBulkItem($target['type'], (int) $target['id']);

                    if ($result['status'] === 'deleted') {
                        $summary['deleted_count']++;

                        return;
                    }

                    if ($result['status'] === 'skipped_already_deleted') {
                        $summary['skipped_already_deleted_count']++;
                        $summary['skipped_reasons'][] = [
                            'type' => $target['type'],
                            'id' => $target['id'],
                            'reason' => 'missing_before_processing_or_deleted_by_parent_cascade',
                        ];
                    }
                } catch (\Throwable $exception) {
                    $summary['failed_count']++;
                    $summary['failure_reasons'][] = $this->friendlyBulkFailureMessage($exception);

                    Log::warning('[RecycleBinController] Bulk force delete item failed', [
                        'type' => $target['type'],
                        'id' => $target['id'],
                        'exception_class' => get_class($exception),
                        'error' => $exception->getMessage(),
                        'user_id' => auth()->id() ?? auth()->guard('guru')->id(),
                    ]);
                }
            });

        return $summary;
    }

    protected function normalizeBulkForceDeleteTargets(Collection $targets): Collection
    {
        return $targets
            ->map(function (array $target, int $index) {
                return [
                    'type' => (string) ($target['type'] ?? ''),
                    'id' => (int) ($target['id'] ?? 0),
                    '_bulk_index' => $index,
                ];
            })
            ->filter(fn (array $target) => $target['type'] !== '' && $target['id'] > 0)
            ->unique(fn (array $target) => $target['type'].':'.$target['id'])
            ->sort(function (array $left, array $right) {
                $priority = $this->bulkForceDeletePriority($left['type'])
                    <=> $this->bulkForceDeletePriority($right['type']);

                return $priority !== 0
                    ? $priority
                    : $left['_bulk_index'] <=> $right['_bulk_index'];
            })
            ->map(fn (array $target) => [
                'type' => $target['type'],
                'id' => $target['id'],
            ])
            ->values();
    }

    protected function bulkForceDeletePriority(string $type): int
    {
        return match ($type) {
            'kelas' => 10,
            'mata-pelajaran' => 20,
            'lingkup-materi' => 30,
            'tujuan-pembelajaran' => 40,
            default => 50,
        };
    }

    protected function bulkForceDeleteMessage(array $summary): string
    {
        $parts = [
            $summary['deleted_count'].' data berhasil dihapus permanen.',
        ];

        if (($summary['skipped_already_deleted_count'] ?? 0) > 0) {
            $parts[] = $summary['skipped_already_deleted_count'].' data dilewati karena sudah ikut terhapus atau tidak lagi tersedia.';
        }

        if ($summary['cleanup_warning_count'] > 0) {
            $parts[] = $summary['cleanup_warning_count'].' data berhasil dihapus, tetapi ada file atau cache rapor yang perlu dibersihkan oleh administrator sistem.';
        }

        if ($summary['failed_count'] > 0) {
            $reasons = collect($summary['failure_reasons'])
                ->take(3)
                ->implode('; ');

            if ($summary['failed_count'] > 3) {
                $reasons .= '; dan '.($summary['failed_count'] - 3).' data lainnya.';
            }

            $parts[] = $summary['failed_count'].' data gagal: '.Str::limit($reasons, 300);
        }

        return implode(' ', $parts);
    }

    protected function bulkFailureLabel(array $target): string
    {
        $label = $this->typeMap()[$target['type']]['label'] ?? Str::title(str_replace('-', ' ', $target['type']));

        return $label.' #'.$target['id'];
    }

    protected function friendlyBulkFailureMessage(\Throwable $exception): string
    {
        if ($exception instanceof \RuntimeException && $exception->getMessage() === 'Tipe data tidak valid.') {
            return $exception->getMessage();
        }

        return 'Gagal diproses. Silakan cek data terkait.';
    }

    protected function collectDeletedItems(Request $request): Collection
    {
        $selectedType = $request->string('type')->toString();
        $search = trim($request->string('search')->toString());
        $dateFrom = $request->string('date_from')->toString();
        $dateTo = $request->string('date_to')->toString();

        $items = collect();

        foreach ($this->typeMap() as $type => $config) {
            if ($selectedType && $selectedType !== $type) {
                continue;
            }

            $query = $config['class']::onlyTrashed()
                ->select($this->getSelectColumnsForType($type));

            if (!empty($config['with'])) {
                $query->with($config['with']);
            }

            if ($dateFrom) {
                $query->whereDate('deleted_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('deleted_at', '<=', $dateTo);
            }

            if ($search !== '') {
                if (isset($config['search_callback'])) {
                    ($config['search_callback'])($query, $search);
                } else {
                    $query->where(function ($builder) use ($config, $search) {
                        foreach ($config['search_fields'] as $index => $field) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $builder->{$method}($field, 'LIKE', "%{$search}%");
                        }
                    });
                }
            }

            $records = $query
                ->orderByDesc('deleted_at')
                ->limit(100)
                ->get();

            $items = $items->concat($records->map(function (Model $record) use ($type, $config) {
                $siswaConfirmation = $type === 'siswa' && $record instanceof Siswa
                    ? app(SiswaPurgeService::class)->confirmationPhrase($record)
                    : null;

                return [
                    'id' => $record->getKey(),
                    'type' => $type,
                    'type_label' => $config['label'],
                    'model_type' => $config['class'],
                    'audit_key' => $config['class'] . ':' . $record->getKey(),
                    'name' => $this->resolveDisplayName($type, $record),
                    'description' => $this->resolveDescription($type, $record),
                    'deleted_at' => $record->deleted_at,
                    'expires_at' => $record->deleted_at->copy()->addDays(60),
                    'parent_type' => $this->resolveParentType($type),
                    'parent_id' => $this->resolveParentId($type, $record),
                    'force_delete_confirmation' => $siswaConfirmation,
                    'force_delete_note' => $siswaConfirmation
                        ? 'Hapus permanen siswa akan membersihkan enrollment dan riwayat akademik milik siswa ini. Kelas, guru, akun, dan tahun ajaran tidak ikut dihapus.'
                        : null,
                    'children' => [],
                ];
            }));
        }

        return $items->sortByDesc('deleted_at')->values();
    }

    protected function getSelectColumnsForType(string $type): array
    {
        return match ($type) {
            'kelas' => ['id', 'nomor_kelas', 'nama_kelas', 'tahun_ajaran_id', 'deleted_at'],
            'mata-pelajaran' => ['id', 'nama_pelajaran', 'semester', 'deleted_at'],
            'lingkup-materi' => ['id', 'judul_lingkup_materi', 'mata_pelajaran_id', 'deleted_at'],
            'tujuan-pembelajaran' => ['id', 'kode_tp', 'deskripsi_tp', 'lingkup_materi_id', 'deleted_at'],
            'ekstrakurikuler' => ['id', 'nama_ekstrakurikuler', 'pembina', 'deleted_at'],
            'prestasi' => ['id', 'siswa_id', 'kelas_id', 'jenis_prestasi', 'keterangan', 'deleted_at'],
            'absensi' => ['id', 'siswa_id', 'semester', 'sakit', 'izin', 'tanpa_keterangan', 'deleted_at'],
            'siswa' => ['id', 'nama', 'nis', 'nisn', 'deleted_at'],
            'guru' => ['id', 'nama', 'nuptk', 'username', 'email', 'deleted_at'],
            default => ['id', 'deleted_at'],
        };
    }

    protected function paginateCollection(Collection $items, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        $results = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $results,
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    protected function resolveDeletedByMap(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $itemsByModel = $items->groupBy('model_type')->map(function (Collection $group) {
            return $group->pluck('id')->unique()->values()->all();
        });

        $logs = AuditLog::query()
            ->whereIn('action', ['deleted', 'cascade_delete_snapshot'])
            ->where(function ($query) use ($itemsByModel) {
                foreach ($itemsByModel as $modelType => $ids) {
                    $query->orWhere(function ($subQuery) use ($modelType, $ids) {
                        $subQuery->where('model_type', $modelType)->whereIn('model_id', $ids);
                    });
                }
            })
            ->latest()
            ->get();

        $map = [];

        foreach ($logs as $log) {
            $key = $log->model_type . ':' . $log->model_id;

            if (!isset($map[$key])) {
                $map[$key] = $this->formatDeletedBy($log);
            }
        }

        return $map;
    }

    protected function flattenItems(Collection $items): Collection
    {
        $flattened = collect();

        foreach ($items as $item) {
            $flattened->push($item);

            if (!empty($item['children'])) {
                $flattened = $flattened->concat($this->flattenItems(collect($item['children'])));
            }
        }

        return $flattened;
    }

    protected function attachDeletedBy(array $item, array $deletedByMap): array
    {
        $item['deleted_by'] = $deletedByMap[$item['audit_key']] ?? 'Tidak diketahui';
        $item['children'] = collect($item['children'] ?? [])
            ->map(fn (array $child) => $this->attachDeletedBy($child, $deletedByMap))
            ->all();

        return $item;
    }

    protected function groupDeletedItems(Collection $items): Collection
    {
        $itemsByKey = [];
        $topLevelKeys = [];

        foreach ($items as $item) {
            $key = $this->itemKey($item['type'], $item['id']);
            $itemsByKey[$key] = $item;
            $topLevelKeys[$key] = true;
        }

        $keys = array_keys($itemsByKey);
        usort($keys, function (string $a, string $b) use ($itemsByKey) {
            return $this->itemDepth($itemsByKey[$b]['type']) <=> $this->itemDepth($itemsByKey[$a]['type']);
        });

        foreach ($keys as $key) {
            $item = $itemsByKey[$key];
            $parentKey = $this->resolveParentKey($item);

            if ($parentKey && isset($itemsByKey[$parentKey])) {
                $itemsByKey[$parentKey]['children'][] = $item;
                unset($topLevelKeys[$key]);
            }
        }

        foreach ($itemsByKey as &$item) {
            if (!empty($item['children'])) {
                usort($item['children'], fn (array $a, array $b) => $b['deleted_at']->timestamp <=> $a['deleted_at']->timestamp);
            }
        }
        unset($item);

        return collect(array_keys($topLevelKeys))
            ->map(fn (string $key) => $itemsByKey[$key])
            ->sortByDesc('deleted_at')
            ->values();
    }

    protected function itemKey(string $type, int $id): string
    {
        return "{$type}:{$id}";
    }

    protected function resolveParentKey(array $item): ?string
    {
        if (empty($item['parent_type']) || empty($item['parent_id'])) {
            return null;
        }

        return $this->itemKey($item['parent_type'], (int) $item['parent_id']);
    }

    protected function itemDepth(string $type): int
    {
        return match ($type) {
            'tujuan-pembelajaran' => 2,
            'lingkup-materi' => 1,
            default => 0,
        };
    }

    protected function resolveParentType(string $type): ?string
    {
        return match ($type) {
            'lingkup-materi' => 'mata-pelajaran',
            'tujuan-pembelajaran' => 'lingkup-materi',
            default => null,
        };
    }

    protected function resolveParentId(string $type, Model $record): ?int
    {
        return match ($type) {
            'lingkup-materi' => (int) $record->mata_pelajaran_id,
            'tujuan-pembelajaran' => (int) $record->lingkup_materi_id,
            default => null,
        };
    }

    protected function formatDeletedBy(AuditLog $log): string
    {
        if (!$log->user_type || !$log->user_id) {
            return 'System';
        }

        if ($log->user_type === User::class) {
            return 'Admin: ' . (User::find($log->user_id)?->name ?? 'Unknown');
        }

        if ($log->user_type === Guru::class) {
            return 'Guru: ' . (Guru::withTrashed()->find($log->user_id)?->nama ?? 'Unknown');
        }

        return class_basename($log->user_type) . ' #' . $log->user_id;
    }

    protected function restoreLingkupMateri(int $id): string
    {
        $lingkupMateri = LingkupMateri::onlyTrashed()->find($id);

        if (!$lingkupMateri) {
            return 'Lingkup Materi sudah dipulihkan.';
        }

        $mataPelajaran = MataPelajaran::withTrashed()->find($lingkupMateri->mata_pelajaran_id);

        if ($mataPelajaran && $mataPelajaran->trashed()) {
            throw new \RuntimeException('Mata Pelajaran induk masih ada di recycle bin. Pulihkan Mata Pelajaran terlebih dahulu.');
        }

        $lingkupMateri->restore();

        $restoredTp = 0;
        $restoredNilai = 0;
        TujuanPembelajaran::onlyTrashed()
            ->where('lingkup_materi_id', $lingkupMateri->id)
            ->get()
            ->each(function (TujuanPembelajaran $tujuanPembelajaran) use (&$restoredTp) {
                $tujuanPembelajaran->restore();
                $restoredTp++;
            });

        $restoredNilai += $this->restoreNilaiForLingkupMateri($lingkupMateri);

        return "Lingkup Materi berhasil dipulihkan beserta {$restoredTp} Tujuan Pembelajaran dan {$restoredNilai} Nilai.";
    }

    protected function restoreKelas(int $id): string
    {
        $kelas = Kelas::onlyTrashed()->find($id);

        if (!$kelas) {
            return 'Kelas sudah dipulihkan.';
        }

        $kelas->restore();

        $snapshot = AuditLog::query()
            ->where('action', 'cascade_delete_snapshot')
            ->where('model_type', Kelas::class)
            ->where('model_id', $kelas->id)
            ->latest()
            ->first();

        $snapshotValues = $snapshot?->old_values ?? [];

        $restoredGuruAssignments = $this->restoreKelasGuruAssignments($kelas, $snapshotValues);

        $studentIds = collect(data_get($snapshotValues, 'siswa_ids', []))
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->values();

        $restoredStudentIds = [];
        $restoredStudentCount = 0;
        $studentQuery = Siswa::onlyTrashed();
        $studentIds->isNotEmpty()
            ? $studentQuery->whereIn('id', $studentIds->all())
            : $studentQuery->where('kelas_id', $kelas->id);

        $studentQuery
            ->get()
            ->each(function (Siswa $siswa) use (&$restoredStudentIds, &$restoredStudentCount) {
                $siswa->restore();
                $restoredStudentIds[] = $siswa->id;
                $restoredStudentCount++;
            });

        $absensiIds = collect(data_get($snapshotValues, 'absensi_ids', []))
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->values();
        $restoredAbsensiCount = 0;
        if ($absensiIds->isNotEmpty() || !empty($restoredStudentIds)) {
            $absensiQuery = Absensi::onlyTrashed();
            $absensiIds->isNotEmpty()
                ? $absensiQuery->whereIn('id', $absensiIds->all())
                : $absensiQuery->whereIn('siswa_id', $restoredStudentIds);

            $absensiQuery
                ->get()
                ->each(function (Absensi $absensi) use (&$restoredAbsensiCount) {
                    $absensi->restore();
                    $restoredAbsensiCount++;
                });
        }

        $prestasiIds = collect(data_get($snapshotValues, 'prestasi_ids', []))
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->values();
        $restoredPrestasiCount = 0;
        $prestasiQuery = Prestasi::onlyTrashed();
        $prestasiIds->isNotEmpty()
            ? $prestasiQuery->whereIn('id', $prestasiIds->all())
            : $prestasiQuery->where('kelas_id', $kelas->id);

        $prestasiQuery
            ->get()
            ->each(function (Prestasi $prestasi) use (&$restoredPrestasiCount) {
                $prestasi->restore();
                $restoredPrestasiCount++;
            });

        $restoredMapelCount = 0;
        $restoredLmCount = 0;
        $restoredTpCount = 0;
        $restoredNilaiCount = 0;

        $mataPelajaranIds = collect(data_get($snapshotValues, 'mata_pelajaran_ids', []))
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->values();

        $mataPelajaranQuery = MataPelajaran::onlyTrashed();
        $mataPelajaranIds->isNotEmpty()
            ? $mataPelajaranQuery->whereIn('id', $mataPelajaranIds->all())
            : $mataPelajaranQuery->where('kelas_id', $kelas->id);

        $mataPelajaranQuery
            ->get()
            ->each(function (MataPelajaran $mataPelajaran) use (&$restoredMapelCount, &$restoredLmCount, &$restoredTpCount, &$restoredNilaiCount) {
                $mataPelajaran->restore();
                $restoredMapelCount++;
                $restoredNilaiCount += $this->restoreNilaiForMataPelajaran($mataPelajaran);

                LingkupMateri::onlyTrashed()
                    ->where('mata_pelajaran_id', $mataPelajaran->id)
                    ->get()
                    ->each(function (LingkupMateri $lingkupMateri) use (&$restoredLmCount, &$restoredTpCount, &$restoredNilaiCount) {
                        $lingkupMateri->restore();
                        $restoredLmCount++;

                        TujuanPembelajaran::onlyTrashed()
                            ->where('lingkup_materi_id', $lingkupMateri->id)
                            ->get()
                            ->each(function (TujuanPembelajaran $tujuanPembelajaran) use (&$restoredTpCount) {
                                $tujuanPembelajaran->restore();
                                $restoredTpCount++;
                            });

                        $restoredNilaiCount += $this->restoreNilaiForLingkupMateri($lingkupMateri);
                    });
            });

        return "Kelas berhasil dipulihkan beserta {$restoredStudentCount} Siswa, {$restoredMapelCount} Mata Pelajaran, {$restoredLmCount} Lingkup Materi, {$restoredTpCount} Tujuan Pembelajaran, {$restoredNilaiCount} Nilai, {$restoredPrestasiCount} Prestasi, {$restoredAbsensiCount} Absensi, dan {$restoredGuruAssignments} relasi guru.";
    }

    protected function restoreMataPelajaran(int $id): string
    {
        $mataPelajaran = MataPelajaran::onlyTrashed()->find($id);

        if (!$mataPelajaran) {
            return 'Mata Pelajaran sudah dipulihkan.';
        }

        $mataPelajaran->restore();

        $restoredLmCount = 0;
        $restoredTpCount = 0;
        $restoredNilaiCount = $this->restoreNilaiForMataPelajaran($mataPelajaran);

        LingkupMateri::onlyTrashed()
            ->where('mata_pelajaran_id', $mataPelajaran->id)
            ->get()
            ->each(function (LingkupMateri $lingkupMateri) use (&$restoredLmCount, &$restoredTpCount, &$restoredNilaiCount) {
                $lingkupMateri->restore();
                $restoredLmCount++;

                TujuanPembelajaran::onlyTrashed()
                    ->where('lingkup_materi_id', $lingkupMateri->id)
                    ->get()
                    ->each(function (TujuanPembelajaran $tujuanPembelajaran) use (&$restoredTpCount) {
                        $tujuanPembelajaran->restore();
                        $restoredTpCount++;
                    });

                $restoredNilaiCount += $this->restoreNilaiForLingkupMateri($lingkupMateri);
            });

        return "Mata Pelajaran berhasil dipulihkan beserta {$restoredLmCount} Lingkup Materi, {$restoredTpCount} Tujuan Pembelajaran, dan {$restoredNilaiCount} Nilai.";
    }

    protected function restoreTujuanPembelajaran(int $id): string
    {
        $tujuanPembelajaran = TujuanPembelajaran::onlyTrashed()->find($id);

        if (!$tujuanPembelajaran) {
            return 'Tujuan Pembelajaran sudah dipulihkan.';
        }

        $lingkupMateri = LingkupMateri::withTrashed()->find($tujuanPembelajaran->lingkup_materi_id);

        if ($lingkupMateri && $lingkupMateri->trashed()) {
            throw new \RuntimeException('Lingkup Materi induk masih ada di recycle bin. Pulihkan Lingkup Materi terlebih dahulu.');
        }

        $tujuanPembelajaran->restore();
        $nilaiQuery = Nilai::onlyTrashed()
            ->where('tujuan_pembelajaran_id', $tujuanPembelajaran->id);
        $contexts = $this->scoreAggregateContexts((clone $nilaiQuery)->get());
        $restoredNilaiCount = $nilaiQuery->restore();
        app(ScoreAggregateRecalculationService::class)->recalculateMany($contexts);

        if ($restoredNilaiCount > 0) {
            return "Tujuan Pembelajaran berhasil dipulihkan beserta {$restoredNilaiCount} Nilai.";
        }

        return 'Tujuan Pembelajaran berhasil dipulihkan.';
    }

    protected function restoreEkstrakurikuler(int $id): string
    {
        $ekstrakurikuler = Ekstrakurikuler::onlyTrashed()->find($id);

        if (!$ekstrakurikuler) {
            return 'Ekstrakurikuler sudah dipulihkan.';
        }

        $ekstrakurikuler->restore();

        $restoredNilaiCount = 0;
        NilaiEkstrakurikuler::onlyTrashed()
            ->where('ekstrakurikuler_id', $ekstrakurikuler->id)
            ->get()
            ->each(function (NilaiEkstrakurikuler $nilai) use (&$restoredNilaiCount) {
                $nilai->restore();
                $restoredNilaiCount++;
            });

        return "Ekstrakurikuler berhasil dipulihkan beserta {$restoredNilaiCount} nilai ekstrakurikuler.";
    }

    protected function restorePrestasi(int $id): string
    {
        $prestasi = Prestasi::onlyTrashed()->find($id);

        if (!$prestasi) {
            return 'Prestasi sudah dipulihkan.';
        }

        $prestasi->restore();

        return 'Prestasi berhasil dipulihkan.';
    }

    protected function restoreAbsensi(int $id): string
    {
        $absensi = Absensi::onlyTrashed()->find($id);

        if (!$absensi) {
            return 'Absensi sudah dipulihkan.';
        }

        $absensi->restore();

        return 'Absensi berhasil dipulihkan.';
    }

    protected function restoreSiswa(int $id): string
    {
        $siswa = Siswa::onlyTrashed()->find($id);

        if (!$siswa) {
            return 'Data siswa sudah dipulihkan.';
        }

        $siswa->restore();

        return 'Data siswa ' . $siswa->nama . ' berhasil dipulihkan.';
    }

    protected function restoreGuru(int $id): string
    {
        $guru = Guru::onlyTrashed()->find($id);

        if (!$guru) {
            return 'Data guru sudah dipulihkan.';
        }

        $guru->restore();

        return 'Data guru ' . $guru->nama . ' berhasil dipulihkan.';
    }

    protected function forceDeleteItem(string $type, int $id): string
    {
        $config = $this->typeMap()[$type] ?? null;

        if (!$config) {
            throw new \RuntimeException('Tipe data tidak valid.');
        }

        $model = $config['class']::onlyTrashed()->findOrFail($id);
        $name = $this->resolveDisplayName($type, $model);

        $this->forceDeleteModel($type, $model);

        return "{$config['label']} {$name} berhasil dihapus permanen.";
    }

    protected function forceDeleteBulkItem(string $type, int $id): array
    {
        $config = $this->typeMap()[$type] ?? null;

        if (!$config) {
            throw new \RuntimeException('Tipe data tidak valid.');
        }

        $model = $config['class']::onlyTrashed()->find($id);

        if (!$model) {
            return [
                'status' => 'skipped_already_deleted',
            ];
        }

        $this->forceDeleteModel($type, $model);

        return [
            'status' => 'deleted',
        ];
    }

    protected function forceDeleteModel(string $type, Model $model): void
    {
        if ($type === 'siswa' && $model instanceof Siswa) {
            throw new \RuntimeException('Hapus permanen siswa harus menggunakan alur konfirmasi satu data dari recycle bin.');
        }

        if ($type === 'guru' && $model instanceof Guru) {
            $this->deletePhotoIfExists($model->photo);
        }

        $model->forceDelete();
    }

    protected function deletePhotoIfExists(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    protected function restoreKelasGuruAssignments(Kelas $kelas, array $snapshotValues = []): int
    {
        $guruPivots = collect(data_get($snapshotValues, 'guru_pivots', []));
        $restoredCount = 0;

        foreach ($guruPivots as $pivot) {
            $guru = Guru::withTrashed()->find($pivot['guru_id'] ?? null);

            if (!$guru || $guru->trashed()) {
                continue;
            }

            DB::table('guru_kelas')->updateOrInsert(
                [
                    'guru_id' => $guru->id,
                    'kelas_id' => $kelas->id,
                    'role' => $pivot['role'] ?? 'pengajar',
                ],
                [
                    'is_wali_kelas' => (bool) ($pivot['is_wali_kelas'] ?? false),
                    'created_at' => $pivot['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );

            if (($pivot['is_wali_kelas'] ?? false) && ($pivot['role'] ?? null) === 'wali_kelas' && $guru->jabatan !== 'guru_wali') {
                $guru->jabatan = 'guru_wali';
                $guru->save();
            }

            $restoredCount++;
        }

        return $restoredCount;
    }

    protected function restoreNilaiForMataPelajaran(MataPelajaran $mataPelajaran): int
    {
        $query = Nilai::onlyTrashed()->where('mata_pelajaran_id', $mataPelajaran->id);
        $contexts = $this->scoreAggregateContexts((clone $query)->get());
        $restored = $query->restore();
        app(ScoreAggregateRecalculationService::class)->recalculateMany($contexts);

        return $restored;
    }

    protected function restoreNilaiForLingkupMateri(LingkupMateri $lingkupMateri): int
    {
        $tujuanPembelajaranIds = TujuanPembelajaran::where('lingkup_materi_id', $lingkupMateri->id)
            ->pluck('id');

        $query = Nilai::onlyTrashed()->where(function ($query) use ($lingkupMateri, $tujuanPembelajaranIds) {
            $query->where('lingkup_materi_id', $lingkupMateri->id);

            if ($tujuanPembelajaranIds->isNotEmpty()) {
                $query->orWhereIn('tujuan_pembelajaran_id', $tujuanPembelajaranIds->all());
            }
        });

        $contexts = $this->scoreAggregateContexts((clone $query)->get());
        $restoredCount = $query->restore();
        app(ScoreAggregateRecalculationService::class)->recalculateMany($contexts);

        return $restoredCount;
    }

    private function scoreAggregateContexts($nilais): array
    {
        return collect($nilais)
            ->map(fn (Nilai $nilai) => $nilai->only([
                'siswa_id',
                'mata_pelajaran_id',
                'tahun_ajaran_id',
            ]))
            ->unique(fn (array $context) => implode(':', $context))
            ->values()
            ->all();
    }

    protected function resolveDisplayName(string $type, Model $record): string
    {
        return match ($type) {
            'kelas' => $record->full_kelas ?? trim('Kelas ' . $record->nomor_kelas . ' ' . $record->nama_kelas),
            'mata-pelajaran' => $record->nama_pelajaran,
            'lingkup-materi' => $record->judul_lingkup_materi,
            'tujuan-pembelajaran' => trim($record->kode_tp . ' - ' . $record->deskripsi_tp),
            'ekstrakurikuler' => $record->nama_ekstrakurikuler,
            'prestasi' => $record->jenis_prestasi,
            'absensi' => 'Absensi ' . ($record->siswa?->nama ?? ('Siswa #' . $record->siswa_id)),
            'siswa' => $record->nama,
            'guru' => $record->nama,
            default => class_basename($record),
        };
    }

    protected function resolveDescription(string $type, Model $record): string
    {
        return match ($type) {
            'kelas' => 'Tahun ajaran #' . ($record->tahun_ajaran_id ?? '-'),
            'mata-pelajaran' => 'Semester ' . ($record->semester ?? '-'),
            'lingkup-materi' => 'Mata pelajaran #' . $record->mata_pelajaran_id,
            'tujuan-pembelajaran' => $record->deskripsi_tp,
            'ekstrakurikuler' => 'Pembina: ' . ($record->pembina ?? '-'),
            'prestasi' => 'Siswa: ' . ($record->siswa?->nama ?? ('#' . $record->siswa_id)) . ' | Keterangan: ' . ($record->keterangan ?? '-'),
            'absensi' => 'Semester ' . ($record->semester ?? '-') . ' | Sakit: ' . ($record->sakit ?? 0) . ', Izin: ' . ($record->izin ?? 0) . ', Tanpa Keterangan: ' . ($record->tanpa_keterangan ?? 0),
            'siswa' => 'NIS ' . ($record->nis ?? '-'),
            'guru' => 'Username ' . ($record->username ?? '-'),
            default => '',
        };
    }

    protected function successResponse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    protected function errorResponse(Request $request, string $message, int $status = 500): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }

        return redirect()->back()->with('error', $message);
    }

    protected function typeMap(): array
    {
        return [
            'kelas' => [
                'class' => Kelas::class,
                'label' => 'Kelas',
                'search_fields' => ['nama_kelas', 'nomor_kelas'],
            ],
            'mata-pelajaran' => [
                'class' => MataPelajaran::class,
                'label' => 'Mata Pelajaran',
                'search_fields' => ['nama_pelajaran'],
            ],
            'lingkup-materi' => [
                'class' => LingkupMateri::class,
                'label' => 'Lingkup Materi',
                'search_fields' => ['judul_lingkup_materi'],
            ],
            'tujuan-pembelajaran' => [
                'class' => TujuanPembelajaran::class,
                'label' => 'Tujuan Pembelajaran',
                'search_fields' => ['kode_tp', 'deskripsi_tp'],
            ],
            'ekstrakurikuler' => [
                'class' => Ekstrakurikuler::class,
                'label' => 'Ekstrakurikuler',
                'search_fields' => ['nama_ekstrakurikuler', 'pembina'],
            ],
            'prestasi' => [
                'class' => Prestasi::class,
                'label' => 'Prestasi',
                'with' => [
                    'siswa' => fn ($query) => $query->withTrashed(),
                    'kelas' => fn ($query) => $query->withTrashed(),
                ],
                'search_fields' => ['jenis_prestasi', 'keterangan'],
                'search_callback' => function ($query, $search) {
                    $query->where(function ($builder) use ($search) {
                        $builder->where('jenis_prestasi', 'LIKE', "%{$search}%")
                            ->orWhere('keterangan', 'LIKE', "%{$search}%")
                            ->orWhereHas('siswa', fn ($siswaQuery) => $siswaQuery->withTrashed()->where('nama', 'LIKE', "%{$search}%"));
                    });
                },
            ],
            'absensi' => [
                'class' => Absensi::class,
                'label' => 'Absensi',
                'with' => [
                    'siswa' => fn ($query) => $query->withTrashed(),
                ],
                'search_fields' => ['semester'],
                'search_callback' => function ($query, $search) {
                    $query->where(function ($builder) use ($search) {
                        $builder->where('semester', 'LIKE', "%{$search}%")
                            ->orWhereHas('siswa', fn ($siswaQuery) => $siswaQuery->withTrashed()
                                ->where('nama', 'LIKE', "%{$search}%")
                                ->orWhere('nis', 'LIKE', "%{$search}%"));
                    });
                },
            ],
            'siswa' => [
                'class' => Siswa::class,
                'label' => 'Siswa',
                'search_fields' => ['nama', 'nis', 'nisn'],
            ],
            'guru' => [
                'class' => Guru::class,
                'label' => 'Guru',
                'search_fields' => ['nama', 'nuptk', 'username', 'email'],
            ],
        ];
    }
}
