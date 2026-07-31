// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
const { gotoEditPost } = require('./test-data-273');

/**
 * PR #273 e2e テスト
 * 品目テーブルの並び替え（jQuery UI Sortable）が行内どこをタップしても反応してしまい、
 * iPad 等のタッチデバイスで入力欄をタップできない不具合の修正確認（Issue #244）
 *
 * 修正対象: inc/custom-field-builder/js/flexible-table.js
 *   .sortable() の初期化オプションに handle: '.icon-drag' と
 *   cancel: 'input, textarea, select, button' を追加し、
 *   ドラッグ開始をハンドルアイコンに限定した。
 *
 * テスト対象画面: 投稿（post）編集画面の「請求品目」メタボックス
 *   inc/custom-field/custom-field-table.php が出力する
 *   <tbody class="sortable"> を含む品目テーブル
 *
 * テストデータ: create-test-data-pr-273.php が作成する
 *   「[e2e-test] PR273 並び替え・タップ確認用」の投稿（行A / 行B / 行C の3行）。
 *   投稿IDは環境（既存の投稿数）によって変わるため決め打ちにせず、
 *   test-data-273.js 経由でマニフェスト（.test-data-273.json）から読み込む。
 *   gotoEditPost() は意図した投稿の編集画面を開けているかをタイトルで確認するので、
 *   投稿が消えた環境で「フォーカスが当たる」「行数が増える」のような
 *   別の投稿でも偶然通る検証が素通りする「空振り PASS」も防げる。
 *
 * タッチ再現条件について:
 *   このバグの再現には jquery-touch-punch
 *   （タッチ操作を jQuery UI 向けのマウス操作に変換するライブラリ）が必要だが、
 *   これが読み込まれるかどうかは環境によって変わる。確認できている経路は2つある。
 *
 *   1. WordPress コアの投稿編集画面（wp-admin/edit-form-advanced.php）が
 *      wp_is_mobile() の場合に読み込む。コアの wp_is_mobile() は
 *      クライアントヒント（Sec-CH-UA-Mobile ヘッダ）を最優先で判定し、
 *      Playwright の isMobile: true はこのヘッダを ?1 で送るため、
 *      下記の test.use() を付けた describe ではプラグイン無しでも読み込まれる。
 *   2. 「VK All in One Expansion Unit」プラグインが管理画面全体で
 *      wp-color-picker（→ iris → jquery-touch-punch）を無条件 enqueue する。
 *
 *   どちらの経路も環境やコア・プラグインの実装変更で変わりうるため、
 *   タッチ操作を再現する describe は読み込み経路を前提にせず、
 *   jquery-touch-punch が実際に読み込まれているかをページ上で判定し、
 *   読み込まれていなければ test.skip() で理由を添えてスキップする。
 *   テストが必要としている条件そのもので判定しているので、
 *   読み込み経路が増減してもテストは壊れない。
 *   マウス操作の describe は touch-punch に依存しないため常に実行する。
 */

// global-setup.js で保存したログイン済み Cookie を使用する
test.use({ storageState: 'tests/e2e/.auth-state.json' });

// テストデータのキー（行A / 行B / 行C の3行を持つ品目テーブルの投稿）
const TEST_DATA_KEY = 'flexible_table';

// 品目テーブルの行
const ROW_SELECTOR = 'table.admin-bill-table tbody.sortable tr';

/**
 * jquery-touch-punch がページに読み込まれているかを判定する
 *
 * jquery-touch-punch は touchstart 等のタッチイベントを
 * mousedown 等のマウスイベントに変換するライブラリで、
 * これが読み込まれていないと jQuery UI Sortable はタッチ操作に反応せず、
 * Issue #244 のバグ（行内どこをタップしても並び替えが始まる）も再現しない。
 *
 * @param {import('@playwright/test').Page} page Playwright の Page。
 * @return {Promise<boolean>} 読み込まれていれば true。
 */
