// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
// eslint-disable-next-line @typescript-eslint/no-var-requires
const fs = require('fs');
// eslint-disable-next-line @typescript-eslint/no-var-requires
const path = require('path');
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { execFileSync } = require('child_process');

/**
 * PR #326 請求書一覧「取引先」列の追加と、不正値で無関係な投稿のタイトルが
 * 表示される不具合の修正を確認する UI / e2e テスト。
 *
 * 投稿 ID には依存せず、管理画面に表示されるタイトルから対象行を特定する。
 *
 * 実行前に確認用データを作成しておく（テーマのディレクトリで実行する）。
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-326.php
 *   WP_BASE_URL=http://localhost:9146 npx playwright test tests/e2e/pr-326-invoice-client-column.spec.js
 *
 * 列幅の実測値は tests/e2e/.pr-326-metrics-<branch>.json に書き出す。
 * master と PR ブランチの両方で実行して差分を比べ、見積書一覧が変わっていないことを確認する。
 * 書き出し先は環境変数 PR326_METRICS_FILE で指定する。
 */

test.use({ storageState: 'tests/e2e/.auth-state.json' });

const ESTIMATE_LIST = '/wp-admin/edit.php?post_type=estimate';

/*
 * 不正値の確認用データだけを対象にした一覧。
 * 他の e2e テストのデータも同じ DB に入るためページ送りで対象行が落ちうる。
 * 列構成・列幅は絞り込みの有無で変わらないので、件名で絞り込んだ一覧を使う。
 */
const INVOICE_LIST_PR326 = '/wp-admin/edit.php?s=PR326';
const ESTIMATE_LIST_PR326 = '/wp-admin/edit.php?post_type=estimate&s=PR326';

/**
 * issue #296 の確認用データだけに絞った請求書一覧の URL を返す。
 *
 * 確認用データの請求書にはカテゴリー「請求書テスト」が付いており、他の e2e テストの
 * データには付かない。カテゴリーのターム ID は環境ごとに変わるため、一覧画面の
 * 絞り込みプルダウンから値を読み取って組み立てる。
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<string>} 絞り込み済みの請求書一覧 URL。
 */
let invoiceListUrlCache = '';
async function invoiceListUrl(page) {
	if (invoiceListUrlCache) return invoiceListUrlCache;

	await page.goto('/wp-admin/edit.php');
	const categoryValue = await page
		.locator('#cat option', { hasText: '請求書テスト' })
		.first()
		.getAttribute('value');
	expect(categoryValue, '確認用データのカテゴリー「請求書テスト」が見つかりません').toBeTruthy();
	invoiceListUrlCache = `/wp-admin/edit.php?cat=${categoryValue}`;

	return invoiceListUrlCache;
}

// 取引先名として絶対に表示されてはいけない非公開ページのタイトル
const SECRET_PAGE_TITLE = 'PR326 機密の非公開ページ';

// 確認する画面幅（PR 本文の確認手順 B に対応）
const VIEWPORT_WIDTHS = [1024, 1280, 1440, 1920];

/**
 * 不正値のフィクスチャを作り直す。
 *
 * C2 では管理画面から取引先を正しい値に選び直すため、そのままだと不正値が残らず
 * 再実行時に C が成立しなくなる。テスト側でデータ作成スクリプトを流し直して
 * 何度実行しても同じ条件になるようにする。
 * テーマのディレクトリ名は worktree で変わるため、カレントディレクトリ名から求める。
 */
function restoreInvalidClientFixtures() {
	const themeDir = path.basename(process.cwd());
	execFileSync(
		'npx',
		[
			'wp-env',
			'run',
			'cli',
			`--env-cwd=wp-content/themes/${themeDir}`,
			'wp',
			'eval-file',
			'tests/e2e/create-test-data-pr-326.php',
		],
		{ stdio: 'ignore' }
	);
}

/**
 * 一覧の行を書類タイトルから取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function listRow(page, title) {
	return page.locator('#the-list tr').filter({
		has: page.locator('.column-title .row-title', { hasText: title }),
	});
}

/**
 * 要素内のテキストが実際に何行で描画されているかを数える。
 *
 * @param {import('@playwright/test').Locator} locator
 */
async function renderedLineCount(locator) {
	return locator.evaluate((element) => {
		const range = document.createRange();
		range.selectNodeContents(element);
		const tops = Array.from(range.getClientRects()).map((rect) => Math.round(rect.top));
		return new Set(tops).size;
	});
}

