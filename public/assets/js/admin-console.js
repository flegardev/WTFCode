(() => {
    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        const message = form?.dataset.confirm;
        if (form && message && !window.confirm(message)) event.preventDefault();
    });
})();
