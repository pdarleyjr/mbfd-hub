import {chromium,expect} from '@playwright/test';
import {readFile,writeFile} from 'node:fs/promises';
import path from 'node:path';
const evidence=path.resolve(process.env.POLICY_LIBRARY_BROWSER_FIXTURE||'var/reader-fixture');
const config=JSON.parse(await readFile(process.env.POLICY_LIBRARY_READING_PREVIEW,'utf8'));
const artifact=JSON.parse(await readFile(config.artifactPath,'utf8'));
const base=process.env.POLICY_LIBRARY_BROWSER_URL||'http://127.0.0.1:8878';
const browser=await chromium.launch({channel:'chrome',headless:true});
const checks=[],errors=[];
try{
 const page=await browser.newPage({viewport:{width:390,height:844}});page.on('pageerror',error=>errors.push(error.message));
 await page.goto(`${base}/?manual=sogs&node=100-01&page=1`);await page.locator('#reading-page:not([hidden])').waitFor();
 expect(await page.locator('.reading-cover p').allTextContents()).toEqual(artifact.cover);await expect(page.locator('.reading-cover')).toContainText('not adopted');await expect(page.locator('.reading-cover')).toContainText('10/07/2026');checks.push('Exact C2 unnumbered cover, revision date and not-adopted status remain visible');
 const expected=artifact.entries[0].blocks.map(block=>block.text);
 expect(await page.locator('.reading-block,.reading-pdf-reference').allTextContents()).toEqual(expected);checks.push('All 22 actual C2 source paragraphs/headings match the hash-bound artifact exactly');
 const paragraph=page.locator('.reading-block').filter({hasText:'It is the policy of the Miami Beach Fire Department'}).first();
 await paragraph.scrollIntoViewIfNeeded();
 const geometry=await paragraph.evaluate(el=>{const rect=el.getBoundingClientRect();const stage=document.getElementById('page-stage');return {fontPx:parseFloat(getComputedStyle(el).fontSize),lineHeight:parseFloat(getComputedStyle(el).lineHeight),width:rect.width,lines:Math.round(rect.height/parseFloat(getComputedStyle(el).lineHeight)),stageWidth:stage.clientWidth,stageScrollWidth:stage.scrollWidth,applicationOverflow:document.documentElement.scrollWidth>innerWidth};});
 expect(geometry.fontPx).toBeGreaterThanOrEqual(18);expect(geometry.stageScrollWidth).toBeLessThanOrEqual(geometry.stageWidth);expect(geometry.applicationOverflow).toBe(false);checks.push('The actual policy paragraph wraps at 18px without horizontal pan or browser zoom');
 await page.screenshot({path:path.join(evidence,'reading-C2-phone-paragraph.png')});
 await page.locator('#read-zoom-in').click();expect(await paragraph.evaluate(el=>parseFloat(getComputedStyle(el).fontSize))).toBe(22.5);await page.locator('#read-zoom-out').click();checks.push('Visible text-size controls scale reflowed source paragraphs');
 await page.locator('.source-page-link').filter({hasText:'Original PDF · page 2'}).first().click();await page.locator('#pdf-page:not([hidden])').waitFor();await expect(page).toHaveURL(/page=2/);await expect(page.locator('#reading-page')).toBeHidden();checks.push('Original-page link switches to the exact C2 PDF page');
 await page.locator('#reading-format').click();await page.locator('#reading-page:not([hidden])').waitFor();await expect(page.locator('#pdf-page')).toBeHidden();checks.push('Reading text and original PDF remain independently available');
 await page.locator('#pages-toggle').click();expect(await page.locator('.thumbnail').evaluateAll(items=>items.map(item=>Number(item.dataset.page)))).toEqual([1,2,3]);await page.locator('.thumbnail[data-page="3"]').click();await page.locator('#pdf-page:not([hidden])').waitFor();await expect(page).toHaveURL(/page=3/);checks.push('Owned source thumbnails switch to the selected original PDF page');
 await writeFile(path.join(evidence,'reading-C2-browser.json'),JSON.stringify({capturedUtc:new Date().toISOString(),scope:config.scope,sourcePdfSha256:artifact.pdf_sha256,sourceDocxSha256:artifact.source_docx_sha256,artifactPublicationValidated:false,checks,paragraphReadTask:{...geometry,horizontalPanTaps:0},unexpectedPageErrors:errors},null,2));console.log(JSON.stringify({passed:checks.length,paragraph:geometry,errors}));
}finally{await browser.close()}
