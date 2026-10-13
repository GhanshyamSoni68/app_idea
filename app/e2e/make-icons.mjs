// Renders the app icons from Step's head artwork (resources/mascot/step-head-512.png) onto the brand cream.
// "any" icons fill the tile; the maskable icon keeps Step inside the safe zone (about 70%) so OS masks never crop it.
// Run after changing the artwork: npm run icons
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright-core';

const head = `data:image/png;base64,${readFileSync('resources/mascot/step-head-512.png').toString('base64')}`;
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const jobs = [['icon-192', 192, 0.9], ['icon-512', 512, 0.9], ['maskable-512', 512, 0.68], ['apple-touch-icon', 180, 0.9]];
for (const [name, size, scale] of jobs) {
    const page = await browser.newPage({ viewport: { width: size, height: size } });
    await page.setContent(`<style>html,body{margin:0}body{width:${size}px;height:${size}px;background:#fdf5e6;display:grid;place-items:center}img{width:${size * scale}px;height:${size * scale}px}</style><img src="${head}">`);
    await page.screenshot({ path: `public/icons/${name}.png` });
    await page.close();
}
await browser.close();
console.log('icons written to public/icons');
