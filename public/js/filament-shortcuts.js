/**
 * Filament Admin Panel - Keyboard Shortcuts
 * Provides keyboard navigation and shortcuts for improved UX
 */

document.addEventListener('DOMContentLoaded', function() {
    // Modal HTML for keyboard shortcuts help
    const shortcutsModalHTML = `
        <div id="shortcuts-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50" role="dialog" aria-modal="true">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full mx-4 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        Keyboard Shortcuts
                    </h2>
                    <button id="close-shortcuts-modal" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex items-center justify-between py-2 px-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                            <span class="text-gray-700 dark:text-gray-300">Global Search</span>
                            <kbd class="px-2 py-1 text-xs font-semibold bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded">/</kbd>
                        </div>
                        <div class="flex items-center justify-between py-2 px-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                            <span class="text-gray-700 dark:text-gray-300">Create New Record</span>
                            <div class="flex gap-1">
                                <kbd class="px-2 py-1 text-xs font-semibold bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded">N</kbd>
                                <span class="text-gray-500 dark:text-gray-400">or</span>
                                <kbd class="px-2 py-1 text-xs font-semibold bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded">C</kbd>
                            </div>
                        </div>
                        <div class="flex items-center justify-between py-2 px-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                            <span class="text-gray-700 dark:text-gray-300">Save Form</span>
                            <kbd class="px-2 py-1 text-xs font-semibold bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded">Ctrl+S / ⌘+S</kbd>
                        </div>
                        <div class="flex items-center justify-between py-2 px-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                            <span class="text-gray-700 dark:text-gray-300">Show Shortcuts</span>
                            <kbd class="px-2 py-1 text-xs font-semibold bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded">?</kbd>
                        </div>
                        <div class="flex items-center justify-between py-2 px-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                            <span class="text-gray-700 dark:text-gray-300">Close Modal</span>
                            <kbd class="px-2 py-1 text-xs font-semibold bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded">ESC</kbd>
                        </div>
                    </div>
                    <div class="pt-4 border-t border-gray-200 dark:border-gray-600">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Form Navigation</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex items-center justify-between py-2 px-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                                <span class="text-gray-700 dark:text-gray-300">Next Field</span>
                                <kbd class="px-2 py-1 text-xs font-semibold bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded">Tab</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                                <span class="text-gray-700 dark:text-gray-300">Previous Field</span>
                                <kbd class="px-2 py-1 text-xs font-semibold bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded">Shift+Tab</kbd>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Inject modal into body
    document.body.insertAdjacentHTML('beforeend', shortcutsModalHTML);

    const modal = document.getElementById('shortcuts-modal');
    const closeButton = document.getElementById('close-shortcuts-modal');

    // Modal controls
    function showModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function hideModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    closeButton?.addEventListener('click', hideModal);
    modal?.addEventListener('click', function(e) {
        if (e.target === modal) {
            hideModal();
        }
    });

    // Check if element is an input/textarea/select or contenteditable
    function isInputElement(element) {
        const tagName = element.tagName.toLowerCase();
        const isEditable = element.isContentEditable;
        return tagName === 'input' || tagName === 'textarea' || tagName === 'select' || isEditable;
    }

    // Global keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S / Cmd+S - Save form
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            const saveButton = document.querySelector('button[type="submit"]') ||
                             document.querySelector('[wire\\:click*="save"]') ||
                             document.querySelector('.fi-btn[type="submit"]') ||
                             document.querySelector('button[form]');
            if (saveButton && !saveButton.disabled) {
                saveButton.click();
            }
            return;
        }

        // Don't trigger shortcuts when typing in input fields (for non-save shortcuts)
        if (isInputElement(e.target)) {
            return;
        }

        // "/" - Focus global search
        if (e.key === '/') {
            e.preventDefault();
            const searchInput = document.querySelector('input[type="search"]') || 
                              document.querySelector('[data-slot="search"]') ||
                              document.querySelector('.fi-global-search-input');
            if (searchInput) {
                searchInput.focus();
            }
        }

        // "n", "N", "c", or "C" - Create new record (find create button on list pages)
        if (e.key === 'n' || e.key === 'N' || e.key === 'c' || e.key === 'C') {
            e.preventDefault();
            const createButton = document.querySelector('[href*="create"]') ||
                               document.querySelector('.fi-ac-btn-create') ||
                               document.querySelector('a[href$="/create"]');
            if (createButton) {
                createButton.click();
            }
        }

        // "?" - Show keyboard shortcuts modal
        if (e.key === '?' && e.shiftKey) {
            e.preventDefault();
            showModal();
        }

        // "Escape" - Close modal
        if (e.key === 'Escape') {
            hideModal();
        }
    });

    // Form autofocus on first field
    function autofocusFirstField() {
        // Wait for form to render
        setTimeout(() => {
            const form = document.querySelector('form');
            if (form) {
                const firstInput = form.querySelector('input:not([type="hidden"]):not([readonly]), textarea:not([readonly]), select:not([readonly])');
                if (firstInput) {
                    firstInput.focus();
                }
            }
        }, 300);
    }

    // Watch for form modals/pages
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) { // Element node
                        // Check if it's a form modal or page
                        if (node.querySelector('form') || node.tagName === 'FORM') {
                            autofocusFirstField();
                        }
                    }
                });
            }
        });
    });

    // Start observing
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Autofocus on page load if form exists
    autofocusFirstField();

    // Wizard shortcuts (Alt+N for next, Alt+B for back)
    document.addEventListener('keydown', function(e) {
        // Alt+N - Next step in wizard
        if (e.altKey && e.key === 'n') {
            e.preventDefault();
            const nextButton = document.querySelector('[wire\\:click*="nextStep"]') ||
                             document.querySelector('.fi-wizard-next-button') ||
                             document.querySelector('button[type="submit"]');
            if (nextButton && !nextButton.disabled) {
                nextButton.click();
            }
        }

        // Alt+B - Previous step in wizard
        if (e.altKey && e.key === 'b') {
            e.preventDefault();
            const backButton = document.querySelector('[wire\\:click*="previousStep"]') ||
                             document.querySelector('.fi-wizard-back-button');
            if (backButton && !backButton.disabled) {
                backButton.click();
            }
        }
    });

    console.log('✅ Filament keyboard shortcuts initialized');
});
