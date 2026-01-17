import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['form', 'filterContainer', 'projectSelect', 'projectInput', 'groupBy', 'facetPanel', 'results'];
    static values = {
        resultsUrl: String,
        facetsUrl: String
    };

    filterCount = 0;

    connect() {
        // Initialize filter count based on existing filters
        const filterRows = this.filterContainerTarget.querySelectorAll('.filter-row:not(.new-filter-row)');
        this.filterCount = filterRows.length;
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
