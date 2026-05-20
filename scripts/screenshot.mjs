/**
 * Capture dashboard screenshots for the README.
 *
 * Run with: node scripts/screenshot.mjs
 * Requires: php artisan serve on http://127.0.0.1:8000 + a seeded database.
 */
import puppeteer from 'puppeteer';
import { mkdir, readdir, stat } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const OUT_DIR = join(ROOT, 'docs', 'screenshots');
const BASE_URL = process.env.BASE_URL ?? 'http://127.0.0.1:8000';

const PAGES = [
    { route: '/', name: 'dashboard' },
    { route: '/employees', name: 'employees' },
    { route: '/wallets', name: 'wallets' },
    { route: '/transactions', name: 'transactions' },
    { route: '/withdrawals', name: 'withdrawals' },
    { route: '/payroll-events', name: 'payroll-events' },
    { route: '/bank-payments', name: 'bank-payments' },
    { route: '/health', name: 'health' },
];

async function firstWalletId() {
    const res = await fetch(`${BASE_URL}/api/wallets?per_page=1`);
    if (!res.ok) return null;
    const json = await res.json();
    return json?.data?.[0]?.id ?? null;
}

async function firstEmployeeId() {
    const res = await fetch(`${BASE_URL}/api/employees?per_page=1`);
    if (!res.ok) return null;
    const json = await res.json();
    return json?.data?.[0]?.id ?? null;
}

await mkdir(OUT_DIR, { recursive: true });

const walletId = await firstWalletId();
const employeeId = await firstEmployeeId();
if (walletId) PAGES.push({ route: `/wallets/${walletId}`, name: 'wallet-detail' });
if (employeeId) PAGES.push({ route: `/employees/${employeeId}`, name: 'employee-detail' });

const browser = await puppeteer.launch({
    headless: true,
    defaultViewport: { width: 1440, height: 900, deviceScaleFactor: 2 },
});

try {
    for (const { route, name } of PAGES) {
        const page = await browser.newPage();
        const url = `${BASE_URL}${route}`;
        process.stdout.write(`→ ${name.padEnd(18)} ${url} ... `);
        await page.goto(url, { waitUntil: 'networkidle0', timeout: 20000 });
        await new Promise((r) => setTimeout(r, 400));
        const filepath = join(OUT_DIR, `${name}.png`);
        await page.screenshot({ path: filepath, fullPage: true });
        await page.close();
        const { size } = await stat(filepath);
        console.log(`${(size / 1024).toFixed(1)} KB`);
    }
} finally {
    await browser.close();
}

const files = (await readdir(OUT_DIR)).filter((f) => f.endsWith('.png')).sort();
console.log(`\nWrote ${files.length} screenshots to ${OUT_DIR}`);
