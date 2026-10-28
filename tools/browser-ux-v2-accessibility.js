async (page) => {
 const base = new URL(page.url()).origin;
 const axePath = 'output/axe/package/axe.min.js';
 const results=[];
 const inspect = async (target, name, theme) => {
  await target.route('**/__ux-audit-axe.js', route => route.fulfill({path:axePath,contentType:'application/javascript'}));
  await target.addScriptTag({url:`${base}/__ux-audit-axe.js`});
  await target.unroute('**/__ux-audit-axe.js');
  const result=await target.evaluate(async()=>await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}}));
  results.push({name,theme,violations:result.violations.map(v=>({id:v.id,impact:v.impact,help:v.help,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary})).slice(0,10)})),incomplete:result.incomplete.length});
 };
 for(const theme of ['dark','light']) {
  await page.evaluate(theme=>localStorage.setItem('wtfcode-theme',theme),theme);
  await page.setViewportSize({width:1440,height:1000});
  for(const path of ['dashboard.php','project-create.php','project.php?id=1','map.php?id=1','change.php?id=1','change.php?id=1&type=file&target=src/app/routes/auth/auth.ts','file.php?project=1&id=25','pricing.php','settings.php','example.php']) {
   await page.goto(`${base}/${path}`); await inspect(page,path,theme);
  }
  for (const width of [320, 1440]) {
   await page.setViewportSize({width,height:1000});
   for (const path of ['project.php?id=1','map.php?id=1','change.php?id=1&type=file&target=src/app/routes/auth/auth.ts','file.php?project=1&id=25']) {
    await page.goto(`${base}/${path}`);await page.evaluate(()=>document.querySelectorAll('details').forEach(e=>e.open=true));
    await inspect(page,`Expanded ${width}px ${path}`,theme);
   }
  }
  for(const width of [1440,375]) {
   await page.setViewportSize({width,height:1000});await page.goto(`${base}/project.php?id=1`);
   await page.locator('.project-nav-ask').click();await page.locator('[data-ask-question]').fill('Where does authentication happen?');
   await page.locator('[data-ask-submit]').click();await page.locator('[data-ask-result]').waitFor({state:'visible'});
   await inspect(page,`Ask drawer ${width}px`,theme);await page.keyboard.press('Escape');
  }
  const context = await page.context().browser().newContext({viewport:{width:1440,height:1000}});
  const anonymous=await context.newPage(); await anonymous.goto(`${base}/index.php`);await anonymous.evaluate(theme=>localStorage.setItem('wtfcode-theme',theme),theme);await anonymous.reload();await inspect(anonymous,'Landing',theme);await context.close();
 }
 return {pages:results.length,violations:results.reduce((n,r)=>n+r.violations.length,0),results};
}
