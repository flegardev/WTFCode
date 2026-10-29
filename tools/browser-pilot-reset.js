async (page) => {
  if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(page.url()).hostname)) throw new Error("Pilot replay requires a disposable loopback application.");
  const base=new URL(page.url()).origin; const checks=[];
  if(!page.url().includes('account-token.php?purpose=reset'))throw new Error('Open controlled recovery link');
  const stale=await page.context().browser().newContext({storageState:await page.context().storageState()});
  try {
    await page.waitForFunction(()=>location.hash==='');
    if(new URL(page.url()).hash!=='')throw new Error('Recovery token remained in fragment');
    await page.screenshot({path:'docs/pilot-screenshots/06-password-recovery.png',fullPage:true});
    await page.getByLabel('New password',{exact:true}).fill('Changed-pilot-password-48271');
    await page.getByLabel('Confirm password',{exact:true}).fill('Changed-pilot-password-48271');
    await page.getByRole('button',{name:'Reset password',exact:true}).click();await page.waitForURL('**/login.php?account_updated=1');
    const expired=await stale.request.get(`${base}/dashboard.php`);
    if(!expired.url().includes('login.php'))throw new Error('Old authenticated session survived reset');
    checks.push('Reset signs out existing authenticated sessions');
  } finally {await stale.close();}
  await page.getByLabel('Email',{exact:true}).fill('pilot-one@wtfcode.local');await page.getByLabel('Password',{exact:true}).fill('Changed-pilot-password-48271');
  await page.getByRole('button',{name:'Log in',exact:true}).click();await page.waitForURL('**/dashboard.php');checks.push('New password signs in');
  await page.goto(`${base}/account.php`);
  const [download]=await Promise.all([page.waitForEvent('download'),page.getByRole('button',{name:'Download account data'}).click()]);
  await download.saveAs('output/pilot-account-export.json');
  const csrf=await page.locator('input[name="csrf_token"]').first().inputValue();
  const data=await (await page.request.post(`${base}/account.php`,{form:{action:'export',csrf_token:csrf}})).json();
  if(data.account.email!=='pilot-one@wtfcode.local'||data.projects.length!==1||JSON.stringify(data).includes('password_hash'))throw new Error('Export scope wrong');
  checks.push('Real download and authenticated metadata export exclude credentials');
  await page.getByRole('button',{name:'Log out',exact:true}).click();await page.waitForURL('**/login.php');
  await page.goto(`${base}/register.php`);
  await page.getByLabel('Name',{exact:true}).fill('Pilot disposable Two');await page.getByLabel('Email',{exact:true}).fill('pilot-two@wtfcode.local');
  await page.getByLabel('Password',{exact:true}).fill('Disposable-pilot-password-48271');await page.getByLabel('Confirm password',{exact:true}).fill('Disposable-pilot-password-48271');
  await page.getByRole('button',{name:'Create workspace'}).click();await page.waitForURL('**/account-request.php?purpose=verify');
  checks.push('Second allowed account registers with verification required');
  return {status:'passed',checks};
}