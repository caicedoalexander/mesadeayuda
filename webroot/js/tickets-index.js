document.addEventListener('DOMContentLoaded', function () {
    if (typeof initBulkActions === 'function') {
        initBulkActions('ticket');
    }

    if (typeof AjaxRefresh !== 'undefined' && AjaxRefresh.init) {
        AjaxRefresh.init({
            entityType: 'ticket',
            autoRefreshSeconds: 30,
        });
    }

    const data = window.ticketsIndexData || {};
    if (data.showInitialSpinner && typeof LoadingSpinner !== 'undefined') {
        LoadingSpinner.showFor(800, 'Cargando tickets...');
    }
});
