import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    // Toggle event details row
    toggleEventDetails(event) {
        // Don't toggle if clicking on a link or button inside the row
        if (event.target.closest('a') || event.target.closest('button')) {
            return;
        }

        const row = event.currentTarget;
        const eventId = row.dataset.eventId;
        const detailsRow = row.closest('tbody').querySelector(`[data-event-details="${eventId}"]`);
        const icon = row.querySelector('.event-expand-icon');

        if (detailsRow) {
            detailsRow.classList.toggle('hidden');

            if (icon) {
                icon.classList.toggle('rotate-90');
            }
        }
    }

    // Export results
    exportResults(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const format = button.dataset.format || 'csv';

        // Build export URL with current filters
        const url = new URL(window.location.href);
        url.pathname = url.pathname.replace('/query', '/query/export');
        url.searchParams.set('format', format);

        window.location.href = url.toString();
    }
}
