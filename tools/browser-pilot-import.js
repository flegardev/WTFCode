async (page) => {
  if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(page.url()).hostname)) throw new Error("Pilot replay requires a disposable loopback application.");
  const base = new URL(page.url()).origin;
  const checks = [];
  if (page.url().includes('account-token.php')) {
  await page.waitForFunction(()=>location.hash==='');
  if (new URL(page.url()).hash !== '') throw new Error('Token fragment was not cleared');
  await page.screenshot({path:'docs/pilot-screenshots/02-confirm-email.png',fullPage:true});
  await page.getByRole('button',{name:'Verify email',exact:true}).click();
  if (!page.url().includes('login.php')) throw new Error('Verification did not complete');
  checks.push('Controlled verification link consumes via CSRF POST and clears token fragment');
  await page.getByRole('textbox',{name:'Email',exact:true}).fill('pilot-one@wtfcode.local');
  await page.getByRole('textbox',{name:'Password',exact:true}).fill('Disposable-pilot-password-48271');
  await page.getByRole('button',{name:'Log in',exact:true}).click();
  if (!page.url().includes('dashboard.php')) throw new Error('Verified account is not admitted');
  }
  if (await page.locator('main').getByRole('link',{name:'Add project',exact:true}).count() !== 0) throw new Error('Empty dashboard has duplicate primary CTA');
  await page.goto(`${base}/project-create.php`);
  await page.locator('input[name="repository_url"]').fill('https://example.com/unsafe');
  await page.getByRole('button',{name:'Analyze repository'}).click();
  await page.locator('.inline-error').waitFor({state:'visible'});
  checks.push('Invalid non-GitHub repository rejected with visible error');
  await page.locator('input[name="repository_url"]').fill('https://github.com/gothinkster/node-express-realworld-example-app');
  await page.getByRole('button',{name:'Analyze repository'}).click();
  await page.waitForURL('**/project.php?id=*');
  if (!page.url().includes('project.php?id=')) throw new Error('Repository did not create project');
  checks.push('Real public repository accepted as durable queued work');
  return {status:'passed',projectId:new URL(page.url()).searchParams.get('id'),checks};
}