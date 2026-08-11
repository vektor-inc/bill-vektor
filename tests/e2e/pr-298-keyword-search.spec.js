// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { requireTestDataPresent } = require('./require-test-data');
const { AUTH_STATE_PATH, withAuthenticatedPage } = require('./auth-helpers');

/**
 * PR #298 e2e テスト
 *
 * 書類一覧のキーワード検索、既存条件との併用、ページ送り、CSV 出力、
 * および 375px / 768px でのレスポンシブ表示をブラウザ上で確認する。
 *
 * 実行前に、テストデータ作成スクリプトで書類・取引先を作成しておくこと。
 * 件名・発行日はスクリプト側の値と対になっているため、片方だけを変更しないこと。
 * テーマのディレクトリ（このリポジトリのルート）で実行する。
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-298.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 *
 * スクリプトは繰り返し実行しても同じ結果になる（同じ件名の書類があれば再利用する）。
 * DB の import / reset / export は行わない。
 */

// global-setup.js で保存したログイン済み Cookie を使用する。
test.use({ storageState: AUTH_STATE_PATH });

const SCREENSHOT_DIR = path.resolve('tests/e2e/screenshots');

/**
 * 一覧テーブルに表示されている件名を上から順に取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string[]>}
 */
async function getDocumentTitles(page) {
	return page.locator('table.table tr')
		.filter({ has: page.locator('td') })
		.locator('td:nth-child(4)')
		.allTextContents();
}

/**
 * 検索フォームを送信し、画面遷移が終わるまで待つ。
 *
 * @param {import('@playwright/test').Page} page
 */
async function submitFilters(page) {
	// click() がフォーム送信後の画面遷移まで待つため、別の navigation 待機は重ねない。
	await page.getByRole('button', { name: /絞り込み/ }).click();
	expect(new URL(page.url()).searchParams.get('action')).toBe('send');
}

/**
 * WordPress の「1ページに表示する最大投稿数」を管理画面の UI から変更する。
 * ページ送りのテスト後は、呼び出し側の finally で必ず元の値へ戻す。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} value
 */
async function setPostsPerPage(page, value) {
	await page.goto('/wp-admin/options-reading.php');
	const input = page.locator('#posts_per_page');
	await expect(input).toBeVisible();
	await input.fill(value);
	// click() 自体がフォーム送信後のナビゲーション完了まで待つ。
	await page.locator('#submit').click();
	await expect(page.locator('.notice-success')).toContainText('Settings saved.');
	await expect(input).toHaveValue(value);
}

test.beforeAll(() => {
	fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
});

// データ作成スクリプト未実行の環境で、各テストが30秒タイムアウトを積み重ねて
// 落ちるのを防ぐため、前提データ（請求書）が1件でも存在するかを先に確認する。
const PR298_SETUP_HINT =
	'PR #298 のテストデータを作成してから実行してください:\n' +
	'  npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-298.php';

test.beforeAll(async ({ browser }) => {
	await withAuthenticatedPage(browser, (page) =>
		requireTestDataPresent(
			page,
			// フロントのトップページ「/」は新着10件までしか表示されないため、
			// 他のテストデータ作成スクリプト（例: create-test-data-pr-314.php が
			// 実行時刻を発行日にした投稿を11件作る）が積み重なると対象がページ外へ
			// 押し出され、存在するのに「見つからない」と誤判定してしまう。
			// 件数に依存しない管理画面の検索結果で確認する。
			'/wp-admin/edit.php?post_type=post&s=' +
				encodeURIComponent('ロゴ制作費'),
			(p) => p.locator('.row-title', { hasText: 'ロゴ制作費' }),
			'PR #298 の請求書「ロゴ制作費」',
			PR298_SETUP_HINT
		)
	);
});

