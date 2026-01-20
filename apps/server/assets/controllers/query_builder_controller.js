import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['form', 'filterContainer', 'projectSelect', 'projectInput', 'groupBy', 'facetPanel', 'results', 'loadingOverlay', 'progressBar', 'cancelButton', 'errorMessage'];
    static values = {
        resultsUrl: String,
        facetsUrl: String,
        submitUrl: String,
        asyncEnabled: { type: Boolean, default: false }
    };

    filterCount = 0;
    currentQueryId = null;
    eventSource = null;

    connect() {
        // Initialize filter count based on existing filters
        const filterRows = this.filterContainerTarget.querySelectorAll('.filter-row:not(.new-filter-row)');
        this.filterCount = filterRows.length;
    }

    disconnect() {
        this.closeEventSource();
    }

    // Submit the query form
    submitQuery(event) {
        if (event) {
            event.preventDefault();
        }

        // Sync project select value to hidden input
        if (this.hasProjectSelectTarget && this.hasProjectInputTarget) {
            this.projectInputTarget.value = this.projectSelectTarget.value;
        }

        // Use async submission if enabled
        if (this.asyncEnabledValue && this.hasSubmitUrlValue) {
            this.submitQueryAsync();
            return;
        }

        // Build URL with current form data
        const formData = new FormData(this.formTarget);
        const params = new URLSearchParams();

        for (const [key, value] of formData.entries()) {
            if (value !== '') {
                params.append(key, value);
            }
        }

        // Navigate to the new URL
        window.location.href = '?' + params.toString();
    }

    // Submit query asynchronously
    async submitQueryAsync() {
        // Show loading state
        this.showLoading();

        try {
            // Close any existing SSE connection
            this.closeEventSource();

            // Build the query data
            const queryData = this.buildQueryData();

            // Submit the query
            const response = await fetch(this.submitUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(queryData),
            });

            if (!response.ok) {
                throw new Error(`HTTP error: ${response.status}`);
            }

            const data = await response.json();
            this.currentQueryId = data.queryId;

            // Subscribe to SSE stream
            this.subscribeToResults(data.streamUrl);

            // Store cancel URL for later
            this.cancelUrl = data.cancelUrl;

        } catch (error) {
            console.error('Query submission failed:', error);
            this.showError('Failed to submit query: ' + error.message);
            this.hideLoading();
        }
    }

    // Build query data from form
    buildQueryData() {
        const formData = new FormData(this.formTarget);
        const filters = [];

        // Collect filters
        const filterRows = this.filterContainerTarget.querySelectorAll('.filter-row:not(.new-filter-row)');
        filterRows.forEach((row, index) => {
            const attribute = row.querySelector(`[name="filters[${index}][attribute]"]`)?.value;
            const operator = row.querySelector(`[name="filters[${index}][operator]"]`)?.value;
            const value = row.querySelector(`[name="filters[${index}][value]"]`)?.value;

            if (attribute && operator) {
                filters.push({ attribute, operator, value });
            }
        });

        return {
            filters,
            groupBy: formData.get('groupBy') || null,
            page: parseInt(formData.get('page') || '1', 10),
            limit: parseInt(formData.get('limit') || '50', 10),
            project: formData.get('project'),
        };
    }

    // Subscribe to SSE results stream
    subscribeToResults(streamUrl) {
        this.eventSource = new EventSource(streamUrl);

        this.eventSource.addEventListener('status', (event) => {
            const data = JSON.parse(event.data);
            this.handleStatusUpdate(data);
        });

        this.eventSource.addEventListener('progress', (event) => {
            const data = JSON.parse(event.data);
            this.updateProgress(data.progress);
        });

        this.eventSource.addEventListener('result', (event) => {
            const data = JSON.parse(event.data);
            this.handleResult(data);
            this.closeEventSource();
        });

        this.eventSource.addEventListener('error', (event) => {
            if (event.data) {
                const data = JSON.parse(event.data);
                this.showError(data.message || 'Query failed');
            } else {
                this.showError('Connection lost');
            }
            this.closeEventSource();
            this.hideLoading();
        });

        this.eventSource.addEventListener('cancelled', (event) => {
            const data = JSON.parse(event.data);
            this.showCancelled(data.message || 'Query was cancelled');
            this.closeEventSource();
            this.hideLoading();
        });

        this.eventSource.addEventListener('heartbeat', (event) => {
            // Keep connection alive indicator
            console.debug('Query heartbeat received');
        });

        this.eventSource.onerror = (error) => {
            console.error('SSE connection error:', error);
            if (this.eventSource.readyState === EventSource.CLOSED) {
                this.closeEventSource();
                this.hideLoading();
            }
        };
    }

    // Handle status update
    handleStatusUpdate(data) {
        console.debug('Query status:', data.status, 'progress:', data.progress);
        this.updateProgress(data.progress);
    }

    // Update progress bar
    updateProgress(progress) {
        if (this.hasProgressBarTarget) {
            this.progressBarTarget.style.width = `${progress}%`;
            this.progressBarTarget.setAttribute('aria-valuenow', progress);
        }
    }

    // Handle query result
    handleResult(data) {
        this.hideLoading();
        this.renderResults(data);
    }

    // Render results in the results target
    renderResults(data) {
        if (!this.hasResultsTarget) {
            console.warn('No results target found');
            return;
        }

        // Dispatch custom event with results for other controllers to handle
        this.element.dispatchEvent(new CustomEvent('query:results', {
            detail: data,
            bubbles: true,
        }));

        // Build a simple results display if template not provided
        const events = data.events || [];
        const total = data.total || 0;

        let html = `<div class="query-results">`;
        html += `<div class="results-summary text-sm text-gray-500 mb-4">Found ${total} events</div>`;

        if (events.length === 0) {
            html += `<div class="no-results text-gray-500 text-center py-8">No events found matching your query.</div>`;
        } else {
            html += `<div class="results-table overflow-x-auto">`;
            html += `<table class="min-w-full divide-y divide-gray-200">`;
            html += `<thead class="bg-gray-50"><tr>`;

            // Get columns from first event
            const columns = Object.keys(events[0]).slice(0, 8); // Limit columns for display
            columns.forEach(col => {
                html += `<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">${col}</th>`;
            });
            html += `</tr></thead>`;

            html += `<tbody class="bg-white divide-y divide-gray-200">`;
            events.forEach(event => {
                html += `<tr class="hover:bg-gray-50">`;
                columns.forEach(col => {
                    const value = event[col];
                    const displayValue = typeof value === 'object' ? JSON.stringify(value) : (value ?? '');
                    html += `<td class="px-4 py-2 text-sm text-gray-900 truncate max-w-xs" title="${displayValue}">${displayValue}</td>`;
                });
                html += `</tr>`;
            });
            html += `</tbody></table>`;
            html += `</div>`;
        }

        html += `</div>`;

        this.resultsTarget.innerHTML = html;
    }

    // Cancel the running query
    async cancelQuery(event) {
        if (event) {
            event.preventDefault();
        }

        if (!this.cancelUrl || !this.currentQueryId) {
            return;
        }

        try {
            const response = await fetch(this.cancelUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
            });

            const data = await response.json();
            if (data.success) {
                console.debug('Query cancellation requested');
            } else {
                console.warn('Could not cancel query:', data.error);
            }
        } catch (error) {
            console.error('Failed to cancel query:', error);
        }
    }

    // Show loading overlay
    showLoading() {
        if (this.hasLoadingOverlayTarget) {
            this.loadingOverlayTarget.classList.remove('hidden');
        }
        if (this.hasProgressBarTarget) {
            this.progressBarTarget.style.width = '0%';
        }
        if (this.hasCancelButtonTarget) {
            this.cancelButtonTarget.classList.remove('hidden');
        }
        if (this.hasErrorMessageTarget) {
            this.errorMessageTarget.classList.add('hidden');
        }
    }

    // Hide loading overlay
    hideLoading() {
        if (this.hasLoadingOverlayTarget) {
            this.loadingOverlayTarget.classList.add('hidden');
        }
        if (this.hasCancelButtonTarget) {
            this.cancelButtonTarget.classList.add('hidden');
        }
    }

    // Show error message
    showError(message) {
        if (this.hasErrorMessageTarget) {
            this.errorMessageTarget.textContent = message;
            this.errorMessageTarget.classList.remove('hidden');
        } else {
            console.error('Query error:', message);
        }
    }

    // Show cancelled message
    showCancelled(message) {
        if (this.hasErrorMessageTarget) {
            this.errorMessageTarget.textContent = message;
            this.errorMessageTarget.classList.remove('hidden');
        }
    }

    // Close EventSource connection
    closeEventSource() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
        this.currentQueryId = null;
        this.cancelUrl = null;
    }

    // Add a new filter row
    addFilter(event) {
        event.preventDefault();

        const template = document.getElementById('filter-row-template');
        if (!template) return;

        // Clone the template
        const newRow = template.content.cloneNode(true);
        const rowDiv = newRow.querySelector('.filter-row');

        // Update indices
        const newIndex = this.filterCount;
        rowDiv.innerHTML = rowDiv.innerHTML.replace(/__INDEX__/g, newIndex);
        rowDiv.dataset.filterIndex = newIndex;

        // Find the remove button and update its data attribute
        const removeButton = rowDiv.querySelector('[data-action="click->query-builder#removeFilter"]');
        if (removeButton) {
            removeButton.dataset.filterIndex = newIndex;
        }

        this.filterCount++;

        // Hide the new filter row placeholder if visible
        const newFilterRow = this.filterContainerTarget.querySelector('.new-filter-row');
        if (newFilterRow) {
            newFilterRow.style.display = 'none';
        }

        // Insert before the new filter row placeholder
        if (newFilterRow) {
            this.filterContainerTarget.insertBefore(rowDiv, newFilterRow);
        } else {
            this.filterContainerTarget.appendChild(rowDiv);
        }
    }

    // Remove a filter row
    removeFilter(event) {
        event.preventDefault();
        event.stopPropagation();

        const button = event.currentTarget;
        const filterRow = button.closest('.filter-row');

        if (filterRow) {
            filterRow.remove();
        }

        // If no filters left, show the new filter row
        const remainingFilters = this.filterContainerTarget.querySelectorAll('.filter-row:not(.new-filter-row)');
        if (remainingFilters.length === 0) {
            const newFilterRow = this.filterContainerTarget.querySelector('.new-filter-row');
            if (newFilterRow) {
                newFilterRow.style.display = '';
            }
        }
    }

    // Handle attribute selection change (could be used for dynamic operator filtering)
    onAttributeChange(event) {
        // Could implement dynamic operator filtering based on attribute type here
        // For now, just log the change
        const select = event.currentTarget;
        const attribute = select.value;
        console.log('Attribute changed to:', attribute);
    }

    // Handle facet selection change
    onFacetChange(event) {
        const input = event.currentTarget;
        const attribute = input.dataset.facetAttribute;
        const value = input.value;
        const isChecked = input.checked;
        const inputType = input.type; // 'radio' or 'checkbox'

        // Update filters based on facet selection
        if (inputType === 'radio') {
            this.setSingleFacetFilter(attribute, value);
        } else {
            this.updateMultiFacetFilter(attribute, value, isChecked);
        }

        // Submit the query
        this.submitQuery();
    }

    // Set a single-select facet filter
    setSingleFacetFilter(attribute, value) {
        // Remove any existing filter for this attribute
        this.removeFilterByAttribute(attribute);

        // If value is not empty, add a new filter
        if (value && value !== '') {
            this.addFilterWithValues(attribute, 'eq', value);
        }
    }

    // Update a multi-select facet filter
    updateMultiFacetFilter(attribute, value, add) {
        // Find existing filter for this attribute
        const existingFilter = this.findFilterByAttribute(attribute);

        if (add) {
            if (existingFilter) {
                // Would need to update to 'in' operator with array - for now just replace
                this.removeFilterByAttribute(attribute);
            }
            this.addFilterWithValues(attribute, 'eq', value);
        } else {
            // Remove the filter if unchecked
            this.removeFilterByAttribute(attribute);
        }
    }

    // Find a filter row by attribute
    findFilterByAttribute(attribute) {
        const filterRows = this.filterContainerTarget.querySelectorAll('.filter-row:not(.new-filter-row)');
        for (const row of filterRows) {
            const select = row.querySelector('select[name*="[attribute]"]');
            if (select && select.value === attribute) {
                return row;
            }
        }
        return null;
    }

    // Remove filter by attribute
    removeFilterByAttribute(attribute) {
        const filterRow = this.findFilterByAttribute(attribute);
        if (filterRow) {
            filterRow.remove();
        }
    }

    // Add a filter with specific values
    addFilterWithValues(attribute, operator, value) {
        const template = document.getElementById('filter-row-template');
        if (!template) return;

        const newRow = template.content.cloneNode(true);
        const rowDiv = newRow.querySelector('.filter-row');

        const newIndex = this.filterCount;
        rowDiv.innerHTML = rowDiv.innerHTML.replace(/__INDEX__/g, newIndex);
        rowDiv.dataset.filterIndex = newIndex;

        this.filterCount++;

        // Insert the row
        const newFilterRow = this.filterContainerTarget.querySelector('.new-filter-row');
        if (newFilterRow) {
            newFilterRow.style.display = 'none';
            this.filterContainerTarget.insertBefore(rowDiv, newFilterRow);
        } else {
            this.filterContainerTarget.appendChild(rowDiv);
        }

        // Now that the row is in the DOM, set the values
        const insertedRow = this.filterContainerTarget.querySelector(`[data-filter-index="${newIndex}"]`);
        if (insertedRow) {
            const attrSelect = insertedRow.querySelector('select[name*="[attribute]"]');
            const opSelect = insertedRow.querySelector('select[name*="[operator]"]');
            const valueInput = insertedRow.querySelector('input[name*="[value]"]');

            if (attrSelect) attrSelect.value = attribute;
            if (opSelect) opSelect.value = operator;
            if (valueInput) valueInput.value = value;
        }
    }

    // Toggle facet expansion
    toggleFacet(event) {
        const button = event.currentTarget;
        const facetGroup = button.closest('.facet-group');
        const facetValues = facetGroup.querySelector('.facet-values');
        const icon = button.querySelector('svg');

        if (facetValues) {
            facetValues.classList.toggle('hidden');
        }
        if (icon) {
            icon.classList.toggle('rotate-180');
        }

        // Update expanded state
        const isExpanded = button.dataset.expanded === 'true';
        button.dataset.expanded = (!isExpanded).toString();
    }

    // Show more facet values
    showMoreFacetValues(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const attribute = button.dataset.facetAttribute;
        const moreContainer = button.closest('.facet-values').querySelector(`[data-facet-more="${attribute}"]`);

        if (moreContainer) {
            moreContainer.classList.remove('hidden');
            button.classList.add('hidden');
        }
    }

    // Go to a specific page
    goToPage(event) {
        event.preventDefault();
        const link = event.currentTarget;
        const page = link.dataset.page;

        // Update URL with new page
        const url = new URL(window.location.href);
        url.searchParams.set('page', page);
        window.location.href = url.toString();
    }
}
