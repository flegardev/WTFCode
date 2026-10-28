(() => {
  "use strict";

  document.querySelectorAll("[data-change-intent]").forEach((button) =>
    button.addEventListener("click", () => {
      const intent = document.querySelector('[name="request"]');
      if (intent) {
        intent.value = button.dataset.changeIntent;
        intent.focus();
      }
    }),
  );
  document
    .querySelector("[data-change-form]")
    ?.addEventListener("submit", (event) => {
      if (!event.target.checkValidity()) return;
      const status = event.target.querySelector("[data-change-status]");
      if (status) status.hidden = false;
      event.target.setAttribute("aria-busy", "true");
    });
  document.querySelectorAll("[data-copy-target]").forEach((button) => {
    button.addEventListener("click", async () => {
      const target = document.getElementById(button.dataset.copyTarget || "");
      const status = button
        .closest("section")
        ?.querySelector("[data-copy-status]");
      if (!(target instanceof HTMLTextAreaElement)) return;
      try {
        await navigator.clipboard.writeText(target.value);
        if (status) status.textContent = "Prompt copied.";
      } catch {
        target.focus();
        target.select();
        if (status)
          status.textContent =
            "Prompt selected. Press Ctrl+C or Command+C to copy.";
      }
    });
  });
})();
