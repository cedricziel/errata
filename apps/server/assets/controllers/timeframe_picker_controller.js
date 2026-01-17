import { Controller } from '@hotwired/stimulus';

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

    connect() {
        this.boundHide = this.hide.bind(this);
        document.addEventListener('click', this.boundHide);
    }

    disconnect() {
        document.removeEventListener('click', this.boundHide);
    }
}
