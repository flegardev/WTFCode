(() => {
  const search = document.querySelector('[data-repository-search]');
  const select = document.querySelector('[data-repository-select]');
  if (!(search instanceof HTMLInputElement) || !(select instanceof HTMLSelectElement)) return;
  const options = Array.from(select.options).slice(1).map((option) => ({ value: option.value, label: option.textContent || '' }));
  search.addEventListener('input', () => {
    const chosen = select.value;
    const query = search.value.trim().toLowerCase();
    select.replaceChildren(new Option('Choose a repository', ''));
    for (const option of options) {
      if (query !== '' && !option.label.toLowerCase().includes(query)) continue;
      select.add(new Option(option.label, option.value, false, option.value === chosen));
    }
  });
})();
