// Renders the SVG sources in resources/icons to the PNG sizes the manifest needs. Run once after changing them.
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright-core';

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const jobs = [['icon', 192, 'icon-192'], ['icon', 512, 'icon-512'], ['maskable', 512, 'maskable-512'], ['icon', 180, 'apple-touch-icon']];
for (const [src, size, name] of jobs) {
    const page = await browser.newPage({ viewport: { width: size, height: size } });
    await page.setContent(`<style>html,body{margin:0}svg{display:block;width:${size}px;height:${size}px}</style>${readFileSync(`resources/icons/${src}.svg`, 'utf8')}`);
    await page.screenshot({ path: `public/icons/${name}.png` });
    await page.close();
}
await browser.close();
console.log('icons written to public/icons');
