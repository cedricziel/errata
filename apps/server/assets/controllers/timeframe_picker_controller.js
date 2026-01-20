import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/**
 * Stimulus controller for the timeframe picker dropdown.
 *
 * Handles dropdown toggle, preset selection, and custom range form submission.
 */
export default class extends Controller {
    static targets = ['menu', 'customForm', 'customFromInput', 'customToInput'];

    toggle() {
        this.menuTarget.classList.toggle('hidden');
    }

    hide(event) {
        if (!this.element.contains(event.target)) {
            this.menuTarget.classList.add('hidden');
        }
    }

    showCustomForm(event) {
        event.preventDefault();
        if (this.hasCustomFormTarget) {
            this.customFormTarget.classList.remove('hidden');
        }
    }

    hideCustomForm() {
        if (this.hasCustomFormTarget) {
            this.customFormTarget.classList.add('hidden');
        }
    }

    /**
     * Handle form submission via fetch to work properly with Turbo Drive.
     *
     * Intercepts the form POST, submits via fetch, then triggers a Turbo visit
     * to refresh the page with the new timeframe selection.
     */
    async handleSubmit(event) {
        event.preventDefault();
        const form = event.target;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'text/html'
                }
            });

            if (response.ok || response.redirected) {
                // Close menu and refresh page via Turbo
                this.menuTarget.classList.add('hidden');
                Turbo.visit(window.location.href, { action: 'replace' });
            }
        } catch (error) {
            console.error('Timeframe update failed:', error);
        }
    }

    connect() {
        this.boundHide = this.hide.bind(this);
        document.addEventListener('click', this.boundHide);
    }

    disconnect() {
        document.removeEventListener('click', this.boundHide);
    }
}
