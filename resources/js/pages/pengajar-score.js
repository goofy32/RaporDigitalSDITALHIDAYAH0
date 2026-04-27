export function initPengajarScorePage() {
    var pageEl = document.querySelector('[data-page="pengajar-score"]');
    if (!pageEl) return;

    window.searchTable = function () {
        var input = document.getElementById('searchInput');
        var table = document.getElementById('pembelajaranTable');
        var rows = table ? table.getElementsByTagName('tr') : [];
        var filter = input ? input.value.toLowerCase() : '';
        var i = 0;

        for (i = 1; i < rows.length; i += 1) {
            var cells = rows[i].getElementsByTagName('td');
            var found = false;
            var j = 0;

            for (j = 0; j < cells.length; j += 1) {
                var cellText = cells[j].textContent || cells[j].innerText;
                if (cellText.toLowerCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }

            rows[i].style.display = found ? '' : 'none';
        }
    };
}
