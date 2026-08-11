// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');

/**
 * PR #311 取引先名フォールバック修正の UI / e2e テスト。
 *
 * 実行前（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 * npx wp-env run cli wp eval-file "wp-content/themes/$(basename "$PWD")/tests/e2e/create-test-data-pr-311.php"
 *
 * 実行後:
 * npx wp-env run cli wp eval-file "wp-content/themes/$(basename "$PWD")/tests/e2e/cleanup-test-data-pr-311.php"
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * $(basename "$PWD") でカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 */

test.use({ storageState: 'tests/e2e/.auth-state.json' });

/**
 * フロント一覧の件名から対象行を取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function frontRow(page, title) {
	return page.locator('#main table tr').filter({
		has: page.locator('td a', { hasText: title }),
	});
}

/**
 * フロント一覧の取引先セルを取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function frontClientCell(page, title) {
	return frontRow(page, title).locator('td').nth(2);
}

/**
 * 管理画面の件名から対象行を取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function adminEstimateRow(page, title) {
	return page.locator('#the-list tr').filter({
		has: page.locator('.column-title .row-title', { hasText: title }),
	});
}

test.describe('PR #311: 取引先名フォールバック', () => {
	test('フロントの見積書一覧で各取引先パターンを正しく表示する', async ({ page }) => {
		await page.goto('/?post_type=estimate');
		await page.waitForLoadState('networkidle');

		// 未設定・不正値・削除済み ID は、書類自身や無関係な投稿へリンクせずダッシュにする。
		for (const title of [
			'PR311 未設定（メタなし）の見積',
			'PR311 未設定（空文字）の見積',
			'PR311 配列値の見積',
			'PR311 削除済み取引先の見積',
		]) {
			const cell = frontClientCell(page, title);
			await expect(cell.locator('[aria-hidden="true"]')).toHaveText('—');
			await expect(cell.locator('.screen-reader-text')).toHaveText('取引先なし');
			await expect(cell.locator('a')).toHaveCount(0);
			await expect(cell).not.toContainText(title);
			await expect(cell).not.toContainText('Hello world!');
		}

		// 登録済み取引先は省略名を表示し、取引先ページへ別タブリンクを張る。
		const registeredCell = frontClientCell(page, 'PR311 登録済み取引先の見積');
		await expect(registeredCell).toContainText('PR311 テスト社');
		await expect(registeredCell.locator('a')).toHaveCount(1);
		await expect(registeredCell.locator('a')).toHaveAttribute('href', /[?&]client=\d+|\/client\//);
		await expect(registeredCell.locator('a')).toHaveAttribute('target', '_blank');

		// 手入力の取引先は文字列だけを表示し、リンクは張らない。
		const manualCell = frontClientCell(page, 'PR311 手入力取引先の見積');
		await expect(manualCell).toHaveText('PR311 手入力の取引先');
		await expect(manualCell.locator('a')).toHaveCount(0);
	});

	test('取引先一覧で名前あり・無題の行をアクセシブルに表示する', async ({ page }) => {
		await page.goto('/?post_type=client');
		await page.waitForLoadState('networkidle');

		// 名前ありの取引先は自身のページへの別タブリンクを維持する。
		const namedLink = page.getByRole('link', { name: 'PR311 株式会社テスト取引先', exact: true });
		await expect(namedLink).toHaveCount(1);
		await expect(namedLink).toHaveAttribute('target', '_blank');

		// 無題の取引先は空アンカーにせず、ダッシュと読み上げ用テキストをリンク内に置く。
		const untitledLink = page.getByRole('link', { name: '名称未設定の取引先', exact: true });
		await expect(untitledLink).toHaveCount(1);
		await expect(untitledLink).toHaveAttribute('target', '_blank');
		await expect(untitledLink.locator('[aria-hidden="true"]')).toHaveText('—');
		await expect(untitledLink.locator('.screen-reader-text')).toHaveText('名称未設定の取引先');
		expect((await untitledLink.innerHTML()).trim()).not.toBe('');
	});

	test('取引先未設定の見積単体で件名を取引先欄と title に重複表示しない', async ({ page }) => {
		await page.goto('/?post_type=estimate');
		const row = frontRow(page, 'PR311 未設定（メタなし）の見積');
		const detailLink = row.getByRole('link', { name: 'PR311 未設定（メタなし）の見積' });
		const href = await detailLink.getAttribute('href');
		expect(href).toBeTruthy();

		await page.goto(href);
		await page.waitForLoadState('networkidle');

		// 書類の取引先欄に件名が誤表示されず、空欄になる。
		await expect(page.locator('.bill-wrap')).toBeVisible();
		await expect(page.locator('.bill-destination-client')).toBeEmpty();

		// ブラウザタイトルでも件名は 1 回だけにする。
		const title = await page.title();
		expect(title.split('PR311 未設定（メタなし）の見積')).toHaveLength(2);
	});

	test('管理画面の見積書一覧で取引先あり・未設定を従来どおり表示する', async ({ page }) => {
		await page.goto('/wp-admin/edit.php?post_type=estimate');
		await page.waitForLoadState('networkidle');

		await expect(
			adminEstimateRow(page, 'PR311 登録済み取引先の見積').locator('.column-bill_client_name')
		).toHaveText('PR311 株式会社テスト取引先');

		for (const title of [
			'PR311 未設定（メタなし）の見積',
			'PR311 未設定（空文字）の見積',
			'PR311 配列値の見積',
			'PR311 削除済み取引先の見積',
		]) {
			const cell = adminEstimateRow(page, title).locator('.column-bill_client_name');
			await expect(cell.locator('[aria-hidden="true"]')).toHaveText('—');
			await expect(cell.locator('.screen-reader-text')).toHaveText('取引先なし');
			await expect(cell).not.toContainText(title);
			await expect(cell).not.toContainText('Hello world!');
		}
	});
});
