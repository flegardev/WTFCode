// Run against a signed-in, freshly scanned disposable public sample.
async (page) => {
    const base = new URL(page.url()).origin;
    const checks = [];
    const results = [];
    const failures = [];
    const errors = [];
    const check = (ok, name) => { if (!ok) failures.push(name); else checks.push(name); };
    page.on('pageerror', error => errors.push(error.message));
    const paths = ['dashboard.php', 'project-create.php', 'project.php?id=1', 'map.php?id=1', 'change.php?id=1', 'change.php?id=1&type=file&target=src/app/routes/auth/auth.ts', 'file.php?project=1&id=25', 'ask.php?id=1', 'pricing.php', 'settings.php', 'example.php'];
    for (const theme of ['dark', 'light']) {
        await page.evaluate(theme => { localStorage.setItem('wtfcode-theme', theme); document.documentElement.dataset.theme = theme; }, theme);
        for (const width of [320, 375, 390, 430, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const path of paths) {
                const response = await page.goto(`${base}/${path}`);
                check(response.status() === 200, `${theme} ${width}px ${path}: HTTP 200`);
                if (path.startsWith('change.php?id=1&type=')) check(await page.locator('.impact-intro').isVisible(), `${theme} ${width}px change result stays visible when its edit form is collapsed`);
                const geometry = await page.evaluate(() => ({ viewport: innerWidth, content: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight, outliers: [...document.querySelectorAll('main *')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && (r.right > innerWidth + 1 || r.left < -1) && getComputedStyle(e).position !== 'absolute'; }).slice(0, 4).map(e => e.className) }));
                results.push({ theme, width, path, ...geometry });
                check(geometry.content <= geometry.viewport + 1, `${theme} ${width}px ${path}: no page overflow: ${JSON.stringify(geometry)}`);
            }
        }
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${base}/project.php?id=1`);
    const trigger = page.locator('.project-nav-ask');
    await trigger.focus(); await page.keyboard.press('Enter');
    check(await page.locator('[data-ask-question]').evaluate(e => e === document.activeElement), 'Drawer autofocus');
    await page.locator('[data-ask-question]').fill('Where does authentication happen?');
    await page.locator('[data-ask-submit]').click();
    await page.locator('[data-ask-result]').waitFor({ state: 'visible' });
    check(await page.locator('[data-ask-evidence] a').count() === 4, 'Four visible clickable source references');
    check(!(await page.locator('[data-ask-answer]').innerText()).includes('normalized evidence'), 'Plain answer replaces internal engine terminology');
    const href = await page.locator('[data-ask-evidence] a').first().getAttribute('href');
    check(new URL(href).origin === base && new URL(href).searchParams.get('project') === '1', 'Source links stay in the owned project');
    await page.keyboard.press('Escape');
    check(await trigger.evaluate(e => e === document.activeElement), 'Escape restores opener focus');
    await page.goto(href);
    check(await page.locator('#source-evidence').getAttribute('open') !== null, 'Source evidence opens at the requested location');
    await page.goto(`${base}/change.php?id=1&type=file&target=does-not-exist.ts`);
    check((await page.locator('.impact-intro').innerText()).includes('No concrete target matched'), 'Unmatched change does not imply safety');
    check((await page.locator('.impact-intro').innerText()).includes('does not mean'), 'Unmatched change explains uncertainty');
    check(errors.length === 0, `No browser JavaScript errors: ${errors.join('; ')}`);
    return { checks: checks.length, failures, results, errors };
}