async function isTouchPunchLoaded(page) {
	return page.evaluate(() => {
		// 読み込まれた script タグの src で判定する
		// （WordPress 同梱の jquery.ui.touch-punch.js）
		const hasScript = Array.from(
			document.querySelectorAll('script[src]')
		).some((script) => /touch-punch/i.test(script.getAttribute('src') || ''));

		if (hasScript) {
			return true;
		}

		// スクリプトが連結・インライン化されていて src で判定できない場合の保険。
		// jquery-touch-punch は読み込まれると jQuery.support.touch を定義する
		const jQueryGlobal = /** @type {any} */ (window).jQuery;

		return !!(
			jQueryGlobal &&
			jQueryGlobal.support &&
			typeof jQueryGlobal.support.touch !== 'undefined'
		);
	});
}

test.describe('PR #273: 品目テーブルの入力欄クリック・並び替え確認（PC / マウス操作）', () => {

	test.beforeEach(async ({ page }) => {
		// テストデータの投稿の編集画面を開く
		// （意図した投稿を開けているかもタイトルで確認される）
		await gotoEditPost(page, TEST_DATA_KEY);
		// 品目テーブルの描画を待つ
		await page.waitForSelector(ROW_SELECTOR);
	});

	test('品目名の入力欄をクリックして文字を入力できる', async ({ page }) => {
		const nameInput = page.locator('input[name="bill_items[0][name]"]');
		await nameInput.click();
		await nameInput.fill('クリック入力テスト');
		await expect(nameInput).toHaveValue('クリック入力テスト');
	});

	test('数量・単位の入力欄をクリックして文字を入力できる', async ({ page }) => {
		const countInput = page.locator('input[name="bill_items[0][count]"]');
		await countInput.click();
		await countInput.fill('5');
		await expect(countInput).toHaveValue('5');

		const unitInput = page.locator('input[name="bill_items[0][unit]"]');
		await unitInput.click();
		await unitInput.fill('式');
		await expect(unitInput).toHaveValue('式');
	});

	test('「追加」ボタンをクリックすると行が1行増える', async ({ page }) => {
		const rowsBefore = await page.locator(ROW_SELECTOR).count();
		await page.locator(ROW_SELECTOR).first().locator('.add-row').click();
		const rowsAfter = await page.locator(ROW_SELECTOR).count();
		expect(rowsAfter).toBe(rowsBefore + 1);
	});

	test('「削除」ボタンをクリックすると行が1行減る', async ({ page }) => {
		const rowsBefore = await page.locator(ROW_SELECTOR).count();
		await page.locator(ROW_SELECTOR).last().locator('.del-row').click();
		const rowsAfter = await page.locator(ROW_SELECTOR).count();
		expect(rowsAfter).toBe(rowsBefore - 1);
	});

	test('ドラッグハンドル（.icon-drag）を掴んで行の並び替えができる', async ({ page }) => {
		// jQuery UI Sortable はビューポート外の座標では反応しないため、
		// テーブル全体が見えるようにビューポートを広げておく
		await page.setViewportSize({ width: 1280, height: 1800 });

		// 開始前の1行目の品目名を記録（行A想定）
		const rows = page.locator(ROW_SELECTOR);
		await rows.nth(0).scrollIntoViewIfNeeded();
		const firstNameBefore = await rows.nth(0).locator('input[name$="[name]"]').inputValue();

		const handle = rows.nth(0).locator('.icon-drag');
		const targetRow = rows.nth(2);

		const handleBox = await handle.boundingBox();
		const targetBox = await targetRow.boundingBox();
		if (!handleBox || !targetBox) {
			throw new Error('ドラッグ対象の要素が見つかりませんでした');
		}

		// ハンドルを掴んで3行目の位置までドラッグする
		// （jQuery UI Sortable のドラッグ判定に必要な中間移動を挟む）
		await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2);
		await page.mouse.down();
		await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2 + 5, { steps: 5 });
		await page.waitForTimeout(100);
		await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2, { steps: 15 });
		await page.waitForTimeout(200);
		await page.mouse.up();

		// sortstop 後の再初期化・行番号ふり直しを待つ
		await page.waitForTimeout(300);

		const firstNameAfter = await rows.nth(0).locator('input[name$="[name]"]').inputValue();

		// 1行目の内容が変わっている＝並び替えが機能していることを確認
		expect(firstNameAfter).not.toBe(firstNameBefore);
	});

});

