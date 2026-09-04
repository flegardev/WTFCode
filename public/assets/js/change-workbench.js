(() => {
    'use strict';

    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.getElementById(button.dataset.copyTarget || '');
            const status = button.closest('section')?.querySelector('[data-copy-status]');
            if (!(target instanceof HTMLTextAreaElement)) return;
            try {
                await navigator.clipboard.writeText(target.value);
                if (status) status.textContent = 'Prompt copied.';
            } catch {
                target.focus();
                target.select();
                if (status) status.textContent = 'Prompt selected. Press Ctrl+C or Command+C to copy.';
            }
        });
    });
})();
