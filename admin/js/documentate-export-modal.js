(function ($) {
    'use strict';

    const config = window.documentateExportModalConfig || {};
    const selectors = {
        modal: '#documentate-export-modal',
        open: '[data-documentate-export-modal-open]',
        close: '[data-documentate-export-modal-close]',
        step: '[data-documentate-step]'
    };
    const bodyOpenClass = 'documentate-export-modal--open';
    const readyEvent = (config.events && config.events.ready) || 'documentateZeta:ready';
    const errorEvent = (config.events && config.events.error) || 'documentateZeta:error';
    const frameTarget = config.frameTarget || 'documentateExportFrame';
    const strings = config.strings || {};

    const state = {
        loaderPromise: null,
        loaderLoaded: false,
        lastTrigger: null
    };

    function getModal() {
        return $(selectors.modal);
    }

    function normalizeAvailable(value) {
        if (typeof value === 'string') {
            return value === '1';
        }
        return Boolean(value);
    }

    function setStepState(step, stateName, message) {
        const $modal = getModal();
        const $step = $modal.find(selectors.step + '[data-documentate-step="' + step + '"]');
        if (!$step.length) {
            return;
        }
        const states = 'is-pending is-active is-ready is-done is-error';
        $step.removeClass(states);
        if (stateName) {
            $step.addClass('is-' + stateName);
        }
        if (typeof message === 'string') {
            const $status = $step.find('[data-documentate-step-status]');
            if ($status.length) {
                $status.text(message);
            }
        }
    }

    function resetSteps() {
        const $modal = getModal();
        setStepState('loader', 'active', strings.loaderLoading || '');
        $modal.find(selectors.step).each(function () {
            const $item = $(this);
            const key = $item.data('documentate-step');
            if (key === 'loader') {
                return;
            }
            const available = normalizeAvailable($item.data('documentate-step-available'));
            if (available) {
                setStepState(key, 'pending', strings.stepPending || '');
            } else {
                $item.addClass('is-disabled');
            }
        });
    }

    function markStepsReady() {
        const $modal = getModal();
        $modal.find(selectors.step).each(function () {
            const $item = $(this);
            const key = $item.data('documentate-step');
            if (key === 'loader') {
                return;
            }
            if (!normalizeAvailable($item.data('documentate-step-available'))) {
                return;
            }
            setStepState(key, 'ready', strings.stepReady || '');
            const $button = $modal.find('[data-documentate-step-target="' + key + '"]');
            if ($button.length) {
                $button.removeClass('disabled').removeAttr('aria-disabled');
            }
        });
    }

    function ensureLoader() {
        if (state.loaderLoaded || (window.documentateZeta && window.documentateZeta.ready)) {
            state.loaderLoaded = true;
            return Promise.resolve();
        }
        if (state.loaderPromise) {
            return state.loaderPromise;
        }
        state.loaderPromise = new Promise(function (resolve, reject) {
            const onReady = function () {
                window.removeEventListener(readyEvent, onReady);
                window.removeEventListener(errorEvent, onError);
                state.loaderLoaded = true;
                state.loaderPromise = null;
                resolve();
            };
            const onError = function (event) {
                window.removeEventListener(readyEvent, onReady);
                window.removeEventListener(errorEvent, onError);
                state.loaderPromise = null;
                reject(event);
            };
            window.addEventListener(readyEvent, onReady, { once: true });
            window.addEventListener(errorEvent, onError, { once: true });

            if (config.loaderConfig) {
                window.documentateZetaLoaderConfig = $.extend(true, {}, config.loaderConfig, window.documentateZetaLoaderConfig || {});
            }

            if (window.documentateZeta && window.documentateZeta.ready) {
                onReady();
                return;
            }

            const existing = document.querySelector('script[data-documentate-zetajs-loader="1"]');
            if (existing) {
                return;
            }

            const scriptUrl = config.loaderUrl;
            if (!scriptUrl) {
                onError(new Error('Missing loader URL'));
                return;
            }

            const script = document.createElement('script');
            script.type = 'module';
            script.src = scriptUrl;
            script.dataset.documentateZetajsLoader = '1';
            script.addEventListener('error', onError);
            document.head.appendChild(script);
        });

        return state.loaderPromise;
    }

    function getDownloadFrame() {
        const frames = document.getElementsByName(frameTarget);
        if (frames && frames.length) {
            return frames[0];
        }
        return null;
    }

    function attachFrameListener($modal) {
        const frame = getDownloadFrame();
        if (!frame) {
            return;
        }
        frame.addEventListener('load', function () {
            const activeStep = frame.dataset.documentateActiveStep;
            if (!activeStep) {
                return;
            }
            setStepState(activeStep, 'done', strings.stepDone || '');
            frame.dataset.documentateActiveStep = '';
        });
    }

    function showModal(trigger) {
        const $modal = getModal();
        if (!$modal.length) {
            return;
        }
        state.lastTrigger = trigger || null;
        $modal.removeAttr('hidden');
        $('body').addClass(bodyOpenClass);
        resetSteps();
        ensureLoader()
            .then(function () {
                setStepState('loader', 'done', strings.loaderReady || '');
                markStepsReady();
            })
            .catch(function (error) {
                setStepState('loader', 'error', strings.loaderError || '');
                window.console.error('Documentate ZetaJS', error);
            });
        const $close = $modal.find(selectors.close).first();
        if ($close.length) {
            setTimeout(function () {
                $close.trigger('focus');
            }, 0);
        }
    }

    function closeModal() {
        const $modal = getModal();
        if (!$modal.length || $modal.is('[hidden]')) {
            return;
        }
        $modal.attr('hidden', 'hidden');
        $('body').removeClass(bodyOpenClass);
        if (state.lastTrigger && typeof state.lastTrigger.focus === 'function') {
            try {
                state.lastTrigger.focus();
            } catch (e) {
                // Ignore focus errors.
            }
        }
        state.lastTrigger = null;
    }

    function handleActionClick(event) {
        const $button = $(this);
        if ($button.is('[aria-disabled="true"]') || $button.hasClass('disabled')) {
            event.preventDefault();
            return;
        }
        const step = $button.data('documentate-step-target');
        if (!step) {
            return;
        }
        setStepState(step, 'active', strings.stepWorking || '');
        const frame = getDownloadFrame();
        if (frame) {
            frame.dataset.documentateActiveStep = step;
        }
    }

    function bindEvents() {
        const $modal = getModal();
        if (!$modal.length) {
            return;
        }

        attachFrameListener($modal);

        $(document).on('click', selectors.open, function (event) {
            event.preventDefault();
            showModal(this);
        });

        $modal.on('click', selectors.close, function (event) {
            event.preventDefault();
            closeModal();
        });

        $modal.on('click', '.documentate-export-modal__overlay', function (event) {
            if (event.target === this) {
                closeModal();
            }
        });

        $modal.on('click', '[data-documentate-step-target]', handleActionClick);

        $(document).on('keydown', function (event) {
            if ('Escape' === event.key && !getModal().is('[hidden]')) {
                event.preventDefault();
                closeModal();
            }
        });
    }

    $(function () {
        bindEvents();
    });
})(jQuery);