test.describe('PR #273: タッチデバイスでの入力欄タップ確認（iPad相当エミュレーション）', () => {

	// iPad 相当のタッチ環境でテストする。
	// baseURL とログイン済み Cookie は playwright.config.js と
	// ファイル冒頭の test.use() から引き継がれるため、ここでは端末条件だけ指定する。
	// 高さを 1800 にしているのは、並び替えのテストで
	// ビューポート外の座標だと jQuery UI Sortable が反応しないため。
	test.use({
		hasTouch: true,
		isMobile: true,
		viewport: { width: 820, height: 1800 },
	});

	test.beforeEach(async ({ page }) => {
		// テストデータの投稿の編集画面を開く
		// （意図した投稿を開けているかもタイトルで確認される）
		await gotoEditPost(page, TEST_DATA_KEY);
		// 品目テーブルの描画を待つ
		await page.waitForSelector(ROW_SELECTOR);

		// jquery-touch-punch が読み込まれていない環境では、
		// タップは合成マウスイベントに変換されず Sortable が反応しないため、
		// 修正前のコードでもこのテストは通ってしまう（意味のある確認にならない）。
		// 失敗と区別できるよう、理由を添えて明示的にスキップする。
		const touchPunchLoaded = await isTouchPunchLoaded(page);
		test.skip(
			!touchPunchLoaded,
			'jquery-touch-punch（タッチ操作をマウス操作に変換するライブラリ）が読み込まれていないため、タッチ操作でのバグを再現できません。' +
				'通常は投稿編集画面が wp_is_mobile() の場合に読み込みますが、' +
				'サーバー側でクライアントヒント（Sec-CH-UA-Mobile）が届かない構成などでは読み込まれません。' +
				'VK All in One Expansion Unit を有効化すると、この判定によらず読み込まれます。'
		);
	});

	/**
	 * 品目名の入力欄をタップして、並び替えに邪魔されず
	 * フォーカスが当たるかを確認する。
	 */
	test('品目名の入力欄をタップするとフォーカスが当たる', async ({ page }) => {
		const nameInput = page.locator('input[name="bill_items[1][name]"]');
		await nameInput.tap();

		const isFocused = await nameInput.evaluate((el) => el === document.activeElement);
		expect(isFocused).toBe(true);
	});

	test('数量の入力欄をタップするとフォーカスが当たる', async ({ page }) => {
		const countInput = page.locator('input[name="bill_items[2][count]"]');
		await countInput.tap();

		const isFocused = await countInput.evaluate((el) => el === document.activeElement);
		expect(isFocused).toBe(true);
	});

	test('ドラッグハンドルをタッチ操作しても並び替えが機能する（デグレ確認）', async ({ page }) => {
		const rows = page.locator(ROW_SELECTOR);
		await rows.nth(0).scrollIntoViewIfNeeded();
		const firstNameBefore = await rows.nth(0).locator('input[name$="[name]"]').inputValue();

		const handle = rows.nth(0).locator('.icon-drag');
		const targetRow = rows.nth(2);

		const handleBox = await handle.boundingBox();
		const targetBox = await targetRow.boundingBox();
		if (!handleBox || !targetBox) {
			throw new Error('ドラッグ対象の要素が見つかりませんでした');
		}

		// タッチ由来の mousedown/mousemove/mouseup でドラッグをエミュレート
		// （jquery-touch-punch が touchstart 等を合成 mouse イベントに変換する挙動を模す）
		await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2);
		await page.mouse.down();
		await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2 + 5, { steps: 5 });
		await page.waitForTimeout(100);
		await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2, { steps: 15 });
		await page.waitForTimeout(200);
		await page.mouse.up();

		await page.waitForTimeout(300);

		const firstNameAfter = await rows.nth(0).locator('input[name$="[name]"]').inputValue();

		expect(firstNameAfter).not.toBe(firstNameBefore);
	});

});
