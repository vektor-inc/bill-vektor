// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');

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
 * テストデータ: Post ID 37（[e2e-test] PR273 並び替え・タップ確認用）
 *   行A / 行B / 行C の3行を用意（各行の並び替えを見分けるため名前を変えてある）
 *
 * タッチ再現条件について:
 *   jquery-touch-punch は本来 wp_is_mobile() が true の場合のみ読み込まれるが、
 *   「VK All in One Expansion Unit」プラグインが管理画面全体で
 *   wp-color-picker（→ iris → jquery-touch-punch）を無条件 enqueue するため、
 *   このプラグインを有効化した状態でのみ本来のバグを再現できる。
 *   このテストを実行する際は vk-all-in-one-expansion-unit を有効化した状態で実行すること。
 */

// global-setup.js で保存したログイン済み Cookie を使用する
test.use({ storageState: 'tests/e2e/.auth-state.json' });

// テストデータの投稿ID（行A/行B/行Cの3行を持つ品目テーブル）
const TEST_POST_ID = 37;
const EDIT_URL = `/wp-admin/post.php?post=${TEST_POST_ID}&action=edit`;

test.describe('PR #273: 品目テーブルの入力欄クリック・並び替え確認（PC / マウス操作）', () => {

	test.beforeEach(async ({ page }) => {
		await page.goto(EDIT_URL);
		// 品目テーブルの描画を待つ
		await page.waitForSelector('table.admin-bill-table tbody.sortable tr');
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
		const rowsBefore = await page.locator('table.admin-bill-table tbody.sortable tr').count();
		await page.locator('table.admin-bill-table tbody.sortable tr').first().locator('.add-row').click();
		const rowsAfter = await page.locator('table.admin-bill-table tbody.sortable tr').count();
		expect(rowsAfter).toBe(rowsBefore + 1);
	});

	test('「削除」ボタンをクリックすると行が1行減る', async ({ page }) => {
		const rowsBefore = await page.locator('table.admin-bill-table tbody.sortable tr').count();
		await page.locator('table.admin-bill-table tbody.sortable tr').last().locator('.del-row').click();
		const rowsAfter = await page.locator('table.admin-bill-table tbody.sortable tr').count();
		expect(rowsAfter).toBe(rowsBefore - 1);
	});

	test('ドラッグハンドル（.icon-drag）を掴んで行の並び替えができる', async ({ page }) => {
		// jQuery UI Sortable はビューポート外の座標では反応しないため、
		// テーブル全体が見えるようにビューポートを広げておく
		await page.setViewportSize({ width: 1280, height: 1800 });

		// 開始前の1行目の品目名を記録（行A想定）
		const rows = page.locator('table.admin-bill-table tbody.sortable tr');
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

	/**
	 * iPad相当のタッチコンテキストを作成し、品目名の入力欄をタップして
	 * フォーカスが当たるか確認する。
	 * jquery-touch-punch が読み込まれていない環境では、修正前でも
	 * touchstart は本物の mousedown を発火させるだけなので再現しない点に注意。
	 */
	test('品目名の入力欄をタップするとフォーカスが当たる', async ({ browser }) => {
		const context = await browser.newContext({
			storageState: 'tests/e2e/.auth-state.json',
			hasTouch: true,
			isMobile: true,
			viewport: { width: 820, height: 1180 },
		});
		const page = await context.newPage();

		await page.goto(EDIT_URL);
		await page.waitForSelector('table.admin-bill-table tbody.sortable tr');

		const nameInput = page.locator('input[name="bill_items[1][name]"]');
		await nameInput.tap();

		const isFocused = await nameInput.evaluate((el) => el === document.activeElement);
		expect(isFocused).toBe(true);

		await context.close();
	});

	test('数量の入力欄をタップするとフォーカスが当たる', async ({ browser }) => {
		const context = await browser.newContext({
			storageState: 'tests/e2e/.auth-state.json',
			hasTouch: true,
			isMobile: true,
			viewport: { width: 820, height: 1180 },
		});
		const page = await context.newPage();

		await page.goto(EDIT_URL);
		await page.waitForSelector('table.admin-bill-table tbody.sortable tr');

		const countInput = page.locator('input[name="bill_items[2][count]"]');
		await countInput.tap();

		const isFocused = await countInput.evaluate((el) => el === document.activeElement);
		expect(isFocused).toBe(true);

		await context.close();
	});

	test('ドラッグハンドルをタッチ操作しても並び替えが機能する（デグレ確認）', async ({ browser }) => {
		const context = await browser.newContext({
			storageState: 'tests/e2e/.auth-state.json',
			hasTouch: true,
			isMobile: true,
			viewport: { width: 820, height: 1800 },
		});
		const page = await context.newPage();

		await page.goto(EDIT_URL);
		await page.waitForSelector('table.admin-bill-table tbody.sortable tr');

		const rows = page.locator('table.admin-bill-table tbody.sortable tr');
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

		await context.close();
	});

});
