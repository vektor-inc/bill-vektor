// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
const { gotoTestPost } = require('./test-data-266');

/**
 * PR #266 e2e テスト
 * 税込入力の品目で消費税の端数処理が二重に適用され税込合計が1円ずれる不具合の修正確認
 *
 * テスト概要:
 * - 税込6000円入力 → 修正前は6001円、修正後は6000円になることを確認
 * - 税抜入力のデグレがないことを確認
 *
 * bill-vektor テーマはログインが必要なため、
 * global-setup.js で取得したログイン済み storageState を全テストで使い回す。
 *
 * テストデータの投稿IDは環境（既存の投稿数）によって変わるため、
 * test-data-266.js 経由で create-test-data.php が書き出したマニフェストから読み込む。
 * gotoTestPost() は意図した投稿が表示されていることまで確認するので、
 * 投稿が消えた環境で否定形の検証が素通りする「空振り PASS」も防げる。
 */

// global-setup.js で保存したログイン済み Cookie を使用する
// これにより各テストで再ログインが不要になる
test.use({ storageState: 'tests/e2e/.auth-state.json' });

test.describe('PR #266: 税込入力の端数処理二重適用バグ修正確認', () => {

	/**
	 * テスト1: 税込6000円（四捨五入）+ 消費税デフォルト（四捨五入）
	 * バグ修正の主要なケース
	 * - 修正前: 税込合計が 6,001円（1円ずれ）
	 * - 修正後: 税込合計が 6,000円（正しい値）
	 */
	test('税込6000円（四捨五入・消費税デフォルト）で税込合計が6000円になること', async ({ page }) => {
		// テスト対象の請求書ページを開く（意図した投稿が表示されているかも確認される）
		await gotoTestPost(page, 'tax_round_default');

		const pageContent = await page.content();

		// 6,001 が表示されていないことを確認（バグの再現値）
		expect(pageContent).not.toContain('6,001');
		// 6,000 が表示されることを確認（正しい値）
		expect(pageContent).toContain('6,000');
	});

	/**
	 * テスト2: 税込6000円（四捨五入）+ 消費税切り上げ設定
	 * bill_tax_fraction が ceil の場合も修正が適用されることを確認
	 * - 修正前: 税込合計が 6,001円
	 * - 修正後: 税込合計が 6,000円（税込入力では bill_tax_fraction は効かない）
	 */
	test('税込6000円（四捨五入・消費税切り上げ）で税込合計が6000円になること', async ({ page }) => {
		await gotoTestPost(page, 'tax_round_ceil');

		const pageContent = await page.content();

		// 6,001 が表示されていないことを確認（バグの再現値）
		expect(pageContent).not.toContain('6,001');
		// 6,000 が表示されることを確認（正しい値）
		expect(pageContent).toContain('6,000');
	});

	/**
	 * テスト3: 税抜10000円 + 消費税切り捨てのデグレ確認
	 * 税抜入力では従来どおりの計算が正常に動作することを確認
	 * - 期待値: 税込合計 11,000円
	 */
	test('税抜10000円（消費税切り捨て）で税込合計が11000円になること（デグレ確認）', async ({ page }) => {
		await gotoTestPost(page, 'tax_excluded');

		const pageContent = await page.content();
		expect(pageContent).toContain('11,000');
	});

	/**
	 * テスト4: 税抜3333円×3個 + 消費税切り捨てのデグレ確認
	 * 割り切れない価格×複数個で bill_tax_fraction（切り捨て）が正常に動作することを確認
	 * - 計算: 税抜合計 9,999円 × 0.1 = 999.9 → floor → 999円、税込合計 10,998円
	 */
	test('税抜3333円×3個（消費税切り捨て）で税込合計が10998円になること（デグレ確認）', async ({ page }) => {
		await gotoTestPost(page, 'tax_excluded_3333');

		const pageContent = await page.content();
		expect(pageContent).toContain('10,998');
	});

});

test.describe('PR #266: 合計金額テーブルの詳細表示確認', () => {

	/**
	 * テスト5: 税込6000円の合計金額テーブル詳細確認
	 * 税抜金額・消費税額・税込合計の各値が正しく表示されることを確認
	 * - 税抜金額: 5,455円（税込6000 ÷ 1.1 = 5454.54... → round → 5,455）
	 * - 消費税額: 545円（6,000 - 5,455 = 545　修正前は 5,455 × 0.1 = 545.5 → round → 546）
	 * - 税込合計: 6,000円
	 */
	test('税込6000円の合計金額テーブルで税抜・消費税・税込の各値が正しいこと', async ({ page }) => {
		await gotoTestPost(page, 'tax_round_default');

		const pageContent = await page.content();

		// 税抜金額: 5,455円が表示されること
		expect(pageContent).toContain('5,455');
		// 税込合計: 6,000円が表示されること
		expect(pageContent).toContain('6,000');
		// 修正前のバグ値 6,001 が表示されていないこと
		expect(pageContent).not.toContain('6,001');
	});

});

test.describe('PR #266: スクリーンショット撮影', () => {

	/**
	 * after: 修正後の表示を撮影（レビュー資料用）
	 * 合計金額テーブルの表示を確認するためのスクリーンショット
	 */
	test('after: 税込6000円（四捨五入）の合計金額テーブル表示', async ({ page }) => {
		await gotoTestPost(page, 'tax_round_default');
		await page.waitForLoadState('networkidle');
		// スクリーンショットを tests/e2e/screenshots/ に保存
		await page.screenshot({
			path: 'tests/e2e/screenshots/after-tax-included-6000.png',
			fullPage: true,
		});
	});

	test('after: 税抜10000円の合計金額テーブル表示（デグレ確認）', async ({ page }) => {
		await gotoTestPost(page, 'tax_excluded');
		await page.waitForLoadState('networkidle');
		await page.screenshot({
			path: 'tests/e2e/screenshots/after-tax-excluded-10000.png',
			fullPage: true,
		});
	});

	test('after: 税抜3333円×3個（消費税切り捨て）の表示（デグレ確認）', async ({ page }) => {
		await gotoTestPost(page, 'tax_excluded_3333');
		await page.waitForLoadState('networkidle');
		await page.screenshot({
			path: 'tests/e2e/screenshots/after-tax-excluded-3333x3.png',
			fullPage: true,
		});
	});

});
