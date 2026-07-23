const STATUS_CLASSES = {
    success: 'text-green-700 bg-green-50',
    processing: 'text-blue-700 bg-blue-50',
    failed: 'text-red-700 bg-red-50',
};

function selectedValues(select) {
    return Array.from(select?.selectedOptions || []).map(option => option.value).filter(Boolean);
}

function setOptions(select, options, placeholder = 'Tidak ada data dummy tersedia') {
    if (!select) return;

    select.innerHTML = '';

    if (!options.length) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholder;
        select.appendChild(option);
        return;
    }

    options.forEach(item => {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = item.label;
        select.appendChild(option);
    });
}

function statusKind(payload) {
    if (payload?.success === false || payload?.status === 'failed') return 'failed';
    if (payload?.status === 'processing' || payload?.status === 'queued') return 'processing';
    return 'success';
}

function renderResult(list, index, label, payload) {
    const li = document.createElement('li');
    const kind = statusKind(payload);
    const badgeClass = STATUS_CLASSES[kind] || STATUS_CLASSES.failed;

    li.className = 'flex flex-col gap-1 p-3 sm:flex-row sm:items-center sm:justify-between';
    li.innerHTML = `
        <div>
            <p class="font-medium text-gray-900">#${index} ${label}</p>
            <p class="text-xs text-gray-500">${payload?.message || 'Tidak ada pesan.'}</p>
        </div>
        <span class="inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-semibold ${badgeClass}">
            ${payload?.status || kind}
        </span>
    `;

    list.appendChild(li);
}

function showMessage(root, message, type = 'info') {
    const box = root.querySelector('[data-simulation-message]');
    if (!box) return;

    const classes = {
        info: 'border-blue-200 bg-blue-50 text-blue-700',
        error: 'border-red-200 bg-red-50 text-red-700',
        success: 'border-green-200 bg-green-50 text-green-700',
    };

    box.className = `mb-3 rounded-md border px-3 py-2 text-sm ${classes[type] || classes.info}`;
    box.textContent = message;
    box.classList.remove('hidden');
}

function resetResults(root) {
    const list = root.querySelector('[data-simulation-results]');
    if (list) list.innerHTML = '';

    root.querySelector('[data-simulation-success]').textContent = '0';
    root.querySelector('[data-simulation-processing]').textContent = '0';
    root.querySelector('[data-simulation-failed]').textContent = '0';

    const box = root.querySelector('[data-simulation-message]');
    box?.classList.add('hidden');
}

function updateCounters(root, payloads) {
    const counts = payloads.reduce((carry, payload) => {
        carry[statusKind(payload)] += 1;
        return carry;
    }, { success: 0, processing: 0, failed: 0 });

    root.querySelector('[data-simulation-success]').textContent = String(counts.success);
    root.querySelector('[data-simulation-processing]').textContent = String(counts.processing);
    root.querySelector('[data-simulation-failed]').textContent = String(counts.failed);
}

function safeCount(input, maxRequests) {
    const count = Number.parseInt(input?.value || '1', 10);

    if (!Number.isFinite(count) || count < 1) return 1;

    return Math.min(count, maxRequests);
}

async function postJson(url, token, payload) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
    });

    const data = await response.json().catch(() => ({
        success: false,
        status: 'failed',
        message: 'Response simulasi tidak dapat dibaca.',
    }));

    return {
        ...data,
        http_status: response.status,
        success: response.ok && data.success !== false,
    };
}

function classOptions(config, yearId) {
    return (config.classes || []).filter(item => String(item.tahun_ajaran_id) === String(yearId) && item.safe);
}

function findClass(config, id) {
    return (config.classes || []).find(item => String(item.id) === String(id));
}

function bindClassFilters(root, config) {
    const pairs = [
        {
            year: root.querySelector('[data-simulation-pdf-year]'),
            kelas: root.querySelector('[data-simulation-pdf-class]'),
            students: root.querySelector('[data-simulation-pdf-students]'),
        },
        {
            year: root.querySelector('[data-simulation-score-year]'),
            kelas: root.querySelector('[data-simulation-score-class]'),
            students: root.querySelector('[data-simulation-score-students]'),
            subjects: root.querySelector('[data-simulation-score-subject]'),
        },
    ];

    pairs.forEach(pair => {
        const refreshClasses = () => {
            const options = classOptions(config, pair.year?.value);
            setOptions(pair.kelas, options, 'Tidak ada kelas dummy/test');
            refreshChildren();
        };

        const refreshChildren = () => {
            const kelas = findClass(config, pair.kelas?.value);
            setOptions(pair.students, kelas?.students || [], 'Tidak ada siswa dummy/test');

            if (pair.subjects) {
                setOptions(pair.subjects, kelas?.subjects || [], 'Tidak ada mapel dummy/test');
            }
        };

        pair.year?.addEventListener('change', refreshClasses);
        pair.kelas?.addEventListener('change', refreshChildren);
        refreshClasses();
    });
}

