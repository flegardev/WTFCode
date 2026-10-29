"use strict";
(() => {
  const readLink = () => {
    const token = new URLSearchParams(location.hash.slice(1)).get("token");
    history.replaceState(null, "", location.pathname + location.search);
    if (!token || !/^[a-f0-9]{64}$/.test(token)) return;
    document.getElementById("account-token").value = token;
    document.getElementById("account-token-label").hidden = true;
    document.getElementById("account-token-help").hidden = true;
    document.querySelector("[data-account-token-error]")?.remove();
  };
  readLink();
  window.addEventListener("hashchange", readLink);
})();
