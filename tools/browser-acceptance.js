// Run after a real public scan, while signed in on project.php?id=...:
// playwright-cli run-code --filename=tools/browser-acceptance.js
async (page) => {
  const base = new URL(page.url()).origin;
  const id = new URL(page.url()).searchParams.get('id');
  if (!id) throw new Error('Open a scanned project before running this check.');
  const checks = [];
  const check = (ok, name) => { if (!ok) throw new Error(name); checks.push(name); };
  await page.keyboard.press('Escape');
  const health = await page.request.get(`${base}/health.php`);
  check(health.status() === 200, 'Core database, sessions and queue readiness');
  const rejected = await page.request.post(`${base}/ask.php?id=${id}`, { headers: { Accept: 'application/json' }, form: { question: 'authentication', csrf_token: 'invalid' } });
  check(rejected.status() === 419, 'Invalid CSRF rejected before Q&A');
  const csrf = await page.locator('form input[name="csrf_token"]').first().inputValue();
  const answered = await page.request.post(`${base}/ask.php?id=${id}`, { headers: { Accept: 'application/json' }, form: { question: 'Where does authentication happen?', csrf_token: csrf } });
  const answer = await answered.json();
  check(answered.status() === 200 && answer.provider === 'deterministic' && answer.evidence.length > 0, 'Real source-backed deterministic Q&A');
  check(/\[\d+\]/.test(answer.answer), 'Answer contains numbered source references');

  // A separate real account exercises HTTP ownership boundaries, not mock data.
  const other = await page.context().browser().newContext();
  try {
    const stranger = await other.newPage();
    await stranger.goto(`${base}/register.php`);
    await stranger.getByRole('textbox', { name: 'Name', exact: true }).fill('Boundary test account');
    await stranger.getByRole('textbox', { name: 'Email', exact: true }).fill(`boundary-${Date.now()}@wtfcode.local`);
    await stranger.getByRole('textbox', { name: 'Password', exact: true }).fill('Disposable-staging-account-48271');
    await stranger.getByRole('textbox', { name: 'Confirm password' }).fill('Disposable-staging-account-48271');
    await stranger.getByRole('button', { name: 'Create workspace' }).click();
    check(new URL(stranger.url()).pathname === '/dashboard.php', 'Second account registration and session');
    for (const endpoint of ['project.php', 'map.php', 'files.php', 'change.php', 'ask.php', 'scan-status.php']) {
      const result = await other.request.get(`${base}/${endpoint}?id=${id}`);
      check(result.status() === 404, `Cross-owner ${endpoint} blocked`);
    }
  } finally { await other.close(); }

  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 1000 });
    for (const endpoint of ['dashboard.php', `project.php?id=${id}`, `map.php?id=${id}`, `change.php?id=${id}&type=file&target=src/app/routes/auth/auth.ts`, `files.php?id=${id}`, 'project-create.php', 'pricing.php']) {
      const response = await page.goto(`${base}/${endpoint}`);
      check(response.status() === 200, `${width}px ${endpoint} loads`);
      const overflow = await page.evaluate(() => ({ width: innerWidth, content: document.documentElement.scrollWidth }));
      check(overflow.content <= overflow.width + 1, `${width}px ${endpoint} has no horizontal page overflow`);
      if (width === 390 && endpoint.startsWith('project.php')) await page.screenshot({ path: 'output/playwright/mobile-project.png', fullPage: true });
    }
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(`${base}/dashboard.php`);
  await page.getByRole('button', { name: 'Switch to light theme' }).click();
  await page.reload();
  check(await page.locator('html').getAttribute('data-theme') === 'light', 'Light theme preference survives navigation');
  await page.screenshot({ path: 'output/playwright/dashboard-light.png' });
  await page.getByRole('button', { name: 'Switch to dark theme' }).click();
  await page.screenshot({ path: 'output/playwright/dashboard.png' });
  await page.goto(`${base}/project.php?id=${id}`);
  await page.screenshot({ path: 'output/playwright/project-analysis.png', fullPage: true });
  check(errors.length === 0, `Browser runtime errors: ${errors.join('; ')}`);
  return { status: 'passed', checks, questionProvider: answer.provider, sourceReferences: answer.evidence.length };
}
