import {chromium,expect} from '@playwright/test';
import {readFile,writeFile} from 'node:fs/promises';
import path from 'node:path';
const evidence=path.resolve(process.env.POLICY_LIBRARY_BROWSER_FIXTURE || 'var/reader-fixture');
const native=JSON.parse(await readFile(path.join(evidence,'native-baseline.json'),'utf8'));
const flatten=nodes=>nodes.flatMap(node=>[...(node.revision?[node]:[]),...flatten(node.children||[])]);
const sog=flatten(native.manuals.find(m=>m.slug==='sogs').tree.nodes)[0];
const med=JSON.parse(await readFile(path.join(evidence,'medical-sample.json'),'utf8'));
const base=process.env.POLICY_LIBRARY_BROWSER_URL || 'http://127.0.0.1:8877';
const report=process.env.READER_QA_LABEL || 'browser-regression';
const browser=await chromium.launch({channel:'chrome',headless:true});
const checks=[];
const viewerErrors=[];
let debugPage;
const check=async(name,body)=>{await body();checks.push({name,status:'pass'});};
try{
 const context=await browser.newContext({viewport:{width:390,height:844}});
 const page=await context.newPage();
 debugPage=page;
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 page.on('request',request=>{if(request.url().endsWith('/api/viewer-errors'))viewerErrors.push(request.postDataJSON());});
 const rendered=async()=>{await page.locator('#pdf-page:not([hidden])').waitFor();await page.locator('#pdf-text span').first().waitFor();};
 await page.goto(`${base}/?manual=sogs&node=${sog.slug}&page=1`);await rendered();
 await check('phone default read size uses actual source body glyphs with contained scrolling',async()=>{
  await expect(page.locator('#read-size')).toHaveAttribute('aria-pressed','true');
  await expect.poll(()=>page.locator('#pdf-page').getAttribute('data-body-text-px').then(Number)).toBeGreaterThanOrEqual(18);
  await expect(page.locator('#pdf-page canvas')).toHaveCount(1);
  await expect(page.locator('canvas:visible')).toHaveCount(1);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth||document.documentElement.scrollHeight>innerHeight)).toBe(false);
 });
 await check('phone pan and visible zoom controls enlarge actual PDF content without browser zoom',async()=>{
  await page.locator('#pan-right').click();expect(await page.locator('#page-stage').evaluate(el=>el.scrollLeft)).toBeGreaterThan(100);
  await page.locator('#pan-left').click();expect(await page.locator('#page-stage').evaluate(el=>el.scrollLeft)).toBeLessThanOrEqual(2);
  await page.locator('#read-zoom-in').click();await expect.poll(()=>page.locator('#pdf-page').getAttribute('data-body-text-px').then(Number)).toBeGreaterThanOrEqual(22.5);
  await page.locator('#read-zoom-out').click();
 });
 await check('selected SOG thumbnails contain only explicitly owned pages and preserve selection identity',async()=>{
  const entry=sog.revision.metadata.primary_entries.find(entry=>entry.id==='100.01');
  await page.goto(`${base}/?manual=sogs&node=${entry.slug}&page=${entry.physical_page}`);await rendered();
  await page.locator('#pages-toggle').click();await expect(page.locator('#page-sidebar')).toBeInViewport();
  expect(await page.locator('.thumbnail').evaluateAll(items=>items.map(el=>Number(el.dataset.page)))).toEqual(entry.semantic_pages);
  await page.locator('.thumbnail canvas').first().waitFor();
  await page.locator(`.thumbnail[data-page="${entry.semantic_pages.at(-1)}"]`).click();await expect(page).toHaveURL(new RegExp(`node=${entry.slug}&page=${entry.semantic_pages.at(-1)}`));await rendered();
  await page.locator('#menu-toggle').click();await expect(page.locator('.tree-pages')).toHaveCount(0);await expect(page.locator('.tree-primary').first()).toContainText('Mission');await page.keyboard.press('Escape');
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
 await check('desktop contents and pages collapse independently and restore exact titles',async()=>{
  const desktop=await browser.newContext({viewport:{width:1366,height:768}});const reader=await desktop.newPage();
  await reader.goto(`${base}/?manual=sogs&node=${sog.slug}&page=1`);await reader.locator('#pdf-text span').first().waitFor();
  await expect(reader.locator('#manual-sidebar')).toBeInViewport();await expect(reader.locator('#page-sidebar')).toBeInViewport();
  const widths=[];const paperWidth=()=>reader.locator('#pdf-page').boundingBox().then(rect=>rect.width);
  widths.push(await paperWidth());await reader.locator('#drawer-close').click();await expect(reader.locator('#menu-toggle')).toHaveAttribute('aria-expanded','false');await expect(reader.locator('#page-sidebar')).toBeInViewport();
  await expect.poll(paperWidth).toBeGreaterThan(widths[0]+200);widths.push(await paperWidth());
  await reader.locator('#pages-close').click();await expect(reader.locator('#pages-toggle')).toHaveAttribute('aria-expanded','false');await expect.poll(paperWidth).toBeGreaterThan(widths[1]+100);
  await reader.locator('#menu-toggle').click();await expect(reader.locator('#manual-sidebar')).toBeInViewport();await expect(reader.locator('#page-sidebar')).toBeHidden();
  await reader.locator('#pages-toggle').click();await expect(reader.locator('#page-sidebar')).toBeInViewport();await reader.locator('.thumbnail canvas').first().waitFor();
  expect(await reader.locator('.thumbnail canvas').count()).toBeLessThanOrEqual(6);await expect(reader.locator('.tree-pages')).toHaveCount(0);
  await reader.locator('#focus-mode').click();await reader.locator('#focus-pages').click();await expect(reader.locator('#page-sidebar')).toBeInViewport();await reader.keyboard.press('Escape');await expect(reader.locator('#focus-pages')).toBeFocused();
  await desktop.close();
 });
 await context.close();
 await writeFile(path.join(evidence,`${report}.json`),JSON.stringify({capturedUtc:new Date().toISOString(),scope:'Loopback exact production catalog/native PDF/source candidate; authentication/admin mutations are excluded',checks,unexpectedPageErrors:errors},null,2));console.log(JSON.stringify({passed:checks.length,pageErrors:errors}));
}catch(error){await debugPage?.screenshot({path:path.join(evidence,`${report}-failure.png`)});await writeFile(path.join(evidence,`${report}-failure.json`),JSON.stringify({checks,viewerErrors,error:error.message,message:await debugPage?.locator('#viewer-message').innerText()},null,2));throw error;}finally{await browser.close();}
