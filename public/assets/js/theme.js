(() => {
  const root = document.documentElement;
  try {
    const saved = localStorage.getItem("wtfcode-theme");
    if (saved === "light" || saved === "dark") root.dataset.theme = saved;
  } catch {
    /* Storage can be disabled; retain the accessible default. */
  }
  document.addEventListener("DOMContentLoaded", () => {
    const toggle = document.querySelector("[data-theme-toggle]");
    if (!toggle) return;
    const update = () => {
      const next = root.dataset.theme === "dark" ? "light" : "dark";
      toggle.textContent = next === "light" ? "Light" : "Dark";
      toggle.setAttribute("aria-label", `Switch to ${next} theme`);
    };
    toggle.addEventListener("click", () => {
      root.dataset.theme = root.dataset.theme === "dark" ? "light" : "dark";
      try {
        localStorage.setItem("wtfcode-theme", root.dataset.theme);
      } catch {
        /* Optional preference. */
      }
      update();
    });
    update();
  });
})();
