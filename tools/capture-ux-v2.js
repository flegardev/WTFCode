// Captures real browser output. Use a freshly scanned public sample, signed in.
async (page) => {
 const base=new URL(page.url()).origin;
 const captured=[];
 const capture=async(target,name,fullPage=true)=>{if(fullPage)await target.evaluate(()=>window.scrollTo(0,0));await target.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await target.mouse.move(5,5);await target.screenshot({path:`docs/screenshots-v2/${name}.png`,fullPage,animations:'disabled'});captured.push(name);};
 const setTheme=async(target,theme)=>{if(await target.locator('html').getAttribute('data-theme')!==theme)await target.getByRole('button',{name:`Switch to ${theme} theme`}).click();};
 const anonContext=await page.context().browser().newContext({viewport:{width:1440,height:1000}});
 const emptyContext=await page.context().browser().newContext({viewport:{width:1440,height:1000},storageState:'output/ux-v2-empty-session.json'});
 try {
  const anonymous=await anonContext.newPage();await anonymous.goto(`${base}/index.php`);await capture(anonymous,'01-landing');
  await anonymous.getByRole('tab',{name:'Architecture',exact:true}).click();await anonymous.locator('[data-example]').screenshot({path:'docs/screenshots-v2/19-public-example.png'});captured.push('19-public-example');
  await anonymous.goto(`${base}/pricing.php`);await capture(anonymous,'12-pricing');
  const empty=await emptyContext.newPage();await empty.goto(`${base}/dashboard.php`);
  if(!await empty.getByRole('heading',{name:'Your first project starts here.'}).isVisible())throw new Error('Prepare an authenticated empty disposable account before capture');
  await capture(empty,'02-empty-dashboard');
  for(const path of ['file.php?project=1&id=25','symbol.php?project=1&id=1']){const response=await empty.request.get(`${base}/${path}`);if(response.status()!==404)throw new Error(`Cross-owner source access: ${path}`);}
  await empty.goto(`${base}/project-create.php`);await capture(empty,'04-repository-import');await empty.locator('[name=repository_url]').fill('https://github.com.example.com/owner/repo');await empty.getByRole('button',{name:'Analyze repository'}).click();if(!await empty.locator('.inline-error').isVisible())throw new Error('No URL validation error');await capture(empty,'18-import-error');
  await page.setViewportSize({width:1440,height:1000});await page.goto(`${base}/dashboard.php`);await setTheme(page,'dark');await capture(page,'03-dashboard');await page.getByRole('link',{name:'Explore project'}).hover();await page.screenshot({path:'docs/screenshots-v2/23-dashboard-hover.png',animations:'disabled'});captured.push('23-dashboard-hover');
  await page.goto(`${base}/project.php?id=1`);await capture(page,'05-project-overview');
  await page.goto(`${base}/map.php?id=1`);await page.locator('[data-system-key=database]').click();await capture(page,'06-architecture');
  await page.locator('[data-advanced-graph] > summary').click();await page.locator('#graph-level').selectOption('file');await page.locator('#graph-search').fill('auth');await capture(page,'07-dependency-graph');
  await page.goto(`${base}/project.php?id=1`);await page.locator('.project-nav-ask').click();await page.locator('[data-ask-question]').fill('Where does authentication happen?');await page.locator('[data-ask-submit]').click();await page.locator('[data-ask-result]').waitFor({state:'visible'});await capture(page,'08-ask-codebase',false);
  await page.locator('[data-ask-evidence] a').first().click();if(!await page.locator('#source-evidence').getAttribute('open')){const open=await page.locator('#source-evidence').evaluate(e=>e.open);if(!open)throw new Error('Source evidence did not open');}await capture(page,'17-source-evidence');
  await page.goto(`${base}/file.php?project=1&id=25`);await capture(page,'11-source-explanation');
  await page.goto(`${base}/change.php?id=1`);await capture(page,'09-change-form');await page.locator('[name=request]').fill('I want to change the login system.');await page.locator('[name=type]').selectOption('file');await page.locator('[name=target]').fill('src/app/routes/auth/auth.ts');await page.getByRole('button',{name:'Analyze impact'}).click();await page.locator('.impact-intro').waitFor({state:'visible'});await capture(page,'10-change-result');
  await page.goto(`${base}/project.php?id=1`);await setTheme(page,'light');await capture(page,'13-light-theme');await page.goto(`${base}/dashboard.php`);await capture(page,'22-light-dashboard');await setTheme(page,'dark');
  await page.setViewportSize({width:390,height:844});await page.goto(`${base}/dashboard.php`);await capture(page,'14-mobile-dashboard');
  await page.setViewportSize({width:390,height:844});await page.goto(`${base}/project.php?id=1`);await capture(page,'15-mobile-project');
  await page.goto(`${base}/change.php?id=1&type=file&target=src/app/routes/auth/auth.ts`);await capture(page,'16-mobile-change');
  await page.goto(`${base}/map.php?id=1`);await page.locator('[data-diagram-toggle]').click();await page.locator('[data-system-key=database]').click();await capture(page,'20-mobile-architecture');
  await page.goto(`${base}/project.php?id=1`);await page.locator('.project-nav-ask').click();await page.locator('[data-ask-question]').fill('Where does authentication happen?');await page.locator('[data-ask-submit]').click();await page.locator('[data-ask-result]').waitFor({state:'visible'});await capture(page,'21-mobile-ask',false);await page.keyboard.press('Escape');
 } finally {await anonContext.close();await emptyContext.close();}
 return {captured,fixture:'Real public GitHub repository, commit 30b68e1e881462b2f4164ea09ab4c4f5699c7b0b',ownershipChecks:2};
}
