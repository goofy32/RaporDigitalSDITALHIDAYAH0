function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildDetailItems(details, icon, formatter) {
    return details.map(detail => `
        <li class="mb-2 flex items-start">
            <span class="${icon.className} mr-1">${icon.text}</span>
            <div>${formatter(detail)}</div>
        </li>
    `).join('');
}

function bindModalTabs() {
    document.querySelectorAll('.tablinks').forEach(tabLink => {
        tabLink.addEventListener('click', function () {
            var target = this.getAttribute('data-target');

            document.querySelectorAll('.tabcontent').forEach(tabContent => {
                tabContent.classList.add('hidden');
                tabContent.classList.remove('block');
            });

            document.querySelectorAll('.tablinks').forEach(tab => {
                tab.classList.remove('active', 'border-green-500', 'border-red-500');
                tab.classList.add('border-transparent');
            });

            document.getElementById(target)?.classList.remove('hidden');
            document.getElementById(target)?.classList.add('block');
            this.classList.add('active');
            this.classList.add(target === 'notProcessed' ? 'border-red-500' : 'border-green-500');
        });
    });
}

function showMassPromotionModal(pageRoot) {
    if (pageRoot.dataset.massPromotionShown === 'true' || pageRoot.dataset.hasMassPromotion !== 'true') return;

    var stats = JSON.parse(pageRoot.dataset.massPromotionStats || 'null') || {};
    var details = JSON.parse(pageRoot.dataset.massPromotionDetails || 'null') || {};
    var detailHtml = '<div class="text-center mb-4"><div class="grid grid-cols-3 gap-4 mb-4">';

    detailHtml += `<div class="bg-green-100 p-3 rounded-lg"><div class="text-green-700 text-lg font-bold">${stats.promoted || 0}</div><div class="text-green-600 text-sm">Naik Kelas</div></div>`;
    detailHtml += `<div class="bg-green-100 p-3 rounded-lg"><div class="text-green-700 text-lg font-bold">${stats.graduated || 0}</div><div class="text-green-600 text-sm">Lulus</div></div>`;
    detailHtml += `<div class="bg-red-100 p-3 rounded-lg"><div class="text-red-700 text-lg font-bold">${stats.notProcessed || 0}</div><div class="text-red-600 text-sm">Tidak Diproses</div></div>`;
    detailHtml += '</div><div class="mb-4 border-b border-gray-200"><ul class="flex flex-wrap -mb-px text-sm font-medium text-center" id="kenaikanTabs" role="tablist">';

    if ((stats.promoted || 0) > 0) detailHtml += `<li class="mr-2"><button class="inline-block p-2 border-b-2 border-green-500 rounded-t-lg hover:bg-green-50 tablinks active" data-target="promoted" type="button">Naik Kelas (${stats.promoted})</button></li>`;
    if ((stats.graduated || 0) > 0) detailHtml += `<li class="mr-2"><button class="inline-block p-2 border-b-2 border-transparent rounded-t-lg hover:bg-green-50 tablinks" data-target="graduated" type="button">Lulus (${stats.graduated})</button></li>`;
    if ((stats.notProcessed || 0) > 0) detailHtml += `<li class="mr-2"><button class="inline-block p-2 border-b-2 border-transparent rounded-t-lg hover:bg-red-50 tablinks" data-target="notProcessed" type="button">Tidak Diproses (${stats.notProcessed})</button></li>`;

    detailHtml += '</ul></div><div class="tabcontent-container">';

    if ((stats.promoted || 0) > 0) {
        detailHtml += `<div id="promoted" class="tabcontent block"><div class="max-h-60 overflow-y-auto py-2"><ul class="text-left">${buildDetailItems(details.promoted || [], { text: '&uarr;', className: 'text-green-600' }, detail => `<strong>${escapeHtml(detail.nama)}</strong><br>${escapeHtml(detail.kelas_asal)} &rarr; ${escapeHtml(detail.kelas_tujuan)}`)}</ul></div></div>`;
    }

    if ((stats.graduated || 0) > 0) {
        detailHtml += `<div id="graduated" class="tabcontent hidden"><div class="max-h-60 overflow-y-auto py-2"><ul class="text-left">${buildDetailItems(details.graduated || [], { text: '&#127891;', className: 'text-green-600' }, detail => `<strong>${escapeHtml(detail.nama)}</strong><br>Dari ${escapeHtml(detail.kelas_asal)} &rarr; Lulus`)}</ul></div></div>`;
    }

    if ((stats.notProcessed || 0) > 0) {
        detailHtml += `<div id="notProcessed" class="tabcontent hidden"><div class="max-h-60 overflow-y-auto py-2"><ul class="text-left">${buildDetailItems(details.notProcessed || [], { text: '&#9888;', className: 'text-red-600' }, detail => `<strong>${escapeHtml(detail.nama)}</strong><br>${escapeHtml(detail.kelas_asal)} &rarr; <span class="text-red-500">${escapeHtml(detail.alasan)}</span>`)}</ul></div></div>`;
    }

    detailHtml += '</div></div>';
    pageRoot.dataset.massPromotionShown = 'true';

    Swal.fire({
        title: 'Kenaikan Kelas Massal Berhasil',
        html: detailHtml,
        icon: 'success',
        width: 600,
        confirmButtonColor: '#10b981',
        confirmButtonText: 'OK',
        didOpen: bindModalTabs,
    });
}

export function initKenaikanKelasIndexPage() {
    var pageRoot = document.querySelector('[data-page="kenaikan-kelas-index"]');
    if (!pageRoot) return;

    showMassPromotionModal(pageRoot);
}
