/**
 * TinyMCE unsupported HTML warning detection.
 *
 * Monitors TinyMCE editors for unsupported HTML formatting and shows/hides
 * warning messages accordingly.
 *
 * @package Documentate
 */

(function () {
    'use strict';

    /**
     * Check if content contains unsupported HTML patterns.
     *
     * Detects: <div>, <font>, <form>, <input>, <button>, ...
     *
     * @param {string} content - HTML content to check.
     * @returns {boolean} True if unsupported patterns are found.
     */
    function hasUnsupportedHtml(content) {
        if (!content || typeof content !== 'string') {
            return false;
        }

        // Patterns for unsupported HTML (case-insensitive).
        var patterns = [
            /<div\b[^>]*>/i,
            /<font\b[^>]*>/i,
            /<form\b[^>]*>/i,
            /<input\b[^>]*>/i,
            /<button\b[^>]*>/i,
        ];

        for (var i = 0; i < patterns.length; i++) {
            if (patterns[i].test(content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Toggle warning visibility based on editor content.
     *
     * @param {string} editorId - The TinyMCE editor ID.
     * @param {string} content - The editor content to check.
     */
    function toggleWarning(editorId, content) {
        var warningEl = document.getElementById(editorId + '-unsupported-warning');
        if (!warningEl) {
            return;
        }

        if (hasUnsupportedHtml(content)) {
            warningEl.style.display = 'block';
        } else {
            warningEl.style.display = 'none';
        }
    }

    /**
     * Attach validation to a TinyMCE editor instance.
     *
     * @param {string} editorId - The TinyMCE editor ID.
     */
    function attachTinyMceValidation(editorId) {
        // Check if TinyMCE is available.
        if (typeof tinymce === 'undefined') {
            attachTextareaValidation(editorId);
            return;
        }

        var editor = tinymce.get(editorId);
        if (!editor) {
            attachTextareaValidation(editorId);
            return;
        }

        // Check on editor setup/load.
        editor.on('init', function () {
            var content = editor.getContent({ format: 'raw' });
            toggleWarning(editorId, content);
        });

        // Check immediately if editor is already initialized.
        if (editor.initialized) {
            var content = editor.getContent({ format: 'raw' });
            toggleWarning(editorId, content);
        }

        // Listen to content change events.
        var events = ['keyup', 'change', 'NodeChange', 'SetContent'];
        events.forEach(function (eventName) {
            editor.on(eventName, function () {
                var content = editor.getContent({ format: 'raw' });
                toggleWarning(editorId, content);
            });
        });

        // Handle paste event specifically.
        editor.on('paste', function () {
            // Delay to allow paste content to be processed.
            setTimeout(function () {
                var content = editor.getContent({ format: 'raw' });
                toggleWarning(editorId, content);
            }, 100);
        });
    }

    /**
     * Attach validation to a plain textarea (fallback when TinyMCE is not available).
     *
     * @param {string} editorId - The textarea ID.
     */
    function attachTextareaValidation(editorId) {
        var textarea = document.getElementById(editorId);
        if (!textarea) {
            return;
        }

        // Initial check.
        toggleWarning(editorId, textarea.value);

        // Listen to input events.
        textarea.addEventListener('input', function () {
            toggleWarning(editorId, textarea.value);
        });
    }

    /**
     * Find all Documentate TinyMCE editors on the page and attach validation.
     */
    function initAllEditors() {
        // Find all warning elements and extract editor IDs from them.
        var warnings = document.querySelectorAll('.documentate-unsupported-warning');
        var editorIds = [];

        warnings.forEach(function (warning) {
            var id = warning.id;
            if (id && id.endsWith('-unsupported-warning')) {
                var editorId = id.replace('-unsupported-warning', '');
                editorIds.push(editorId);
            }
        });

        // Attach validation to each editor.
        editorIds.forEach(function (editorId) {
            if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                attachTinyMceValidation(editorId);
            } else {
                // Editor not yet initialized, wait for it.
                if (typeof tinymce !== 'undefined') {
                    tinymce.on('AddEditor', function (e) {
                        if (e.editor && e.editor.id === editorId) {
                            attachTinyMceValidation(editorId);
                        }
                    });
                }
                // Also try textarea fallback for initial check.
                attachTextareaValidation(editorId);
            }
        });
    }

    // Initialize on DOM ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllEditors);
    } else {
        initAllEditors();
    }

    // Also hook into TinyMCE initialization for editors created after page load.
    if (typeof tinymce !== 'undefined') {
        tinymce.on('AddEditor', function (e) {
            if (e.editor) {
                var editorId = e.editor.id;
                var warningEl = document.getElementById(editorId + '-unsupported-warning');
                if (warningEl) {
                    attachTinyMceValidation(editorId);
                }
            }
        });
    }
})();