test.describe('PR #298: キーワード検索と既存条件の併用', () => {

	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 1200 });
		await page.goto('/');
		await expect(page.locator('#bill_keyword')).toBeVisible();
	});

	test('キーワードで絞り込め、値の維持と空欄による解除ができる', async ({ page }) => {
		// 新しい入力欄・注記・全幅ボタンが表示されることを確認する。
		await expect(page.getByLabel('キーワード')).toHaveAttribute('type', 'search');
		await expect(page.locator('.search-box .help-block'))
			.toHaveText('キーワードは書類の「件名」を対象に検索します。');

		const searchBox = await page.locator('.search-box').boundingBox();
		const submitButton = await page.getByRole('button', { name: /絞り込み/ }).boundingBox();
		expect(searchBox).not.toBeNull();
		expect(submitButton).not.toBeNull();
		expect(Math.abs(submitButton.width - searchBox.width)).toBeLessThanOrEqual(1);

		// 「サイト」で請求書を絞り込み、件名と入力値を確認する。
		await page.locator('#bill_keyword').fill('サイト');
		await submitFilters(page);
		expect(await getDocumentTitles(page)).toEqual(['サイト制作費']);
		await expect(page.locator('#bill_keyword')).toHaveValue('サイト');

		// キーワードを空にして再送信すると、キーワード絞り込みが解除される。
		await page.locator('#bill_keyword').fill('');
		await submitFilters(page);
		const titlesAfterClear = await getDocumentTitles(page);
		expect(titlesAfterClear).toContain('サイト制作費');
		expect(titlesAfterClear).toContain('ロゴ制作費');
		expect(titlesAfterClear.length).toBeGreaterThan(1);
		await expect(page.locator('#bill_keyword')).toHaveValue('');
	});

	test('書類種別・取引先・発行日とキーワードを併用できる', async ({ page }) => {
		// 見積書 + 取引先 + キーワードの3条件を同時に指定する。
		await page.locator('#post_type').selectOption('estimate');
		await page.locator('#bill_client').selectOption({ label: '株式会社テスト商事' });
		await page.locator('#bill_keyword').fill('サイト');
		await submitFilters(page);
		expect(await getDocumentTitles(page)).toEqual(['サイトリニューアル見積']);

		// 発行日 + キーワードの併用で、2023年の2書類だけに絞り込む。
		await page.locator('#post_type').selectOption('post');
		await page.locator('#bill_client').selectOption('');
		await page.locator('#start_date').fill('20230101');
		await page.locator('#end_date').fill('20231231');
		await page.locator('#bill_keyword').fill('年度 更新');
		await submitFilters(page);
		expect(await getDocumentTitles(page)).toEqual([
			'更新プランの年度切替',
			'年度 更新プラン',
		]);
	});

	test('本文だけの語句はヒットせず、検索対象が件名に限定される', async ({ page }) => {
		await page.locator('#bill_keyword').fill('本文限定キーワード');
		await submitFilters(page);

		await expect(page.getByText('該当の書類はありません。', { exact: true })).toBeVisible();
		expect(await getDocumentTitles(page)).toEqual([]);
		await expect(page.locator('#bill_keyword')).toHaveValue('本文限定キーワード');
	});

	test('複数語でも発行日の新しい順を維持する', async ({ page }) => {
		await page.locator('#bill_keyword').fill('年度 更新');
		await submitFilters(page);

		expect(await getDocumentTitles(page)).toEqual([
			'更新プランの年度切替',
			'年度 更新プラン',
		]);
		const dates = await page.locator('table.table tr')
			.filter({ has: page.locator('td') })
			.locator('td:nth-child(2)')
			.allTextContents();
		expect(dates).toEqual(['2023.08.01', '2023.01.01']);
	});

	test('キーワード「0」の1文字でも絞り込める', async ({ page }) => {
		await page.locator('#bill_keyword').fill('0');
		await submitFilters(page);

		expect(await getDocumentTitles(page)).toEqual(['型番0123の部品代']);
		await expect(page.locator('#bill_keyword')).toHaveValue('0');
	});

	test('キーワードがページ送りの2ページ目でも維持される', async ({ page }) => {
		// 管理画面で表示件数を変更して元に戻す2回の保存を含むため、個別に余裕を持たせる。
		test.setTimeout(60000);

		// 確認用データを増やさず、管理画面 UI で一時的に1件/ページへ変更する。
		// 一覧ページの遷移と管理画面の保存を干渉させないよう、設定専用ページを使う。
		const settingsPage = await page.context().newPage();
		await settingsPage.goto('/wp-admin/options-reading.php');
		const originalPostsPerPage = await settingsPage.locator('#posts_per_page').inputValue();
		await setPostsPerPage(settingsPage, '1');
		await settingsPage.close();

		try {
			// 基本のフォーム送信は別テストで確認済み。ここではページ送り自体へ
			// 焦点を絞り、同じ GET 条件の相対 URL から1ページ目を開く。
			await page.goto('/?post_type=post&bill_keyword=%E5%88%B6%E4%BD%9C%E8%B2%BB&action=send');

			expect(await getDocumentTitles(page)).toEqual(['サイト制作費']);
			const secondPageLink = page.locator('a.page-numbers').filter({ hasText: /^2$/ });
			await expect(secondPageLink).toHaveAttribute('href', /bill_keyword=/);
			// トップページ内の外部 RSS 読み込みに影響されないよう、クリック開始後は
			// DOMContentLoaded とページ番号 URL を明示的な完了条件にする。
			await secondPageLink.evaluate((element) => element.click());
			await page.waitForURL(/\/page\/2\//, { waitUntil: 'domcontentloaded' });

			expect(await getDocumentTitles(page)).toEqual(['ロゴ制作費']);
			await expect(page.locator('#bill_keyword')).toHaveValue('制作費');
			expect(new URL(page.url()).searchParams.get('bill_keyword')).toBe('制作費');
		} finally {
			const restorePage = await page.context().newPage();
			await setPostsPerPage(restorePage, originalPostsPerPage);
			await restorePage.close();
		}
	});

	test('キーワードがCSVエクスポートへ反映され、案内文とボタン幅も維持される', async ({ page }) => {
		await page.locator('#bill_keyword').fill('サイト');
		await submitFilters(page);

		// 更新された案内文が常時表示されることを確認する。
		await expect(page.locator('#csv-export'))
			.toContainText('※エクスポートしたい期間・キーワードなど必要に応じて上部検索ボックスで指定してください。');

		// 検索ボタンの全幅化が CSV ボタンへ波及せず、2つとも親カラムの幅に収まることを確認する。
		const exportButtons = page.locator('.export-box .search-submit');
		await expect(exportButtons).toHaveCount(2);
		for (let index = 0; index < 2; index++) {
			const button = exportButtons.nth(index);
			const column = button.locator('xpath=..');
			const buttonBox = await button.boundingBox();
			const columnBox = await column.boundingBox();
			const columnPadding = await column.evaluate((element) => {
				const style = getComputedStyle(element);
				return parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
			});
			expect(buttonBox).not.toBeNull();
			expect(columnBox).not.toBeNull();
			const columnContentWidth = columnBox.width - columnPadding;
			expect(buttonBox.width / columnContentWidth).toBeGreaterThanOrEqual(0.47);
			expect(buttonBox.width / columnContentWidth).toBeLessThanOrEqual(0.49);
			expect(buttonBox.width).toBeLessThan(page.viewportSize().width / 2);
		}

		// MFクラウド会計用 CSV を実際にダウンロードし、件名の抽出条件を照合する。
		const downloadPromise = page.waitForEvent('download');
		await page.getByRole('button', { name: /MFクラウド会計用CSVエクスポート/ }).click();
		const download = await downloadPromise;
		const downloadPath = await download.path();
		expect(downloadPath).not.toBeNull();

		const csvBuffer = fs.readFileSync(downloadPath);
		const csvText = new TextDecoder('shift_jis').decode(csvBuffer);
		expect(csvText).toContain('サイト制作費');
		expect(csvText).not.toContain('ロゴ制作費');
		expect(csvText).not.toContain('保守費用（月額）');
	});

	test('キーワード空欄でも従来の書類種別・取引先・発行日絞り込みが効く', async ({ page }) => {
		// 書類種別 + 取引先の回帰確認。
		await page.locator('#post_type').selectOption('estimate');
		await page.locator('#bill_client').selectOption({ label: '株式会社テスト商事' });
		await expect(page.locator('#bill_keyword')).toHaveValue('');
		await submitFilters(page);
		expect(await getDocumentTitles(page)).toEqual(['サイトリニューアル見積']);

		// 発行日だけの回帰確認。
		await page.locator('#post_type').selectOption('post');
		await page.locator('#bill_client').selectOption('');
		await page.locator('#start_date').fill('20230801');
		await page.locator('#end_date').fill('20230801');
		await submitFilters(page);
		expect(await getDocumentTitles(page)).toEqual(['更新プランの年度切替']);
	});
});

