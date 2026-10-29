async (page) => {
  const base = new URL(page.url()).origin;
  const id = new URL(page.url()).searchParams.get('id');
  const checks=[]; const check=(ok,name)=>{if(!ok)throw new Error(name);checks.push(name);};
  await page.reload();
  const status=await (await page.request.get(`${base}/scan-status.php?id=${id}`)).json();
  check(['completed','partial'].includes(status.job?.state || status.state) || status.project?.status==='ready', 'Real worker completion received through status API');
  const csrf=await page.locator('input[name="csrf_token"]').first().inputValue();
  const rejected=await page.request.post(`${base}/ask.php?id=${id}`,{headers:{Accept:'application/json'},form:{csrf_token:'invalid',question:'authentication'}});
  check(rejected.status()===419,'CSRF rejected');
  const response=await page.request.post(`${base}/ask.php?id=${id}`,{headers:{Accept:'application/json'},form:{csrf_token:csrf,question:'Where does authentication happen?'}});
  const answer=await response.json();
  check(response.status()===200 && answer.provider==='deterministic' && answer.evidence.length>0,'Source-backed deterministic question answered');
  await page.locator('.project-nav-ask').click();
  await page.locator('[data-ask-question]').fill('Where does authentication happen?');
  await page.locator('[data-ask-submit]').click();
  await page.locator('[data-ask-result]').waitFor({state:'visible'});
  const evidenceLink=page.locator('[data-ask-result] a[href*="file.php"]').first();
  await evidenceLink.waitFor({state:'visible'}); await evidenceLink.click();
  await page.waitForURL('**/file.php?*');
  check((await page.locator('main').innerText()).includes('Source'),'Evidence link opens owned source explanation');
  await page.goto(`${base}/change.php?id=${id}&type=file&target=src/app/routes/auth/auth.ts`);
  check((await page.locator('main').innerText()).includes('not a prediction that they will break'),'Change analysis presents uncertainty');
  await page.goto(`${base}/map.php?id=${id}`);
  check(await page.locator('canvas').count()>0,'Dependency graph rendered');
  for(const width of [1440,390,320]){
    await page.setViewportSize({width,height:1000});
    for(const path of [`project.php?id=${id}`,`map.php?id=${id}`,'account.php','feedback.php','settings.php']){
      const r=await page.goto(`${base}/${path}`);check(r.status()===200,`${width}px ${path} loads`);
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${width}px ${path} fits viewport`);
    }
  }
  await page.setViewportSize({width:1440,height:1000});
  await page.goto(`${base}/account.php`);
  await page.screenshot({path:'docs/pilot-screenshots/03-account-controls.png',fullPage:true});
  await page.getByRole('button',{name:'Switch to light theme'}).click(); await page.reload();
  check(await page.locator('html').getAttribute('data-theme')==='light','Light theme persists');
  await page.screenshot({path:'docs/pilot-screenshots/04-account-light.png',fullPage:true});
  await page.getByRole('button',{name:'Switch to dark theme'}).click();
  await page.keyboard.press('Tab');
  check(await page.evaluate(()=>document.activeElement!==document.body),'Keyboard moves focus');
  await page.goto(`${base}/feedback.php`);
  await page.getByLabel('Category').selectOption('usability');await page.getByRole('combobox',{name:'Area',exact:true}).selectOption('questions');
  await page.getByRole('button',{name:'Send optional feedback'}).click();
  await page.getByRole('status').filter({hasText:'Feedback saved'}).waitFor();
  await page.screenshot({path:'docs/pilot-screenshots/05-optional-feedback.png',fullPage:true});
  checks.push('Optional structured feedback submitted without source or free text');
  await page.goto(`${base}/project-create.php`);
  await page.locator('input[name="repository_url"]').fill('https://github.com/octocat/Hello-World');await page.getByRole('button',{name:'Analyze repository'}).click();
  await page.locator('.inline-error').waitFor();
  check((await page.locator('.inline-error').innerText()).includes('project'),'Second public project blocked by trial entitlement');
  await page.goto(`${base}/account-request.php?purpose=reset`);
  await page.getByLabel('Email',{exact:true}).fill('pilot-one@wtfcode.local');await page.getByRole('button',{name:'Request recovery link'}).click();
  await page.getByRole('status').filter({hasText:'If this address'}).waitFor();
  checks.push('Recovery request returns generic response');
  return {status:'passed',projectId:id,evidence:answer.evidence.length,checks};
}