(function () {
    'use strict';

    const config = window.documentateExportWorkspaceConfig || {};
    const readyEvent = (config.events && config.events.ready) || 'documentateZeta:ready';
    const errorEvent = (config.events && config.events.error) || 'documentateZeta:error';
    const frameTarget = config.frameTarget || 'documentateExportFrame';
    const strings = config.strings || {};

    const statusElement = document.querySelector('[data-documentate-workspace-status]');
    const steps = Array.from(document.querySelectorAll('[data-documentate-step]'));

    const frame = (function getFrame() {
        const frames = document.getElementsByName(frameTarget);
        if (frames && frames.length) {
            return frames[0];
        }
        return null;
    })();

    function setStatus(message, stateClass) {
        if (!statusElement) {
            return;
        }
        statusElement.textContent = message || '';
        statusElement.dataset.state = stateClass || '';
    }

    function setStepState(stepKey, state, message) {
        const element = steps.find((item) => item.dataset.documentateStep === stepKey);
        if (!element) {
            return;
        }
        element.classList.remove('is-pending', 'is-active', 'is-ready', 'is-done', 'is-error');
        if (state) {
            element.classList.add('is-' + state);
        }
        if (typeof message === 'string') {
            const status = element.querySelector('[data-documentate-step-status]');
            if (status) {
                status.textContent = message;
            }
        }
    }

    function markInitialStates() {
        setStepState('loader', 'active', strings.loaderLoading || '');
        steps.forEach((element) => {
            const key = element.dataset.documentateStep;
            if (!key || key === 'loader') {
                return;
            }
            const available = element.dataset.documentateStepAvailable === '1';
            if (available) {
                setStepState(key, 'pending', strings.stepPending || '');
            }
        });
    }

    function enableButtons() {
        document.querySelectorAll('[data-documentate-step-target]').forEach((button) => {
            button.classList.remove('disabled');
            button.removeAttribute('aria-disabled');
        });
    }

    function handleReady() {
        setStatus(strings.loaderReady || '');
        setStepState('loader', 'done', strings.loaderReady || '');
        steps.forEach((element) => {
            const key = element.dataset.documentateStep;
            if (!key || key === 'loader') {
                return;
            }
            if (element.dataset.documentateStepAvailable !== '1') {
                return;
            }
            setStepState(key, 'ready', strings.stepReady || '');
        });
        enableButtons();
    }

    function handleError(event) {
        const message = strings.loaderError || '';
        setStatus(message, 'error');
        setStepState('loader', 'error', message);
        if (event && event.detail && event.detail.error) {
            // eslint-disable-next-line no-console
            console.error('Documentate ZetaJS', event.detail.error);
        }
    }

    function handleActionClick(event) {
        const target = event.currentTarget;
        if (target.classList.contains('disabled') || target.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
            return;
        }
        const step = target.dataset.documentateStepTarget;
        if (!step) {
            return;
        }
        setStepState(step, 'active', strings.stepWorking || '');
        if (frame) {
            frame.dataset.documentateActiveStep = step;
        }
    }

    function handleFrameLoad() {
        if (!frame) {
            return;
        }
        const activeStep = frame.dataset.documentateActiveStep;
        if (!activeStep) {
            return;
        }
        setStepState(activeStep, 'done', strings.stepDone || '');
        frame.dataset.documentateActiveStep = '';
    }

    function bindEvents() {
        document.querySelectorAll('[data-documentate-step-target]').forEach((button) => {
            button.addEventListener('click', handleActionClick);
        });
        if (frame) {
            frame.addEventListener('load', handleFrameLoad);
        }
        window.addEventListener(readyEvent, handleReady, { once: true });
        window.addEventListener(errorEvent, handleError, { once: true });
    }

    document.addEventListener('DOMContentLoaded', () => {
        markInitialStates();
        setStatus(strings.loaderLoading || '');
        bindEvents();
    });
})();
