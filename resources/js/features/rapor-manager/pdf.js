const activePdfPolls = new Map();
let pdfLifecycleCleanupRegistered = false;

function pdfPollKey(requestId, disposition) {
    return `${requestId}:${disposition}`;
}

function clearPdfPoll(key) {
    const poll = activePdfPolls.get(key);
    if (!poll) return;

    if (poll.timer) {
        clearTimeout(poll.timer);
    }

    if (poll.controller) {
        poll.controller.abort();
    }

    activePdfPolls.delete(key);
}

function clearAllPdfPolls() {
    Array.from(activePdfPolls.keys()).forEach((key) => clearPdfPoll(key));
}

function registerPdfLifecycleCleanup() {
    if (pdfLifecycleCleanupRegistered || typeof document === 'undefined') return;

    pdfLifecycleCleanupRegistered = true;
    document.addEventListener('turbo:before-cache', clearAllPdfPolls);
    document.addEventListener('turbo:before-render', clearAllPdfPolls);
    window.addEventListener('beforeunload', clearAllPdfPolls);
}

async function resolvePdfRequest(url) {
    var response = await fetch(url, {
        method: 'GET',
        headers: {
            Accept: 'application/json, application/pdf, */*',
            'X-Requested-With': 'XMLHttpRequest'
        }
    });
    var contentType = response.headers.get('content-type') || '';

    if (contentType.includes('application/json')) {
        var data = await response.json();

        if (data.status === 'ready' || data.ready) {
            return {
                ok: true,
                status: 'ready',
                cacheHit: data.cache_hit ?? data.cached ?? false,
                url: data.url || data.download_url,
                filename: data.filename
            };
        }

        if (data.status === 'processing') {
            return {
                ok: true,
                status: 'processing',
                cacheHit: false,
                requestId: data.request_id,
                pollUrl: data.poll_url || data.progress_url,
                message: data.message || 'Sedang menyiapkan PDF rapor.'
            };
        }

        return {
            ok: false,
            status: response.status,
            message: data.message || 'PDF tidak dapat dibuat saat ini. Hubungi administrator.'
        };
    }

    if (!response.ok) {
        return {
            ok: false,
            status: response.status,
            message: 'PDF tidak dapat dibuat saat ini. Hubungi administrator.'
        };
    }

    return {
        ok: true,
        status: 'ready',
        cacheHit: true,
        url: response.url || url
    };
}

