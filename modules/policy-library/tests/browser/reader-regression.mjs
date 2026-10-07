import {chromium,expect} from '@playwright/test';
import {readFile,writeFile} from 'node:fs/promises';
import path from 'node:path';
const evidence=path.resolve(process.env.POLICY_LIBRARY_BROWSER_FIXTURE || 'var/reader-fixture');
const native=JSON.parse(await readFile(path.join(evidence,'native-baseline.json'),'utf8'));
const flatten=nodes=>nodes.flatMap(node=>[...(node.revision?[node]:[]),...flatten(node.children||[])]);
const sog=flatten(native.manuals.find(m=>m.slug==='sogs').tree.nodes)[0];
const med=JSON.parse(await readFile(path.join(evidence,'medical-sample.json'),'utf8'));
const base=process.env.POLICY_LIBRARY_BROWSER_URL || 'http://127.0.0.1:8877';
const browser=await chromium.launch({channel:'chrome',headless:true});
const checks=[];
let debugPage;
const check=async(name,body)=>{await body();checks.push({name,status:'pass'});};
try{
 const context=await browser.newContext({viewport:{width:390,height:844}});
 const page=await context.newPage();
 debugPage=page;
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const rendered=async()=>{await page.locator('#pdf-page:not([hidden])').waitFor();await page.locator('#pdf-text span').first().waitFor();};
 await page.goto(`${base}/?manual=sogs&node=${sog.slug}&page=1`);await rendered();
 await check('phone default fit width with contained scrolling and one canvas',async()=>{
  await expect(page.locator('#fit-width')).toHaveAttribute('aria-pressed','true');
  await expect(page.locator('#pdf-page canvas')).toHaveCount(1);
  await expect(page.locator('canvas:visible')).toHaveCount(1);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth||document.documentElement.scrollHeight>innerHeight)).toBe(false);
 });
 await check('View controls remain within phone viewport and escape restores focus',async()=>{
  await page.locator('#reader-options summary').click();
  const rect=await page.locator('#reader-options .reader-menu-panel').boundingBox();expect(rect.x).toBeGreaterThanOrEqual(0);expect(rect.x+rect.width).toBeLessThanOrEqual(390);
  await page.keyboard.press('Escape');await expect(page.locator('#reader-options')).not.toHaveAttribute('open');await expect(page.locator('#reader-options summary')).toBeFocused();
 });
 await check('fit page, actual size and persisted zoom preference',async()=>{
  await page.locator('#reader-options summary').click();await page.locator('#fit-page').click();await expect(page.locator('#fit-page')).toHaveAttribute('aria-pressed','true');
  await page.locator('#actual-size').click();await expect(page.locator('#actual-size')).toHaveAttribute('aria-pressed','true');await page.locator('#zoom-in').click();await expect(page.locator('#zoom-value')).toHaveText('125%');
  await page.reload();await rendered();await expect(page.locator('#actual-size')).toHaveAttribute('aria-pressed','true');await expect(page.locator('#zoom-value')).toHaveText('125%');
  await page.locator('#reader-options summary').click();await page.locator('#fit-width').click();await page.locator('#reader-options summary').click();
 });
 await check('page jump, next/previous and browser history preserve exact page',async()=>{
  await page.locator('#reader-options summary').click();await page.locator('#page-number').fill('3');await page.locator('#page-jump button').click();await expect(page).toHaveURL(/page=3/);await rendered();
  await page.locator('[data-nav="next"]').click();await expect(page).toHaveURL(/page=4/);await rendered();await page.goBack();await expect(page).toHaveURL(/page=3/);await rendered();
 });
 await check('focus mode keeps navigation and search reachable',async()=>{
  await page.locator('#focus-mode').click();await page.locator('#focus-navigation').click();await expect(page.locator('#manual-sidebar')).toBeInViewport();
  await page.locator('#title-search').fill('Mission');await expect(page.locator('.tree-document').first()).toBeVisible();await page.keyboard.press('Escape');await expect(page.locator('#focus-navigation')).toHaveAttribute('aria-expanded','false');
  await page.locator('#focus-mode').click();
 });
 await check('download uses existing protected route; print opens native original PDF',async()=>{
  await page.locator('#document-actions summary').click();await expect(page.locator('#download-pdf')).toHaveAttribute('href',sog.revision.download_url);
  const [popup]=await Promise.all([page.waitForEvent('popup'),page.locator('#print-pdf').click()]);await popup.waitForURL(/\/assets\//);expect(popup.url()).toContain(sog.revision.id);await popup.close();await page.locator('#document-actions summary').click();
 });
 await check('manual switch and title filter retain real medical protocol rendering',async()=>{
  await page.goto(`${base}/?manual=medical-protocols&node=${med.slug}&page=1`);await rendered();await expect(page.locator('#document-title')).toHaveText(med.title);await expect(page.locator('#manage-link')).toBeHidden();
  await page.locator('#menu-toggle').click();await page.locator('#title-search').fill('POCUS');await expect(page.locator('.tree-document')).toHaveCount(1);await page.locator('.tree-document').click();await rendered();await expect(page.locator('#document-title')).toContainText('POCUS');
 });
 await check('rapid switching cancels stale document renders',async()=>{
  await page.goto(`${base}/?manual=sogs&node=${sog.slug}&page=1`);await rendered();
  const second=flatten(native.manuals.find(m=>m.slug==='sogs').tree.nodes).find(n=>n.title.includes('Section 300'));
  await page.evaluate(({first,second})=>{history.pushState({},'',`/?manual=sogs&node=${second}&page=1`);dispatchEvent(new PopStateEvent('popstate'));history.pushState({},'',`/?manual=sogs&node=${first}&page=2`);dispatchEvent(new PopStateEvent('popstate'));},{first:sog.slug,second:second.slug});
  await expect(page.locator('#document-title')).toHaveText(sog.title);await expect(page).toHaveURL(/page=2/);await rendered();
 });
 await check('landscape focus retains useful page space without application overflow',async()=>{
  await page.setViewportSize({width:844,height:390});await page.locator('#focus-mode').click();await page.waitForTimeout(200);expect((await page.locator('#page-stage').boundingBox()).height).toBeGreaterThan(280);expect(await page.evaluate(()=>document.documentElement.scrollHeight>innerHeight)).toBe(false);
 });
 await check('viewer failure gives retry and original PDF recovery',async()=>{
  const pdfPattern=base+'/assets/*';
  await page.route(pdfPattern,route=>route.fulfill({status:500,body:'test-only failure'}));await page.reload();await expect(page.locator('#viewer-message')).toContainText('This document could not be displayed');await expect(page.getByRole('button',{name:'Retry',exact:true})).toBeVisible();await expect(page.getByRole('link',{name:'Open original PDF'})).toBeVisible();await page.unroute(pdfPattern);await page.getByRole('button',{name:'Retry',exact:true}).click();await rendered();
 });
 await check('PDF engine network failure recovers through saved-place reload',async()=>{
  const enginePattern='**/vendor/policy-library/assets/pdf-*.js';
  await page.route(enginePattern,route=>route.fulfill({status:500,body:'test-only engine failure'}));await page.reload();await expect(page.locator('#viewer-message')).toContainText('This document could not be displayed');await page.unroute(enginePattern);await page.getByRole('button',{name:'Retry',exact:true}).click();await rendered();
 });
 await context.close();
 await writeFile(path.join(evidence,'browser-regression.json'),JSON.stringify({capturedUtc:new Date().toISOString(),scope:'Loopback exact production catalog/native PDF/source candidate; authentication/admin mutations are excluded',checks,unexpectedPageErrors:errors},null,2));console.log(JSON.stringify({passed:checks.length,pageErrors:errors}));
}catch(error){await debugPage?.screenshot({path:path.join(evidence,'browser-regression-failure.png')});await writeFile(path.join(evidence,'browser-regression-failure.json'),JSON.stringify({checks,error:error.message,message:await debugPage?.locator('#viewer-message').innerText()},null,2));throw error;}finally{await browser.close();}