test.describe('PR #298: レスポンシブ表示と余白', () => {

	test('375pxでは1カラムで、日付8桁が読める幅を確保する', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 1000 });
		await page.goto('/');

		const filterItems = page.locator('.search-box dl');
		await expect(filterItems).toHaveCount(4);
		const boxes = await filterItems.evaluateAll((elements) =>
			elements.map((element) => element.getBoundingClientRect().toJSON())
		);

		// 4項目の左端と幅が揃い、上から順に重ならず積まれていることを確認する。
		for (let index = 0; index < boxes.length; index++) {
			expect(Math.abs(boxes[index].x - boxes[0].x)).toBeLessThanOrEqual(1);
			expect(Math.abs(boxes[index].width - boxes[0].width)).toBeLessThanOrEqual(1);
			if (index > 0) {
				expect(boxes[index].top).toBeGreaterThanOrEqual(boxes[index - 1].bottom);
			}
		}

		// 各ラベルが対応する入力欄・選択欄より上に配置されることを確認する。
		for (const id of ['post_type', 'bill_client', 'bill_keyword']) {
			const control = page.locator(`#${id}`);
			const item = control.locator('xpath=ancestor::dl');
			const labelBox = await item.locator('dt').boundingBox();
			const controlBox = await control.boundingBox();
			expect(labelBox).not.toBeNull();
			expect(controlBox).not.toBeNull();
			expect(labelBox.y + labelBox.height).toBeLessThanOrEqual(controlBox.y);
		}

		// 区切りなし8桁を入力し、実際のテキスト描画幅が入力欄の内側に収まることを確認する。
		for (const id of ['start_date', 'end_date']) {
			const input = page.locator(`#${id}`);
			await input.fill('20260730');
			await expect(input).toHaveValue('20260730');
			const textFit = await input.evaluate((element) => {
				const style = getComputedStyle(element);
				const canvas = document.createElement('canvas');
				const context = canvas.getContext('2d');
				context.font = style.font;
				const textWidth = context.measureText(element.value).width;
				const availableWidth = element.clientWidth
					- parseFloat(style.paddingLeft)
					- parseFloat(style.paddingRight);
				return { textWidth, availableWidth };
			});
			expect(textFit.availableWidth).toBeGreaterThanOrEqual(textFit.textWidth);
		}

		await page.locator('#search-box').screenshot({
			path: path.join(SCREENSHOT_DIR, 'pr-298-mobile-375-search.png'),
		});
	});

	test('768pxでは2カラムを維持し、発行日の「～」が折り返されない', async ({ page }) => {
		await page.setViewportSize({ width: 768, height: 1000 });
		await page.goto('/');
		await page.locator('#start_date').fill('20260730');
		await page.locator('#end_date').fill('20260730');

		const filterItems = await page.locator('.search-box dl').evaluateAll((elements) =>
			elements.map((element) => element.getBoundingClientRect().toJSON())
		);
		// 1・2項目、3・4項目がそれぞれ同じ行にあることを確認する。
		expect(Math.abs(filterItems[0].top - filterItems[1].top)).toBeLessThanOrEqual(1);
		expect(Math.abs(filterItems[2].top - filterItems[3].top)).toBeLessThanOrEqual(1);
		expect(filterItems[1].left).toBeGreaterThan(filterItems[0].left);

		const dateLayout = await page.locator('.search-date dd').evaluate((element) => {
			const inputs = element.querySelectorAll('input');
			const start = inputs[0].getBoundingClientRect();
			const end = inputs[1].getBoundingClientRect();
			const separatorNode = Array.from(element.childNodes)
				.find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.includes('～'));
			const range = document.createRange();
			range.selectNode(separatorNode);
			const separator = range.getBoundingClientRect();
			return {
				start: start.toJSON(),
				end: end.toJSON(),
				separator: separator.toJSON(),
			};
		});

		// 2つの日付欄と区切り記号の縦位置が重なっていれば、記号だけの段落ちはない。
		expect(Math.abs(dateLayout.start.top - dateLayout.end.top)).toBeLessThanOrEqual(1);
		expect(dateLayout.separator.top).toBeLessThan(dateLayout.start.bottom);
		expect(dateLayout.separator.bottom).toBeGreaterThan(dateLayout.start.top);

		await page.locator('#search-box').screenshot({
			path: path.join(SCREENSHOT_DIR, 'pr-298-tablet-768-search.png'),
		});
	});

	test('PC表示で項目間12px・注記とボタン間21pxの余白になる', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 1200 });
		await page.goto('/');

		const dateItem = await page.locator('.search-date').boundingBox();
		const note = await page.locator('.search-box .help-block').boundingBox();
		const submitButton = await page.getByRole('button', { name: /絞り込み/ }).boundingBox();
		expect(dateItem).not.toBeNull();
		expect(note).not.toBeNull();
		expect(submitButton).not.toBeNull();

		expect(Math.abs(note.y - (dateItem.y + dateItem.height) - 12)).toBeLessThanOrEqual(1);
		expect(Math.abs(submitButton.y - (note.y + note.height) - 21)).toBeLessThanOrEqual(1);

		await page.screenshot({
			path: path.join(SCREENSHOT_DIR, 'pr-298-desktop-1280-full.png'),
			fullPage: true,
		});
	});
});
