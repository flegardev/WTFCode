async (page) => {
  if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(page.url()).hostname)) throw new Error("Pilot replay requires a disposable loopback application.");
  const base=new URL(page.url()).origin; const id='15'; const checks=[];
  await page.getByRole('button',{name:'Verify email',exact:true}).click();await page.waitForURL('**/login.php?account_updated=1');
  await page.goto(`${base}/account-token.php?purpose=verify#token=${'a'.repeat(64)}`);
  await page.waitForFunction(()=>location.hash==='');
  await page.goto(`${base}/account-token.php?purpose=verify#token=${'b'.repeat(64)}`);
  await page.waitForFunction(()=>location.hash==='' && document.querySelector('#account-token').value==='b'.repeat(64));
  checks.push('New same-page fragment link replaces stale code and clears fragment');
  await page.goto(`${base}/login.php`);
  await page.getByLabel('Email',{exact:true}).fill('pilot-two@wtfcode.local');await page.getByLabel('Password',{exact:true}).fill('Disposable-pilot-password-48271');
  await page.getByRole('button',{name:'Log in',exact:true}).click();await page.waitForURL('**/dashboard.php');
  for(const endpoint of ['project.php','map.php','files.php','change.php','ask.php','scan-status.php']){
    const r=await page.request.get(`${base}/${endpoint}?id=${id}`);if(r.status()!==404)throw new Error(`Cross-owner ${endpoint} exposed`);checks.push(`Cross-owner ${endpoint} blocked`);
  }
  await page.goto(`${base}/account.php`);const csrf=await page.locator('input[name="csrf_token"]').first().inputValue();
  const own=await (await page.request.post(`${base}/account.php?user_id=3`,{form:{action:'export',csrf_token:csrf,user_id:'3'}})).json();
  if(own.account.email!=='pilot-two@wtfcode.local'||own.projects.length!==0)throw new Error('Export accepts another owner ID');checks.push('Forged export user ID ignored');
  await page.getByLabel('Current password',{exact:true}).fill('Disposable-pilot-password-48271');await page.getByLabel('Type DELETE to confirm').fill('DELETE');
  await page.getByRole('button',{name:'Permanently delete my account'}).click();await page.waitForURL('**/login.php?account_deleted=1');checks.push('Second test account deleted through reauthenticated form');
  await page.getByLabel('Email',{exact:true}).fill('pilot-one@wtfcode.local');await page.getByLabel('Password',{exact:true}).fill('Changed-pilot-password-48271');
  await page.getByRole('button',{name:'Log in',exact:true}).click();await page.waitForURL('**/dashboard.php');
  const r=await page.goto(`${base}/project.php?id=${id}`);if(r.status()!==200)throw new Error('Deleting other account affected owner project');checks.push('Deleting another account leaves original owner intact');
  return {status:'passed',checks};
}