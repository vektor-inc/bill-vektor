// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

/**
 * スクリーンショット撮影スクリプト（PR #266）
 * 修正後の表示を撮影して review-assets に保存するための素材を取得する
 */

test.use({ storageState: 'tests/e2e/.auth-state.json' });

// スクリーンショット保存先ディレクトリ
const dir = 'tests/e2e/screenshots';

// 各テストの実行前に一度だけ保存先ディレクトリを作成する（重複処理をまとめる）
test.beforeAll(() => {
	if ( ! fs.existsSync(dir) ) {
		fs.mkdirSync(dir, { recursive: true });
	}
});

test('after: 税込6000円（四捨五入）の合計金額テーブル', async ({ page }) => {
	await page.goto('/?p=4');
	await page.waitForLoadState('networkidle');

	await page.screenshot({
		path: path.join(dir, 'after-tax-included-6000.png'),
		fullPage: true,
	});
});

test('after: 税抜10000円の合計金額テーブル（デグレ確認）', async ({ page }) => {
	await page.goto('/?p=6');
	await page.waitForLoadState('networkidle');

	await page.screenshot({
		path: path.join(dir, 'after-tax-excluded-10000.png'),
		fullPage: true,
	});
});

test('after: 税抜3333円×3個（消費税切り捨て）の合計金額テーブル（デグレ確認）', async ({ page }) => {
	await page.goto('/?p=7');
	await page.waitForLoadState('networkidle');

	await page.screenshot({
		path: path.join(dir, 'after-tax-excluded-3333x3.png'),
		fullPage: true,
	});
});