/**
 * 一覧テーブルの各列の実測幅（px）を列キーごとに取得する。
 *
 * @param {import('@playwright/test').Page} page
 */
async function columnWidths(page) {
	// チェックボックス列は th ではなく td で出力されるため td も含める
	return page.locator('.wp-list-table thead th, .wp-list-table thead td').evaluateAll((elements) => {
		/** @type {Record<string, number>} */
		const widths = {};
		elements.forEach((element) => {
			if (element.offsetParent === null && getComputedStyle(element).display === 'none') return;
			const key = element.id || element.className;
			widths[key] = Math.round(element.getBoundingClientRect().width);
		});
		return widths;
	});
}

// 計測結果の書き出し用（テスト間で共有する）
/** @type {Record<string, Record<number, Record<string, number>>>} */
const metrics = {};

test.afterAll(() => {
	const file = process.env.PR326_METRICS_FILE;
	if (file && Object.keys(metrics).length) {
		fs.writeFileSync(file, JSON.stringify(metrics, null, '\t'));
	}
});

test.describe('PR #326: 請求書一覧の取引先列', () => {
	test('A. 請求書一覧の件名直後に取引先列があり、各入力パターンを仕様どおり表示する', async ({ page }) => {
		await page.setViewportSize({ width: 1440, height: 1000 });
		await page.goto(await invoiceListUrl(page));
		await page.waitForLoadState('networkidle');

		// 表示オプションで取引先列が非表示に残っていた場合は戻す（検証条件を揃える）
		const option = page.locator('#bill_client_name-hide');
		if ((await option.count()) && !(await option.isChecked())) {
			await page.locator('#show-settings-link').click();
			await option.check();
			await expect(page.locator('#bill_client_name')).toBeVisible();
		}

		// サイト言語に依存しないカラムキーで、件名 → 取引先 → 作成者 の順を確認する
		const headers = await page.locator('.wp-list-table thead th:visible').evaluateAll((elements) =>
			elements
				.map((element) => {
					if (element.classList.contains('column-title')) return 'title';
					if (element.classList.contains('column-bill_client_name')) return 'bill_client_name';
					if (element.classList.contains('column-author')) return 'author';
					return '';
				})
				.filter(Boolean)
		);
		const titleIndex = headers.indexOf('title');
		expect(titleIndex).toBeGreaterThanOrEqual(0);
		// 件名の直後が取引先、その次が作成者（＝作成者より前にある）
		expect(headers.slice(titleIndex, titleIndex + 3)).toEqual(['title', 'bill_client_name', 'author']);

		// ヘッダーのラベルが「取引先」であること
		await expect(page.locator('#bill_client_name')).toHaveText('取引先');

		// 取引先（登録済）・取引先（イレギュラー）・両方入力の表示
		const cases = [
			['Web制作請求（登録済取引先）', '株式会社ベクトル'],
			['単発ロゴ制作請求（イレギュラー）', '個人事業主 山田太郎'],
			['両方入力した請求書', '手入力を優先する取引先'],
		];

		for (const [title, client] of cases) {
			const cell = listRow(page, title).locator('.column-bill_client_name');
			await expect(cell).toHaveText(client);
			// 仕様どおり、取引先名にはリンクを付けない
			await expect(cell.locator('a')).toHaveCount(0);
		}

		// 未設定・無題の取引先はダッシュ＋読み上げ文言。件名が代わりに出ていないこと
		for (const title of ['取引先未設定の請求書', '名称未設定の取引先を選んだ請求書']) {
			const cell = listRow(page, title).locator('.column-bill_client_name');
			await expect(cell.locator('[aria-hidden="true"]')).toHaveText('—');
			await expect(cell.locator('.screen-reader-text')).toHaveText('取引先なし');
			expect((await cell.textContent())?.replace('取引先なし', '').trim()).toBe('—');
		}
	});

	test('A5. 見積書一覧は従来どおり取引先列を表示する', async ({ page }) => {
		await page.setViewportSize({ width: 1440, height: 1000 });
		await page.goto(ESTIMATE_LIST);
		await page.waitForLoadState('networkidle');

		const headers = await page.locator('.wp-list-table thead th:visible').evaluateAll((elements) =>
			elements
				.map((element) => {
					if (element.classList.contains('column-title')) return 'title';
					if (element.classList.contains('column-bill_client_name')) return 'bill_client_name';
					if (element.classList.contains('column-date')) return 'date';
					return '';
				})
				.filter(Boolean)
		);
		const titleIndex = headers.indexOf('title');
		expect(headers.slice(titleIndex, titleIndex + 2)).toEqual(['title', 'bill_client_name']);

		// 見積書側の取引先列は SCSS の 26% 指定が効いたままであること
		const width = await page.locator('#bill_client_name').evaluate((element) => element.getBoundingClientRect().width);
		const tableWidth = await page.locator('.wp-list-table').evaluate((element) => element.getBoundingClientRect().width);
		expect(width / tableWidth).toBeGreaterThan(0.24);
		expect(width / tableWidth).toBeLessThan(0.28);
	});

	test('B. 1024〜1920px のいずれでも件名と取引先が読める', async ({ page }) => {
		test.setTimeout(90000);
		metrics.invoice = {};

		for (const width of VIEWPORT_WIDTHS) {
			await page.setViewportSize({ width, height: 1000 });
			await page.goto(await invoiceListUrl(page));
			await page.waitForLoadState('networkidle');

			metrics.invoice[width] = await columnWidths(page);

			// ページ全体に意図しない横スクロールが出ていないこと
			const dimensions = await page.evaluate(() => ({
				scrollWidth: document.documentElement.scrollWidth,
				clientWidth: document.documentElement.clientWidth,
			}));
			expect(dimensions.scrollWidth, `${width}px で横スクロールが発生`).toBeLessThanOrEqual(dimensions.clientWidth + 1);

			// 件名列・取引先列がどちらも実用的な幅を持つこと（片方が潰れていないこと）
			const titleWidth = metrics.invoice[width]['column-title'] ?? metrics.invoice[width].title;
			const clientWidth = metrics.invoice[width].bill_client_name;
			expect(titleWidth, `${width}px の件名列が狭すぎる`).toBeGreaterThan(60);
			expect(clientWidth, `${width}px の取引先列が狭すぎる`).toBeGreaterThan(60);

			// 長い件名が1文字ずつ折り返していないこと（＝行数が文字数に近づいていないこと）
			const longRow = listRow(page, '新規オフィス移転に伴う社内ネットワーク再構築およびセキュリティ強化対応一式のご請求');
			const titleLines = await renderedLineCount(longRow.locator('.column-title .row-title'));
			expect(titleLines, `${width}px で件名が ${titleLines} 行に折り返している`).toBeLessThanOrEqual(8);

			// 英字1語の長い取引先名がセルからはみ出していないこと
			const englishCell = listRow(page, '英字取引先テスト（請求書）').locator('.column-bill_client_name');
			const overflow = await englishCell.evaluate((element) => element.scrollWidth - element.clientWidth);
			expect(overflow, `${width}px で取引先名がセルからはみ出している`).toBeLessThanOrEqual(1);
		}
	});

	test('B2. 見積書一覧の列幅を各画面幅で計測する', async ({ page }) => {
		test.setTimeout(90000);
		metrics.estimate = {};

		for (const width of VIEWPORT_WIDTHS) {
			await page.setViewportSize({ width, height: 1000 });
			await page.goto(ESTIMATE_LIST);
			await page.waitForLoadState('networkidle');

			metrics.estimate[width] = await columnWidths(page);

			const dimensions = await page.evaluate(() => ({
				scrollWidth: document.documentElement.scrollWidth,
				clientWidth: document.documentElement.clientWidth,
			}));
			expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth + 1);
		}
	});

	test('C. 不正な値の書類に非公開ページのタイトルが出ない', async ({ page }) => {
		test.setTimeout(120000);
		await page.setViewportSize({ width: 1440, height: 1000 });

		const invoices = [
			'PR326 不正値マイナス（請求書）',
			'PR326 不正値数字以外（請求書）',
			'PR326 不正値ページID（請求書）',
		];
		const estimates = [
			'PR326 不正値マイナス（見積書）',
			'PR326 不正値数字以外（見積書）',
			'PR326 不正値ページID（見積書）',
		];

		// 1. 請求書一覧・見積書一覧の取引先列
		for (const [listPath, titles] of [
			[INVOICE_LIST_PR326, invoices],
			[ESTIMATE_LIST_PR326, estimates],
		]) {
			await page.goto(/** @type {string} */ (listPath));
			await page.waitForLoadState('networkidle');
			// 一覧のどこにも非公開ページのタイトルが出ていないこと
			await expect(page.locator('.wp-list-table')).not.toContainText(SECRET_PAGE_TITLE);

			for (const title of /** @type {string[]} */ (titles)) {
				const cell = listRow(page, title).locator('.column-bill_client_name');
				await expect(cell, `${title} の取引先セル`).not.toContainText(SECRET_PAGE_TITLE);
				await expect(cell.locator('[aria-hidden="true"]')).toHaveText('—');
				await expect(cell.locator('.screen-reader-text')).toHaveText('取引先なし');
			}
		}

		// 2. 書類本体（プレビュー）と PDF のファイル名になる document title
		for (const [listPath, titles] of [
			[INVOICE_LIST_PR326, invoices],
			[ESTIMATE_LIST_PR326, estimates],
		]) {
			for (const title of /** @type {string[]} */ (titles)) {
				await page.goto(/** @type {string} */ (listPath));
				const viewHref = await listRow(page, title).locator('.row-title').getAttribute('href');
				expect(viewHref).toBeTruthy();
				// 編集画面の URL から投稿 ID を取り出し、書類本体（フロント）を開く
				const postId = new URL(/** @type {string} */ (viewHref), page.url()).searchParams.get('post');
				expect(postId).toBeTruthy();
				await page.goto(`/?p=${postId}`);
				await page.waitForLoadState('domcontentloaded');

				// 書類本体に非公開ページのタイトルが出ていないこと
				await expect(page.locator('body')).not.toContainText(SECRET_PAGE_TITLE);
				// PDF のファイル名になる document title にも出ていないこと
				expect(await page.title(), `${title} の document title`).not.toContain(SECRET_PAGE_TITLE);
			}
		}

		// 3. フロント側の書類一覧
		// issue #322 レビュー指摘: 絞り込み無しのフロント一覧は10件/ページで、
		// PR326の不正値書類6件は作成時点の日付が古く post 37件・estimate 18件の
		// 後ろへ押し出され1ページ目に存在しない。対象が写っていない画面に対して
		// 「漏れていないこと」を確認しても、否定アサーションが空振りで成立するだけで
		// 検証になっていない。件名（bill_keyword）で絞り込み、対象が実際にこの画面に
		// 写っていることを保証してから漏れの有無を検証する。
		for (const postType of ['post', 'estimate']) {
			await page.goto(`/?post_type=${postType}&bill_keyword=PR326&action=send`);
			await page.waitForLoadState('networkidle');
			// 否定検証の前に、対象がこの画面に存在することを保証する
			await expect(page.locator('table.table tr').filter({ hasText: 'PR326 不正値' })).toHaveCount(3);
			await expect(page.locator('body')).not.toContainText(SECRET_PAGE_TITLE);
		}

		// 4. CSV エクスポートにも出ていないこと
		const nonce = await page.locator('.export-box input[name="_wpnonce"]').first().inputValue();
		expect(nonce).toBeTruthy();
		const response = await page.request.get(`/?action=csv_freee&_wpnonce=${encodeURIComponent(nonce)}`);
		expect(response.ok()).toBe(true);
		const csv = await response.text();
		expect(csv).not.toContain(SECRET_PAGE_TITLE);
	});

	test('C2. 正しい取引先に戻すと従来どおり表示される', async ({ page }) => {
		test.setTimeout(120000);
		const title = 'PR326 不正値マイナス（請求書）';

		try {
			await page.goto(INVOICE_LIST_PR326);
			const editHref = await listRow(page, title).locator('.row-title').getAttribute('href');
			expect(editHref).toBeTruthy();
			await page.goto(/** @type {string} */ (editHref));
			await page.waitForLoadState('networkidle');

			// 取引先（登録済）で「株式会社ベクトル」を選び直す
			const select = page.locator('#bill_client');
			await expect(select).toBeVisible();
			await select.selectOption({ label: '株式会社ベクトル' });
			await page.getByRole('button', { name: /^(Update|更新)$/ }).dispatchEvent('click');
			await expect(page.locator('#message')).toContainText(/更新しました|Post updated/);

			// 一覧に取引先名が表示されること
			await page.goto(INVOICE_LIST_PR326);
			await expect(listRow(page, title).locator('.column-bill_client_name')).toHaveText('株式会社ベクトル');

			// 書類本体・PDF のファイル名にも取引先名が入ること
			const postId = new URL(/** @type {string} */ (editHref), page.url()).searchParams.get('post');
			await page.goto(`/?p=${postId}`);
			await expect(page.locator('body')).toContainText('株式会社ベクトル');
			expect(await page.title()).toContain('株式会社ベクトル');
		} finally {
			// 不正値の状態に戻す（何度実行しても同じ条件で確認できるようにする）
			restoreInvalidClientFixtures();
		}
	});

	/**
	 * 指定した請求書に品目が無ければ管理画面から入力して保存する。
	 *
	 * CSV エクスポートは税率ごとの明細行として出力されるため、品目が無い書類は
	 * 1行も出力されない。CSV の取引先名を確認するための前提を整える。
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string} title
	 */
	async function ensureInvoiceHasItem(page, title) {
		await page.goto(await invoiceListUrl(page));
		const editHref = await listRow(page, title).locator('.row-title').getAttribute('href');
		expect(editHref).toBeTruthy();
		await page.goto(/** @type {string} */ (editHref));

		const itemName = page.locator('input[name="bill_items[0][name]"]');
		await expect(itemName).toBeVisible();
		if (await itemName.inputValue()) return;

		await itemName.fill('確認用品目');
		await page.locator('input[name="bill_items[0][count]"]').fill('1');
		await page.locator('input[name="bill_items[0][price]"]').fill('10000');
		await page.getByRole('button', { name: /^(Update|更新)$/ }).dispatchEvent('click');
		await expect(page.locator('#message')).toContainText(/更新しました|Post updated/);
	}

	test('D. 既存の取引先表示（書類本体・PDFファイル名・CSV・フロント一覧）が壊れていない', async ({ page }) => {
		test.setTimeout(180000);

		// CSV は品目のある書類だけが出力対象になるため、先に品目を入れておく
		await ensureInvoiceHasItem(page, 'Web制作請求（登録済取引先）');
		await ensureInvoiceHasItem(page, '単発ロゴ制作請求（イレギュラー）');

		// 書類本体と PDF のファイル名
		await page.goto(await invoiceListUrl(page));
		const viewHref = await listRow(page, 'Web制作請求（登録済取引先）')
			.locator('.row-title')
			.getAttribute('href');
		const postId = new URL(/** @type {string} */ (viewHref), page.url()).searchParams.get('post');
		await page.goto(`/?p=${postId}`);
		await expect(page.locator('body')).toContainText('株式会社ベクトル');
		// PDF のファイル名は document title から作られる
		expect(await page.title()).toContain('株式会社ベクトル');

		// 手入力（イレギュラー）の書類も従来どおり
		await page.goto(await invoiceListUrl(page));
		const manualHref = await listRow(page, '単発ロゴ制作請求（イレギュラー）')
			.locator('.row-title')
			.getAttribute('href');
		const manualId = new URL(/** @type {string} */ (manualHref), page.url()).searchParams.get('post');
		await page.goto(`/?p=${manualId}`);
		await expect(page.locator('body')).toContainText('個人事業主 山田太郎');
		expect(await page.title()).toContain('個人事業主 山田太郎');

		/*
		 * フロント側の書類一覧の取引先名とリンク。
		 * 一覧は1ページ分しか出ないため、検索ボックスの取引先で絞り込んでから確認する。
		 */
		await page.goto('/');
		await page.waitForLoadState('networkidle');
		// 検索ボックスの取引先セレクトから、絞り込みフォームが送る値をそのまま使う
		const clientOptionValue = await page
			.locator('.search-box #bill_client option', { hasText: '株式会社ベクトル' })
			.first()
			.getAttribute('value');
		expect(clientOptionValue).toBeTruthy();
		await page.goto(`/?post_type=post&bill_client=${clientOptionValue}&action=send`);
		await page.waitForLoadState('networkidle');
		const frontRow = page.locator('tr').filter({ hasText: 'Web制作請求（登録済取引先）' }).first();
		await expect(frontRow).toContainText('株式会社ベクトル');
		// 取引先名は取引先ページへのリンクになっていること
		const clientLink = frontRow.locator('a', { hasText: '株式会社ベクトル' }).first();
		await expect(clientLink).toHaveAttribute('href', /.+/);

		// CSV エクスポートの取引先名
		const nonce = await page.locator('.export-box input[name="_wpnonce"]').first().inputValue();
		const response = await page.request.get(`/?action=csv_freee&_wpnonce=${encodeURIComponent(nonce)}`);
		expect(response.ok()).toBe(true);
		const csv = await response.text();
		expect(csv).toContain('"取引先"');
		expect(csv).toContain('株式会社ベクトル');
		expect(csv).toContain('個人事業主 山田太郎');
	});
});
