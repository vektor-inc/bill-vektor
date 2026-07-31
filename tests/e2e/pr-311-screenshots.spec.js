// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test } = require('@playwright/test');
const path = require('path');

/**
 * PR #311 の before / after 比較用スクリーンショット。
 *
 * SCREENSHOT_PHASE に before / after、SCREENSHOT_DIR に絶対保存先を渡す。
 * 撮影条件を揃えるため、同じ viewport・同じテストデータを使う。
 */
test.use({
	storageState: 'tests/e2e/.auth-state.json',
	viewport: { width: 1440, height: 1100 },
});

const phase = process.env.SCREENSHOT_PHASE || 'after';
const outputDir = process.env.SCREENSHOT_DIR || 'tests/e2e/screenshots';

for (const [slug, pagePath] of [
	['estimate-list', '/?post_type=estimate'],
	['client-list', '/?post_type=client'],
]) {
	test(`${phase}: ${slug}`, async ({ page }) => {
		await page.goto(pagePath);
		await page.waitForLoadState('networkidle');
		await page.screenshot({
			path: path.join(outputDir, `${phase}-${slug}.png`),
			fullPage: true,
		});
	});
}
