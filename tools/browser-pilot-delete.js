async (page) => {
  if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(page.url()).hostname)) throw new Error("Pilot replay requires a disposable loopback application.");
 const base=new URL(page.url()).origin;const checks=[];
 await page.goto(`${base}/login.php`);await page.getByLabel('Email',{exact:true}).fill('pilot-one@wtfcode.local');await page.getByLabel('Password',{exact:true}).fill('Changed-pilot-password-48271');
 await page.getByRole('button',{name:'Log in',exact:true}).click();await page.waitForURL('**/dashboard.php');
 const href=await page.locator('.repository-card a[href*="project.php?id="]').first().getAttribute('href');const id=new URL(href).searchParams.get('id');
 await page.goto(`${base}/project.php?id=${id}`);
 checks.push('Account and analysis survive actual database and web service restarts');
 const csrf=await page.locator('input[name="csrf_token"]').first().inputValue();
 for(let i=0;i<3;i++)await page.request.post(`${base}/project.php?id=${id}`,{form:{csrf_token:csrf,profile:'quick'}});
 const status=await(await page.request.get(`${base}/scan-status.php?id=${id}`)).json();if(status.job?.state!=='queued')throw new Error('Expected durable pending job before deletion');
 checks.push('Repeated rescans produce durable pending work while worker is stopped');
 await page.goto(`${base}/account.php`);
 await page.getByLabel('Current password',{exact:true}).fill('Incorrect-password-48271');await page.getByLabel('Type DELETE to confirm').fill('DELETE');
 await page.getByRole('button',{name:'Permanently delete my account'}).click();await page.getByRole('alert').filter({hasText:'Deletion was not confirmed'}).waitFor();
 checks.push('Deletion refuses an incorrect password');
 await page.getByLabel('Current password',{exact:true}).fill('Changed-pilot-password-48271');await page.getByLabel('Type DELETE to confirm').fill('DELETE');
 await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:'docs/pilot-screenshots/08-deletion-confirmation.png',fullPage:true});
 await page.getByRole('button',{name:'Permanently delete my account'}).click();await page.waitForURL('**/login.php?account_deleted=1');
 checks.push('Reauthenticated deletion completes with pending work');
 await page.goto(`${base}/project.php?id=${id}`);if(!page.url().includes('login.php'))throw new Error('Deleted session retained project access');
 await page.getByLabel('Email',{exact:true}).fill('pilot-one@wtfcode.local');await page.getByLabel('Password',{exact:true}).fill('Changed-pilot-password-48271');
 await page.getByRole('button',{name:'Log in',exact:true}).click();await page.locator('.inline-error').waitFor();checks.push('Deleted account cannot sign in or access old project');
 return {status:'passed',deletedProjectId:id,pendingJobId:status.job.id,checks};
}