async (page) => {
 const base=new URL(page.url()).origin;
 const checks=[];const failures=[];const metrics=[];
 const check=(ok,name)=>{if(!ok)failures.push(name);else checks.push(name);};
 const anonymousContext=await page.context().browser().newContext({viewport:{width:1440,height:1000}});
 try {
  const anonymous=await anonymousContext.newPage();
  for(const theme of ['dark','light']) {
   await anonymous.goto(`${base}/index.php`);await anonymous.evaluate(theme=>localStorage.setItem('wtfcode-theme',theme),theme);
   for(const width of [320,375,390,430,768,1024,1440]) {
    await anonymous.setViewportSize({width,height:1000});
    for(const path of ['index.php','pricing.php','register.php']) {
     const response=await anonymous.goto(`${base}/${path}`);check(response.status()===200,`${theme} ${width} public ${path} loads`);
     check(await anonymous.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${theme} ${width} public ${path} fits`);
     if(path==='index.php') {
      check((await anonymous.locator('h1').innerText()).replace(/\s+/g,' ')==='Understand what your AI built.',`${theme} ${width} exact hero headline`);
      for(const tab of ['Architecture','Dependencies','Ask a question','Change impact','Overview']) {
       await anonymous.getByRole('tab',{name:tab,exact:true}).click();
       check(await anonymous.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${theme} ${width} preview ${tab} fits`);
      }
      metrics.push(await anonymous.evaluate(()=>({theme:document.documentElement.dataset.theme,width:innerWidth,navigationMs:performance.getEntriesByType('navigation')[0].duration,decodedResourceBytes:performance.getEntriesByType('resource').reduce((n,r)=>n+(r.decodedBodySize||0),0)})));
     }
    }
   }
  }
  await anonymous.setViewportSize({width:1440,height:1000});await anonymous.goto(`${base}/index.php`);
  await anonymous.getByRole('tab',{name:'Overview',exact:true}).focus();await anonymous.keyboard.press('ArrowRight');
  check(await anonymous.getByRole('tab',{name:'Architecture',exact:true}).getAttribute('aria-selected')==='true','Preview arrow-key navigation');
 } finally {await anonymousContext.close();}
 for(const theme of ['dark','light']) {
  await page.evaluate(theme=>localStorage.setItem('wtfcode-theme',theme),theme);
  for(const width of [320,1440]) {
   await page.setViewportSize({width,height:1000});
   for(const path of ['project.php?id=1','map.php?id=1','change.php?id=1&type=file&target=src/app/routes/auth/auth.ts','file.php?project=1&id=25']) {
    await page.goto(`${base}/${path}`);
    await page.evaluate(()=>document.querySelectorAll('details').forEach(e=>e.open=true));
    if(width===320 && await page.locator('[data-diagram-toggle]').count()) {
     await page.locator('[data-diagram-toggle]').click();
     const separate=await page.locator('#overview-architecture-graph').evaluate(e=>{const nodes=e._cyreg.cy.nodes().map(n=>n.renderedBoundingBox());return nodes.every((a,i)=>nodes.every((b,j)=>i===j||a.x2<=b.x1||b.x2<=a.x1||a.y2<=b.y1||b.y2<=a.y1));});
     check(separate,`${theme} mobile diagram nodes do not overlap after opening`);
    }
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${theme} ${width} expanded ${path} fits`);
   }
  }
 }
 await page.setViewportSize({width:1440,height:1000});await page.goto(`${base}/dashboard.php`);
 const explore=page.getByRole('link',{name:'Explore project'});
 const contrast=async()=>await explore.evaluate(e=>{
  const c=getComputedStyle(e),parse=s=>(s.match(/[\d.]+/g)||[]).slice(0,3).map(Number),lum=a=>a.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((n,v,i)=>n+v*[.2126,.7152,.0722][i],0),fg=lum(parse(c.color)),bg=lum(parse(c.backgroundColor));return (Math.max(fg,bg)+.05)/(Math.min(fg,bg)+.05);
 });
 check(await contrast()>=4.5,'Explore project contrast at rest');await explore.hover();check(await contrast()>=4.5,'Explore project hover contrast');await explore.focus();check(await contrast()>=4.5,'Explore project focus contrast');
 for(const theme of ['dark','light']) {if(await page.locator('html').getAttribute('data-theme')!==theme)await page.getByRole('button',{name:`Switch to ${theme} theme`}).click();check(await contrast()>=4.5,`Explore project contrast immediately after switching to ${theme}`);}
 await explore.click();await page.locator('[data-system-key]').first().click();check((await page.locator('#overview-architecture-detail').innerText()).includes('Open system evidence'),'Keyboard-readable system selection shows evidence');
 const csrf=await page.locator('[name=csrf_token]').first().inputValue();
 const bad=await page.request.post(`${base}/ask.php?id=1`,{headers:{Accept:'application/json'},form:{csrf_token:'invalid',question:'authentication'}});check(bad.status()===419,'JSON CSRF validation');
 const empty=await page.request.post(`${base}/ask.php?id=1`,{headers:{Accept:'application/json'},form:{csrf_token:csrf,question:''}});check(empty.status()===422,'Empty JSON question rejected');
 await page.locator('.project-nav-ask').click();await page.locator('[data-ask-question]').fill('Where does authentication happen?');
 await page.route('**/ask.php?id=1',route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({error:'A controlled test network failure. Try again.'})}));
 await page.locator('[data-ask-submit]').click();await page.locator('[data-ask-retry]').waitFor({state:'visible'});check((await page.locator('[data-ask-status]').innerText()).includes('controlled test'),'Retryable error shown');
 await page.unroute('**/ask.php?id=1');await page.locator('[data-ask-retry]').click();await page.locator('[data-ask-result]').waitFor({state:'visible'});check((await page.locator('[data-ask-answer]').innerText()).includes('request handlers'),'Retry returns a real deterministic answer');
 await page.locator('[data-copy-answer]').click();await page.waitForFunction(()=>/copied|selected/.test(document.querySelector('[data-ask-status]').textContent));check(/copied|selected/.test(await page.locator('[data-ask-status]').innerText()),'Copy answer has accessible success or selection fallback');
 for(let i=0;i<12;i++){await page.keyboard.press('Tab');check(await page.evaluate(()=>document.querySelector('[data-ask-dialog]').contains(document.activeElement)),'Native dialog keeps keyboard focus');}
 await page.keyboard.press('Escape');await page.emulateMedia({reducedMotion:'reduce'});check(await page.evaluate(()=>getComputedStyle(document.querySelector('.button')).transitionDuration.split(',').every(x=>parseFloat(x)===0)),'Reduced motion disables transitions');await page.emulateMedia({reducedMotion:'no-preference'});
 await page.goto(`${base}/map.php?id=1`);await page.locator('[data-advanced-graph] > summary').click();await page.locator('#graph-level').selectOption('file');await page.locator('#graph-search').fill('auth');check(/nodes/.test(await page.locator('#graph-result-count').innerText()),'Advanced graph filtering returns actual node counts');check(await page.locator('#graph-viewport').evaluate(e=>{const cy=e._cyreg.cy;return parseFloat(cy.nodes().not('.filtered').first().renderedStyle('font-size'))>=10;}),'Filtered file graph keeps labels at a readable rendered size');await page.locator('#graph-reset').click();await page.locator('#graph-fit').click();
 return {checks:checks.length,failures,metrics};
}
