import Alpine from 'alpinejs';

export function registerSessionTimeout() {
    Alpine.data('sessionTimeout', () => ({
        isExpired: false,
        timeoutDuration: 7200000,
        checkInterval: null,
        isLoggingOut: false,
        configLoaded: false,

        async init() {
            if (this.isLoginPage()) return;
            await this.loadSessionConfig();
            this.setupActivityTracking();
            this.setupSessionCheck();
            this.setupLogoutHandlers();
            this.setupTurboListeners();
        },

        async loadSessionConfig() {
            try {
                const response = await fetch('/api/session-config', {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                });

                if (response.ok) {
                    const config = await response.json();
                    this.timeoutDuration = config.lifetime;
                    this.configLoaded = true;
                } else {
                    throw new Error('Config load failed');
                }
            } catch {
                this.timeoutDuration = 7200000;
                this.configLoaded = true;
            }
        },

        isLoginPage() {
            return window.location.pathname === '/login';
        },

        setupActivityTracking() {
            const resetActivity = () => {
                if (this.isLoggingOut) return;
                sessionStorage.setItem('lastActivityTime', Date.now().toString());
            };

            ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(event => {
                document.addEventListener(event, resetActivity, { passive: true });
            });

            resetActivity();
        },

        setupSessionCheck() {
            if (this.checkInterval) clearInterval(this.checkInterval);

            const checkSession = () => {
                if (!this.configLoaded || this.isLoggingOut) return;

                const lastActivity = parseInt(sessionStorage.getItem('lastActivityTime') || Date.now(), 10);
                const inactiveTime = Date.now() - lastActivity;

                if (inactiveTime > this.timeoutDuration) {
                    this.triggerExpiration();
                }
            };

            this.checkInterval = setInterval(checkSession, 30000);
            setTimeout(checkSession, 2000);
        },

        triggerExpiration() {
            if (this.isExpired || this.isLoggingOut) return;

            this.isExpired = true;
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
                this.checkInterval = null;
            }

            this.$nextTick(() => {
                const alert = document.getElementById('session-alert');
                if (alert) alert.style.display = 'block';
            });
        },

        setupLogoutHandlers() {
            document.addEventListener('submit', e => {
                if (e.target.action?.includes('logout')) {
                    this.isLoggingOut = true;
                    if (this.checkInterval) clearInterval(this.checkInterval);
                }
            });

            document.addEventListener('click', e => {
                const link = e.target.closest('a, button');
                if (link && (link.href?.includes('logout') || link.textContent?.toLowerCase().includes('logout'))) {
                    this.isLoggingOut = true;
                    if (this.checkInterval) clearInterval(this.checkInterval);
                }
            });
        },

        setupTurboListeners() {
            document.addEventListener('turbo:before-visit', event => {
                if (event.detail.url.includes('/login') || event.detail.url.includes('/logout')) {
                    this.isLoggingOut = true;
                    if (this.checkInterval) clearInterval(this.checkInterval);
                    return;
                }

                if (!this.isLoggingOut) {
                    sessionStorage.setItem('lastActivityTime', Date.now().toString());
                }
            });

            document.addEventListener('turbo:load', () => {
                if (this.isLoginPage() || this.isLoggingOut) {
                    if (this.checkInterval) clearInterval(this.checkInterval);
                    return;
                }

                sessionStorage.setItem('lastActivityTime', Date.now().toString());
                this.loadSessionConfig().then(() => this.setupSessionCheck());
            });
        },

        handleLogout() {
            this.isLoggingOut = true;
            if (this.checkInterval) clearInterval(this.checkInterval);

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/logout';

            const token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = document.querySelector('meta[name="csrf-token"]').content;

            form.appendChild(token);
            document.body.appendChild(form);
            form.submit();
        },
    }));
}