async function pollPdfUntilReady({ requestId, pollUrl, disposition, onStatus }) {
    const key = pdfPollKey(requestId, disposition);
    clearPdfPoll(key);

    const maxChecks = 180;
    let checkCount = 0;

    return new Promise((resolve, reject) => {
        const poll = {
            controller: new AbortController(),
            timer: null
        };

        activePdfPolls.set(key, poll);

        const finish = (callback, payload) => {
            clearPdfPoll(key);
            callback(payload);
        };

        const tick = async () => {
            try {
                checkCount++;

                const pollRequestUrl = new URL(pollUrl, window.location.origin);
                pollRequestUrl.searchParams.set('disposition', disposition);

                const response = await fetch(pollRequestUrl.toString(), {
                    method: 'GET',
                    signal: poll.controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();

                if (!response.ok || data.status === 'failed') {
                    finish(reject, new Error(data.message || 'PDF gagal disiapkan. Silakan coba lagi atau hubungi administrator.'));
                    return;
                }

                if (data.status === 'ready' || data.ready) {
                    finish(resolve, {
                        url: data.url || data.download_url,
                        filename: data.filename,
                        cacheHit: data.cache_hit ?? data.cached ?? false
                    });
                    return;
                }

                if (typeof onStatus === 'function') {
                    onStatus(data.message || data.progress?.message || 'Sedang menyiapkan PDF rapor.');
                }

                if (checkCount >= maxChecks) {
                    finish(reject, new Error('PDF masih belum selesai disiapkan. Silakan coba periksa kembali beberapa saat lagi.'));
                    return;
                }

                poll.timer = setTimeout(tick, 1000);
            } catch (error) {
                if (error.name === 'AbortError') {
                    finish(reject, error);
                    return;
                }

                if (checkCount >= maxChecks) {
                    finish(reject, new Error('PDF masih belum selesai disiapkan. Silakan coba periksa kembali beberapa saat lagi.'));
                    return;
                }

                poll.timer = setTimeout(tick, 1000);
            }
        };

        tick();
    });
}

function openDownload(url) {
    var link = document.createElement('a');
    link.href = url;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function renderPreviewStatus(targetWindow, title, message, color = '#374151') {
    if (!targetWindow || targetWindow.closed) return;

    targetWindow.document.body.innerHTML =
        '<div style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f3f4f6;">' +
        '<div style="text-align:center;max-width:420px;padding:24px;">' +
        `<p style="font-size:18px;color:${color};margin-bottom:8px;">${title}</p>` +
        `<p style="color:#6b7280;font-size:14px;">${message}</p>` +
        '<p style="color:#9ca3af;font-size:12px;margin-top:12px;">Proses ini biasanya membutuhkan beberapa detik.</p>' +
        '</div></div>';
}

export const raporManagerPdf = {
    async handleDownloadPdf(siswaId, nilaiCount, hasAbsensi, namaSiswa) {
        if (!this.validateData(nilaiCount, hasAbsensi)) return;
        if (!this.validatePdfTemplate(siswaId)) return;
        registerPdfLifecycleCleanup();

        let cancelled = false;

        try {
            this.loadingPdf = siswaId;
            var url = `/wali-kelas/rapor/preview-pdf/${siswaId}?type=${this.activeTab}&tahun_ajaran_id=${this.tahunAjaranId}&disposition=attachment`;
            var result = await resolvePdfRequest(url);

            if (!result.ok) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Mengunduh PDF',
                    text: result.message,
                    confirmButtonText: 'OK'
                });
                return;
            }

            if (result.status === 'processing') {
                this.updatePdfStatus?.(siswaId, 'preparing');
                const key = pdfPollKey(result.requestId, 'attachment');

                Swal.fire({
                    title: 'Sedang menyiapkan PDF',
                    html: '<p>Sedang menyiapkan PDF rapor.</p><p class="text-sm text-gray-600 mt-2">Proses ini biasanya membutuhkan beberapa detik.</p>',
                    allowOutsideClick: true,
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: 'Tutup',
                    didOpen: () => Swal.getCancelButton()?.addEventListener('click', () => {
                        cancelled = true;
                        clearPdfPoll(key);
                    })
                });

                result = await pollPdfUntilReady({
                    requestId: result.requestId,
                    pollUrl: result.pollUrl,
                    disposition: 'attachment',
                    onStatus: (message) => {
                        Swal.update({
                            html: `<p>${message}</p><p class="text-sm text-gray-600 mt-2">Proses ini biasanya membutuhkan beberapa detik.</p>`
                        });
                    }
                });

                Swal.close();
            }

            this.updatePdfStatus?.(siswaId, 'ready');
            openDownload(result.url);

            Swal.fire({
                icon: 'success',
                title: 'PDF Siap',
                html: `<div><p>Rapor PDF untuk <strong>${namaSiswa}</strong> berhasil disiapkan.</p>${result.cacheHit ? '<p class="text-sm text-blue-600 mt-2">Dari cache.</p>' : ''}</div>`,
                timer: 2500,
                showConfirmButton: false
            });
        } catch (error) {
            if (cancelled || error.name === 'AbortError') {
                return;
            }

            console.error('Error in handleDownloadPdf:', error);
            Swal.fire({
                icon: 'error',
                title: 'Gagal Mengunduh PDF',
                text: error.message || 'Terjadi kesalahan. Silakan coba lagi.',
                confirmButtonText: 'OK'
            });
        } finally {
            this.loadingPdf = null;
        }
    },

    async handlePreviewPdf(siswaId, nilaiCount, hasAbsensi) {
        if (!this.validateData(nilaiCount, hasAbsensi)) return;
        if (!this.validatePdfTemplate(siswaId)) return;
        registerPdfLifecycleCleanup();

        var newWindow = window.open('', '_blank');
        if (!newWindow) {
            alert('Popup diblokir browser. Izinkan popup untuk situs ini.');
            return;
        }

        newWindow.document.write(
            '<html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f3f4f6;">' +
            '<div style="text-align:center">' +
            '<p style="font-size:18px;color:#374151;">Memuat PDF rapor...</p>' +
            '<p style="color:#6b7280;font-size:14px;">Mohon tunggu sebentar</p>' +
            '</div></body></html>'
        );

        try {
            this.loading = true;
            var url = `/wali-kelas/rapor/preview-pdf/${siswaId}?type=${this.activeTab}&tahun_ajaran_id=${this.tahunAjaranId}`;
            var result = await resolvePdfRequest(url);

            if (!result.ok) {
                newWindow.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Membuat PDF',
                    text: result.message,
                    confirmButtonText: 'OK'
                });
                return;
            }

            if (result.status === 'processing') {
                this.updatePdfStatus?.(siswaId, 'preparing');
                result = await pollPdfUntilReady({
                    requestId: result.requestId,
                    pollUrl: result.pollUrl,
                    disposition: 'inline',
                    onStatus: (message) => {
                        if (newWindow.closed) {
                            clearPdfPoll(pdfPollKey(result.requestId, 'inline'));
                            return;
                        }

                        renderPreviewStatus(newWindow, 'Sedang menyiapkan PDF rapor', message);
                    }
                });
            }

            this.updatePdfStatus?.(siswaId, 'ready');
            if (!newWindow.closed) {
                newWindow.location.href = result.url;
            }
        } catch (error) {
            if (!newWindow.closed) {
                renderPreviewStatus(
                    newWindow,
                    'PDF belum tersedia',
                    error.message || 'Silakan coba lagi beberapa saat lagi.',
                    '#991b1b'
                );
            }

            console.error('Error previewing PDF:', error);
            Swal.fire({
                icon: 'error',
                title: 'Gagal Preview PDF',
                text: error.message || 'Terjadi kesalahan. Silakan coba lagi.',
                confirmButtonText: 'OK'
            });
        } finally {
            this.loading = false;
        }
    }
};
