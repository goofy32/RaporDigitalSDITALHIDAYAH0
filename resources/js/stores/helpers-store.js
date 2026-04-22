import Alpine from 'alpinejs';

export function registerHelpersStore() {
    document.addEventListener('alpine:init', () => {
        Alpine.store('helpers', {
            getRelativeTime(dateString) {
                if (!dateString) return '';
                try {
                    const date = new Date(dateString);
                    const now = new Date();
                    const diffMs = now - date;
                    const diffMins = Math.floor(diffMs / 60000);
                    const diffHours = Math.floor(diffMs / 3600000);
                    const diffDays = Math.floor(diffMs / 86400000);

                    if (diffMins < 1) return 'Baru saja';
                    if (diffMins < 60) return `${diffMins} menit lalu`;
                    if (diffHours < 24) return `${diffHours} jam lalu`;
                    if (diffDays < 7) return `${diffDays} hari lalu`;

                    return date.toLocaleDateString('id-ID', {
                        day: 'numeric',
                        month: 'short',
                        year: diffDays > 365 ? 'numeric' : undefined,
                    });
                } catch (error) {
                    console.error('Error formatting relative time:', error);
                    return dateString;
                }
            },

            formatTimestamp(dateString) {
                if (!dateString) return '';
                try {
                    const date = new Date(dateString);
                    const now = new Date();
                    const yesterday = new Date(now);
                    yesterday.setDate(yesterday.getDate() - 1);

                    const isToday = date.toDateString() === now.toDateString();
                    const isYesterday = date.toDateString() === yesterday.toDateString();
                    const isCurrentYear = date.getFullYear() === now.getFullYear();

                    if (isToday) {
                        return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false });
                    }
                    if (isYesterday) {
                        return `Kemarin ${date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false })}`;
                    }
                    if (isCurrentYear) {
                        return `${date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })} ${date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false })}`;
                    }
                    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
                } catch (e) {
                    console.error('Error formatting date:', e);
                    return dateString;
                }
            },

            truncateText(text, maxLength = 50) {
                if (!text) return '';
                return text.length > maxLength ? `${text.substring(0, maxLength)}...` : text;
            },

            formatResponseText(text) {
                if (!text) return '';
                return text
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*(.*?)\*/g, '<em>$1</em>')
                    .replace(/\n/g, '<br>')
                    .replace(/â€¢ /g, 'â€¢ ');
            },
        });
    });
}
