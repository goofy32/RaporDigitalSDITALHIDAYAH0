import Alpine from 'alpinejs';

export function registerDashboard() {
    Alpine.data('dashboard', () => ({
        selectedKelas: '',
        selectedKelasName: 'Per Kelas',
        selectedSubject: '',
        mapelProgress: [],

        init() {
            const role = this.$el.dataset.dashboardRole;

            if (role === 'admin') {
                this.$nextTick(() => {
                    if (typeof overallProgress === 'undefined') {
                        window.overallProgress = 0;
                    }

                    setTimeout(() => {
                        initCharts();
                        this.fetchKelasProgress();
                    }, 200);
                });
                return;
            }

            setTimeout(() => {
                initCharts();
            }, 100);

            this.$watch('selectedSubject', value => {
                if (value) {
                    this.fetchSubjectProgress();
                }
            });
        },

        initCharts() {
            initCharts();
        },

        async fetchKelasProgress() {
            if (!this.selectedKelas) {
                this.selectedKelasName = 'Per Kelas';
                updateClassChart(0);
                return;
            }

            const kelasSelect = document.getElementById('kelas');
            if (kelasSelect) {
                const selectedOption = kelasSelect.options[kelasSelect.selectedIndex];
                this.selectedKelasName = selectedOption ? selectedOption.text : 'Per Kelas';
            }

            try {
                const timestamp = new Date().getTime();
                const response = await fetch(`/admin/kelas-progress/${this.selectedKelas}?_=${timestamp}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.success && !isNaN(data.progress)) {
                    updateClassChart(data.progress);
                } else {
                    console.error('Invalid progress data:', data);
                    updateClassChart(0);
                }
            } catch (error) {
                console.error('Error fetching progress:', error);
                updateClassChart(0);
            }
        },

        fetchSubjectProgress() {
            if (!this.selectedSubject) return;
            window.fetchSubjectProgress(this.selectedSubject);
        }
    }));
}
