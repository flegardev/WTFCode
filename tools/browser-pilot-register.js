async (page) => {
  if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(page.url()).hostname)) throw new Error("Pilot replay requires a disposable loopback application.");
  const base = new URL(page.url()).origin;
  const checks = [];
  await page.goto(`${base}/register.php`);
  await page.getByRole('textbox', { name: 'Name', exact: true }).fill('Pilot disposable One');
  await page.getByRole('textbox', { name: 'Email', exact: true }).fill('pilot-one@wtfcode.local');
  await page.getByRole('textbox', { name: 'Password', exact: true }).fill('Disposable-pilot-password-48271');
  await page.getByRole('textbox', { name: 'Confirm password' }).fill('Disposable-pilot-password-48271');
  await page.getByRole('button', { name: 'Create workspace' }).click();
  if (!page.url().includes('account-request.php')) throw new Error('Registration did not require verification');
  checks.push('Allowed registration queues verification without auto-login');
  await page.goto(`${base}/login.php`);
  await page.getByRole('textbox', { name: 'Email', exact: true }).fill('pilot-one@wtfcode.local');
  await page.getByRole('textbox', { name: 'Password', exact: true }).fill('Disposable-pilot-password-48271');
  await page.getByRole('button', { name: 'Log in', exact: true }).click();
  if (!page.url().includes('account-access.php')) throw new Error('Unverified account accessed workspace');
  await page.screenshot({path:'docs/pilot-screenshots/01-verification-required.png',fullPage:true});
  checks.push('Unverified login is gated; account privacy remains accessible');
  await page.goto(`${base}/account.php`);
  if (!await page.getByRole('heading',{name:'Account and data',exact:true}).isVisible()) throw new Error('Unverified account cannot reach privacy controls');
  return {status:'passed',checks};
}