async function runRequests(root, tasks) {
    resetResults(root);

    const list = root.querySelector('[data-simulation-results]');
    const payloads = [];

    await Promise.all(tasks.map(async (task, index) => {
        const result = await task.run();
        payloads[index] = result;
        renderResult(list, index + 1, task.label, result);
        updateCounters(root, payloads.filter(Boolean));
    }));

    showMessage(root, 'Simulasi selesai. Periksa queue health dan log staging bila ada request gagal.', 'success');
}

function bindPdfSimulation(root, config) {
    root.querySelectorAll('[data-simulation-start-pdf]').forEach(button => {
        button.addEventListener('click', () => {
            const action = button.dataset.simulationStartPdf;
            const year = root.querySelector('[data-simulation-pdf-year]')?.value;
            const kelasId = root.querySelector('[data-simulation-pdf-class]')?.value;
            const reportType = root.querySelector('[data-simulation-pdf-type]')?.value || 'UTS';
            const studentIds = selectedValues(root.querySelector('[data-simulation-pdf-students]'));
            const count = safeCount(root.querySelector('[data-simulation-pdf-count]'), config.max_requests);

            if (!year || !kelasId || !studentIds.length) {
                showMessage(root, 'Pilih tahun ajaran, kelas dummy, dan minimal satu siswa dummy terlebih dahulu.', 'error');
                return;
            }

            const tasks = Array.from({ length: count }, (_, index) => {
                const studentId = studentIds[index % studentIds.length];

                return {
                    label: `PDF ${action} siswa ${studentId}`,
                    run: () => postJson(config.pdf_url, config.csrf_token, {
                        action,
                        report_type: reportType,
                        tahun_ajaran_id: year,
                        kelas_id: kelasId,
                        student_id: studentId,
                        request_count: count,
                        request_index: index + 1,
                    }),
                };
            });

            runRequests(root, tasks);
        });
    });
}

function bindScoreSimulation(root, config) {
    root.querySelector('[data-simulation-start-score]')?.addEventListener('click', () => {
        const year = root.querySelector('[data-simulation-score-year]')?.value;
        const kelasId = root.querySelector('[data-simulation-score-class]')?.value;
        const subjectId = root.querySelector('[data-simulation-score-subject]')?.value;
        const confirmation = root.querySelector('[data-simulation-score-confirmation]')?.value || '';
        const studentIds = selectedValues(root.querySelector('[data-simulation-score-students]'));
        const count = safeCount(root.querySelector('[data-simulation-score-count]'), config.max_requests);

        if (confirmation !== config.score_confirmation) {
            showMessage(root, `Ketik konfirmasi persis: ${config.score_confirmation}`, 'error');
            return;
        }

        if (!year || !kelasId || !subjectId || !studentIds.length) {
            showMessage(root, 'Pilih tahun ajaran, kelas, mapel dummy, dan minimal satu siswa dummy terlebih dahulu.', 'error');
            return;
        }

        const tasks = Array.from({ length: count }, (_, index) => {
            const studentId = studentIds[index % studentIds.length];

            return {
                label: `Simpan nilai dummy siswa ${studentId}`,
                run: () => postJson(config.score_url, config.csrf_token, {
                    confirmation,
                    tahun_ajaran_id: year,
                    kelas_id: kelasId,
                    mata_pelajaran_id: subjectId,
                    student_id: studentId,
                    request_count: count,
                    request_index: index + 1,
                }),
            };
        });

        runRequests(root, tasks);
    });
}

function bindQueueHealth(root, config) {
    const refresh = async () => {
        const response = await fetch(config.queue_health_url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json();

        root.querySelector('[data-queue-pending]').textContent = data.pending_jobs ?? '-';
        root.querySelector('[data-queue-failed]').textContent = data.failed_jobs ?? '-';
        root.querySelector('[data-queue-reminder]').textContent = data.worker_reminder || '';
    };

    root.querySelector('[data-simulation-refresh-queue]')?.addEventListener('click', refresh);
}

export function initStagingSimulationPage() {
    const root = document.querySelector('[data-page="staging-simulation"]');
    if (!root || root.dataset.simulationBound === 'true') return;

    root.dataset.simulationBound = 'true';

    const config = JSON.parse(root.dataset.simulationConfig || '{}');
    bindClassFilters(root, config);
    bindPdfSimulation(root, config);
    bindScoreSimulation(root, config);
    bindQueueHealth(root, config);
}
