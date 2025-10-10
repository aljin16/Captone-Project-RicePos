/**
 * Floating Labels JavaScript
 * Handles dynamic label behavior and select element value detection
 */

(function() {
    'use strict';

    // Initialize floating labels when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initializeFloatingLabels();
    });

    function initializeFloatingLabels() {
        // Handle all floating field containers
        const floatingFields = document.querySelectorAll('.floating-field');
        
        floatingFields.forEach(function(field) {
            const input = field.querySelector('input, select, textarea');
            const label = field.querySelector('label');
            
            if (!input || !label) return;

            // Set up event listeners
            setupInputListeners(input, label, field);
            
            // Handle initial state
            updateLabelState(input, label, field);
            
            // Special handling for select elements
            if (input.tagName === 'SELECT') {
                handleSelectElement(input, label, field);
            }
        });
    }

    function setupInputListeners(input, label, field) {
        // Focus events
        input.addEventListener('focus', function() {
            field.classList.add('focused');
            updateLabelState(input, label, field);
        });

        input.addEventListener('blur', function() {
            field.classList.remove('focused');
            updateLabelState(input, label, field);
        });

        // Input events for text inputs
        if (input.type !== 'select-one') {
            input.addEventListener('input', function() {
                updateLabelState(input, label, field);
            });
        }

        // Change events for select elements
        if (input.tagName === 'SELECT') {
            input.addEventListener('change', function() {
                updateLabelState(input, label, field);
            });
        }
    }

    function updateLabelState(input, label, field) {
        const hasValue = hasInputValue(input);
        const isFocused = field.classList.contains('focused') || document.activeElement === input;
        
        // Add/remove floating class based on value or focus
        if (hasValue || isFocused) {
            field.classList.add('floating');
        } else {
            field.classList.remove('floating');
        }

        // Update select data attribute for CSS targeting
        if (input.tagName === 'SELECT') {
            input.setAttribute('data-has-value', hasValue ? 'true' : 'false');
        }
    }

    function hasInputValue(input) {
        if (input.tagName === 'SELECT') {
            return input.value !== '' && input.value !== null;
        }
        
        if (input.type === 'checkbox' || input.type === 'radio') {
            return input.checked;
        }
        
        if (input.type === 'file') {
            return input.files && input.files.length > 0;
        }
        
        // For text inputs, textareas, etc.
        return input.value.trim() !== '';
    }

    function handleSelectElement(select, label, field) {
        // Set initial state
        updateLabelState(select, label, field);
        
        // Handle programmatic value changes
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
                    updateLabelState(select, label, field);
                }
            });
        });
        
        observer.observe(select, {
            attributes: true,
            attributeFilter: ['value']
        });
    }

    // Utility function to add floating label to existing elements
    window.addFloatingLabel = function(element) {
        if (!element || !element.classList.contains('floating-field')) {
            console.warn('Element must have .floating-field class');
            return;
        }
        
        const input = element.querySelector('input, select, textarea');
        const label = element.querySelector('label');
        
        if (input && label) {
            setupInputListeners(input, label, element);
            updateLabelState(input, label, element);
            
            if (input.tagName === 'SELECT') {
                handleSelectElement(input, label, element);
            }
        }
    };

    // Utility function to remove floating label behavior
    window.removeFloatingLabel = function(element) {
        if (!element || !element.classList.contains('floating-field')) {
            return;
        }
        
        const input = element.querySelector('input, select, textarea');
        if (input) {
            // Remove event listeners by cloning the element
            const newInput = input.cloneNode(true);
            input.parentNode.replaceChild(newInput, input);
            
            // Remove classes
            element.classList.remove('floating', 'focused');
        }
    };

    // Handle dynamically added elements
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) { // Element node
                    // Check if the added node is a floating field
                    if (node.classList && node.classList.contains('floating-field')) {
                        const input = node.querySelector('input, select, textarea');
                        const label = node.querySelector('label');
                        
                        if (input && label) {
                            setupInputListeners(input, label, node);
                            updateLabelState(input, label, node);
                            
                            if (input.tagName === 'SELECT') {
                                handleSelectElement(input, label, node);
                            }
                        }
                    }
                    
                    // Check for floating fields within the added node
                    const floatingFields = node.querySelectorAll && node.querySelectorAll('.floating-field');
                    if (floatingFields) {
                        floatingFields.forEach(function(field) {
                            const input = field.querySelector('input, select, textarea');
                            const label = field.querySelector('label');
                            
                            if (input && label) {
                                setupInputListeners(input, label, field);
                                updateLabelState(input, label, field);
                                
                                if (input.tagName === 'SELECT') {
                                    handleSelectElement(input, label, field);
                                }
                            }
                        });
                    }
                }
            });
        });
    });

    // Start observing
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Form validation integration
    window.validateFloatingField = function(field, isValid, message) {
        if (!field || !field.classList.contains('floating-field')) {
            return;
        }
        
        // Remove existing validation classes
        field.classList.remove('error', 'success');
        
        // Remove existing helper text
        const existingHelper = field.querySelector('.helper-text');
        if (existingHelper) {
            existingHelper.remove();
        }
        
        if (isValid === false) {
            field.classList.add('error');
            if (message) {
                addHelperText(field, message, 'error');
            }
        } else if (isValid === true) {
            field.classList.add('success');
            if (message) {
                addHelperText(field, message, 'success');
            }
        }
    };

    function addHelperText(field, message, type) {
        const helperText = document.createElement('div');
        helperText.className = `helper-text ${type}`;
        helperText.textContent = message;
        field.appendChild(helperText);
    }

    // Export for module systems
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            initializeFloatingLabels: initializeFloatingLabels,
            addFloatingLabel: window.addFloatingLabel,
            removeFloatingLabel: window.removeFloatingLabel,
            validateFloatingField: window.validateFloatingField
        };
    }

})();
