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
        url: response.url || url
    };
}

export const raporManagerPdf = {
    async handleDownloadPdf(siswaId, nilaiCount, hasAbsensi, namaSiswa) {
        if (!this.validateData(nilaiCount, hasAbsensi)) return;
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

            var link = document.createElement('a');
            link.href = result.url;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } catch (error) {
            console.error('Error in handleDownloadPdf:', error);
            Swal.fire({
                icon: 'error',
                title: 'Gagal Mengunduh PDF',
                text: 'Terjadi kesalahan. Silakan coba lagi.',
                confirmButtonText: 'OK'
            });
        } finally {
            this.loadingPdf = null;
        }
    },

    showPdfProgressEnhanced(requestId, namaSiswa, estimatedTime) {
        let checkCount = 0;
        let consecutiveErrors = 0;
        const maxChecks = 30;
        const maxConsecutiveErrors = 3;

        const progressInterval = setInterval(async () => {
            try {
                checkCount++;
                const response = await fetch(`/wali-kelas/rapor/pdf-progress/${requestId}`, {
                    method: 'GET',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                const data = await response.json();
                consecutiveErrors = 0;

                if (data.success && data.progress) {
                    const progressData = data.progress;
                    if (progressData.completed) {
                        clearInterval(progressInterval);
                        Swal.close();
                        if (progressData.error) {
                            Swal.fire({ icon: 'error', title: 'PDF Generation Failed', html: `<p>${progressData.message}</p><p class="text-sm text-gray-600 mt-2">Request ID: ${requestId}</p><p class="text-xs text-gray-500 mt-2">Coba download lagi, mungkin sudah siap.</p>` });
                        } else {
                            this.downloadPdfFile(progressData.download_url, progressData.filename, namaSiswa, progressData.cached || false);
                        }
                    } else {
                        const progress = Math.max(0, Math.min(100, progressData.percentage || 0));
                        Swal.update({
                            html: `<div class="text-center"><div class="mb-4">${progressData.message || 'Processing...'}</div><div class="w-full bg-gray-200 rounded-full h-3"><div class="bg-blue-600 h-3 rounded-full transition-all duration-500" style="width: ${progress}%"></div></div><div class="mt-2 text-sm text-gray-600">${progress}%</div><div class="mt-2 text-xs text-gray-500">Est. ${estimatedTime}</div><div class="mt-3 text-xs text-gray-400">Check ${checkCount}/${maxChecks}</div></div>`
                        });
                    }
                } else {
                    consecutiveErrors++;
                    if (consecutiveErrors >= maxConsecutiveErrors) throw new Error(data.message || 'Invalid progress response');
                }

                if (checkCount >= maxChecks) {
                    clearInterval(progressInterval);
                    Swal.close();
                    Swal.fire({ icon: 'warning', title: 'Progress Timeout', html: '<p>Proses terlalu lama atau tidak dapat dilacak.</p><p class="text-sm text-gray-600 mt-2">PDF mungkin masih sedang diproses di background.</p><p class="text-sm text-blue-600 mt-2">Coba download lagi dalam 1-2 menit.</p>' });
                }
            } catch (error) {
                console.error('Progress check error:', error);
                consecutiveErrors++;
                if (consecutiveErrors >= maxConsecutiveErrors || checkCount >= maxChecks) {
                    clearInterval(progressInterval);
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Connection Error', html: `<p>Tidak dapat memeriksa progress.</p><p class="text-sm text-gray-600 mt-2">Error: ${error.message}</p><p class="text-sm text-blue-600 mt-3">Tip: PDF mungkin masih diproses. Coba klik download lagi dalam 30-60 detik.</p>` });
                }
            }
        }, 2000);

        Swal.fire({
            title: 'Generating PDF',
            html: `<div class="text-center"><div class="mb-4">Memulai generate PDF untuk ${namaSiswa}...</div><div class="w-full bg-gray-200 rounded-full h-3"><div class="bg-blue-600 h-3 rounded-full transition-all duration-500" style="width: 5%"></div></div><div class="mt-2 text-sm text-gray-600">5%</div><div class="mt-2 text-xs text-gray-500">Est. ${estimatedTime}</div><div class="mt-3 text-xs text-gray-400">Request ID: ${requestId}</div></div>`,
            allowOutsideClick: false,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Batal',
            didOpen: () => Swal.getCancelButton()?.addEventListener('click', () => clearInterval(progressInterval))
        });
    },

    downloadPdfFile(downloadUrl, filename, namaSiswa, isCached = false) {
        const a = document.createElement('a');
        a.href = downloadUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        Swal.fire({
            icon: 'success',
            title: 'PDF Ready!',
            html: `<div><p>Rapor PDF untuk <strong>${namaSiswa}</strong> berhasil diunduh</p>${isCached ? '<p class="text-sm text-blue-600 mt-2">Dari cache (instan)</p>' : '<p class="text-sm text-green-600 mt-2">Freshly generated</p>'}</div>`,
            timer: 3000,
            showConfirmButton: false
        });
    },

    async handlePreviewPdf(siswaId, nilaiCount, hasAbsensi) {
        if (!this.validateData(nilaiCount, hasAbsensi)) return;
        var newWindow = window.open('', '_blank');
        if (!newWindow) {
            alert('Popup diblokir browser. Izinkan popup untuk situs ini.');
            return;
        }

        newWindow.document.write(
            '<html><body style="font-family:sans-serif;' +
            'display:flex;align-items:center;justify-content:center;' +
            'height:100vh;margin:0;background:#f3f4f6;">' +
            '<div style="text-align:center">' +
            '<p style="font-size:18px;color:#374151;">⏳ Memuat PDF rapor...</p>' +
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

            newWindow.location.href = result.url;
        } catch (error) {
            newWindow.close();
            console.error('Error previewing PDF:', error);
            Swal.fire({
                icon: 'error',
                title: 'Gagal Preview PDF',
                text: 'Terjadi kesalahan. Silakan coba lagi.',
                confirmButtonText: 'OK'
            });
        } finally {
            this.loading = false;
        }
    }
};
