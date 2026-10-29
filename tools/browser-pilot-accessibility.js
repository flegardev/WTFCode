async (page) => {
 const base=new URL(page.url()).origin;const results=[];const errors=[];page.on('pageerror',error=>errors.push(error.message));
 for(const theme of ['dark','light']){
  await page.evaluate(theme=>localStorage.setItem('wtfcode-theme',theme),theme);
  for(const width of [1440,320]){
   await page.setViewportSize({width,height:1000});
   for(const path of ['account.php','feedback.php','account-request.php?purpose=reset','account-token.php?purpose=reset']){
    await page.goto(`${base}/${path}`);
    await page.route('**/__pilot-axe.js',route=>route.fulfill({path:'output/axe.min.js',contentType:'application/javascript'}));
    await page.addScriptTag({url:`${base}/__pilot-axe.js`});await page.unroute('**/__pilot-axe.js');
    const audit=await page.evaluate(async()=>await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}}));
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);
    results.push({path,theme,width,overflow,violations:audit.violations.map(v=>({id:v.id,impact:v.impact,targets:v.nodes.map(n=>n.target)})),manualReviewItems:audit.incomplete.length});
    if(width===320&&theme==='dark'&&path==='account.php')await page.screenshot({path:'docs/pilot-screenshots/07-account-mobile.png',fullPage:true});
   }
  }
 }
 return {status:results.some(r=>r.overflow||r.violations.length)||errors.length?'failed':'passed',results,errors};
}