export const raporManagerPdf = {
    async handleDownloadPdf(siswaId, nilaiCount, hasAbsensi, namaSiswa) {
        if (!this.validateData(nilaiCount, hasAbsensi)) return;
        try {
            this.loadingPdf = siswaId;
            const response = await fetch(`/wali-kelas/rapor/request-pdf/${siswaId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ type: this.activeTab, tahun_ajaran_id: this.tahunAjaranId })
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            const data = await response.json();
            if (data.success) {
                if (data.ready) {
                    this.downloadPdfFile(data.download_url, data.filename, namaSiswa, data.cached);
                } else {
                    this.showPdfProgressEnhanced(data.request_id, namaSiswa, data.estimated_time);
                }
            } else {
                throw new Error(data.message || 'Gagal memproses PDF');
            }
        } catch (error) {
            console.error('Error in handleDownloadPdf:', error);
            Swal.fire({
                icon: 'error',
                title: 'Gagal Generate PDF',
                html: `<p>${error.message}</p><div class="mt-4 text-xs text-gray-500"><p>Kemungkinan penyebab:</p><ul class="text-left list-disc list-inside"><li>Koneksi internet tidak stabil</li><li>Server sedang sibuk</li><li>Queue worker tidak berjalan</li></ul><p class="mt-2">Coba lagi dalam beberapa saat.</p></div>`
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
        try {
            this.loading = true;
            const url = `/wali-kelas/rapor/preview-pdf/${siswaId}?type=${this.activeTab}&tahun_ajaran_id=${this.tahunAjaranId}`;
            const newWindow = window.open(url, '_blank');
            if (!newWindow) {
                Swal.fire({ icon: 'warning', title: 'Popup Diblokir', html: `<a href="${url}" target="_blank" class="text-blue-600 underline">Buka PDF Preview</a>` });
            }
        } catch (error) {
            console.error('Error previewing PDF:', error);
            Swal.fire({ icon: 'error', title: 'Gagal Preview PDF', text: error.message || 'Terjadi kesalahan saat membuka preview PDF' });
        } finally {
            this.loading = false;
        }
    }
};
