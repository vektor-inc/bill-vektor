// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
const { requireTestDataPresent } = require('./require-test-data');

/**
 * PR #297 見積書一覧「取引先」列の UI / e2e テスト。
 *
 * 既知の投稿 ID には依存せず、管理画面に表示される見積書タイトルから
 * 対象行を特定する。
 *
 * 実行前に、参照する見積書・取引先を作成しておく必要がある。
 * どちらもテーマのディレクトリ（このリポジトリのルート）で実行する。
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-297.php
 *   npx playwright test tests/e2e/pr-297-estimate-client-column.spec.js
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 *
 * wp-env のポートを既定（8895）から変えている場合は WP_BASE_URL で指定する。
 *   WP_BASE_URL=http://localhost:9112 npx playwright test tests/e2e/pr-297-estimate-client-column.spec.js
 *
 * データ作成スクリプトは同じタイトルの見積書が既にあれば作成をスキップするため、
 * 何度実行しても同じタイトルの行が増えない。
 *
 * bill-vektor テーマはログインが必要なため、
 * global-setup.js で取得したログイン済み storageState を使い回す。
 */

test.use({ storageState: 'tests/e2e/.auth-state.json' });

const LIST_PATH = '/wp-admin/edit.php?post_type=estimate';

// データ作成スクリプト未実行の環境で、各テストが30秒タイムアウトを積み重ねて
// 落ちるのを防ぐため、前提データ（見積書）が1件でも存在するかを先に確認する。
const SETUP_HINT =
	'PR #297 のテストデータを作成してから実行してください:\n' +
	'  npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-297.php';

test.beforeAll(async ({ browser }) => {
	const context = await browser.newContext({
		storageState: 'tests/e2e/.auth-state.json',
	});
	const page = await context.newPage();
	try {
		await requireTestDataPresent(
			page,
			LIST_PATH,
			(p) => estimateRow(p, 'Webサイト制作見積（登録済取引先）'),
			'PR #297 の見積書「Webサイト制作見積（登録済取引先）」',
			SETUP_HINT
		);
	} finally {
		await context.close();
	}
});

/**
 * 後片付けの削除ループの反復上限。
 *
 * nonce 切れなどで削除が成立しない場合に、テストのタイムアウトではなく
 * 「削除できていない」と分かるエラーで止めるための保険。
 */
const MAX_CLEANUP_ITERATIONS = 20;

/**
 * 取引先列に指定している幅（assets/_scss/admin-style.scss の 26%）。
 *
 * ブラウザの丸めや管理画面側の余白で厳密に一致しないため、前後に許容幅を取る。
 * SCSS の値を変えたときは、この基準値も合わせて更新する。
 */
const CLIENT_COLUMN_WIDTH_RATIO = 0.26;
const CLIENT_COLUMN_WIDTH_TOLERANCE = 0.02;

/**
 * 見積書タイトルから一覧の行を取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function estimateRow(page, title) {
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
 * 指定タイトルのテスト投稿をゴミ箱から完全削除する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} postType
 * @param {string} titlePrefix
 */
async function permanentlyDeleteTestPosts(page, postType, titlePrefix) {
	const typeQuery = postType === 'post' ? '' : `&post_type=${postType}`;
	const trashPath = `/wp-admin/edit.php?post_status=trash${typeQuery}`;
	for (let iteration = 0; iteration < MAX_CLEANUP_ITERATIONS; iteration += 1) {
		// 1件削除すると通常一覧へリダイレクトされるため、毎回ゴミ箱を開き直す。
		await page.goto(trashPath);
		const rows = page.locator('#the-list tr').filter({
			// ゴミ箱ではタイトルがリンクではなく strong > span になる。
			has: page.locator('.column-title strong', { hasText: titlePrefix }),
		});
		if (!(await rows.count())) return;
		const deleteHref = await rows.first().locator('.submitdelete').getAttribute('href');
		if (!deleteHref) throw new Error('テスト投稿の完全削除 URL を取得できません。');
		await page.goto(deleteHref);
	}
	// 上限まで回っても残っている場合は、削除が成立していないことを明示して止める。
	throw new Error(
		`テスト投稿の完全削除が ${MAX_CLEANUP_ITERATIONS} 回で終わりませんでした（${postType} / ${titlePrefix}）。`
	);
}

/**
 * 公開状態に残った一時テスト投稿を UI のゴミ箱リンクで片付ける。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} postType
 * @param {string} titlePrefix
 */
async function moveTestPostsToTrash(page, postType, titlePrefix) {
	const typeQuery = postType === 'post' ? '' : `?post_type=${postType}`;
	await page.goto(`/wp-admin/edit.php${typeQuery}`);
	const rows = page.locator('#the-list tr').filter({
		has: page.locator('.column-title .row-title', { hasText: titlePrefix }),
	});
	for (let iteration = 0; iteration < MAX_CLEANUP_ITERATIONS; iteration += 1) {
		if (!(await rows.count())) return;
		const trashHref = await rows.first().locator('.submitdelete').getAttribute('href');
		if (!trashHref) throw new Error('テスト投稿のゴミ箱 URL を取得できません。');
		await page.goto(trashHref);
	}
	// 上限まで回っても残っている場合は、ゴミ箱への移動が成立していないことを明示して止める。
	throw new Error(
		`テスト投稿のゴミ箱への移動が ${MAX_CLEANUP_ITERATIONS} 回で終わりませんでした（${postType} / ${titlePrefix}）。`
	);
}

test.describe('PR #297: 見積書一覧の取引先列', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(LIST_PATH);
		await page.waitForLoadState('networkidle');

		// 前回の中断などでユーザー設定に列非表示が残っていても、検証条件を揃える。
		const option = page.locator('#bill_client_name-hide');
		if (await option.count() && !(await option.isChecked())) {
			await page.locator('#show-settings-link').click();
			await option.check();
			await expect(page.locator('#bill_client_name')).toBeVisible();
		}
	});

	test('タイトル直後に列があり、各入力パターンを仕様どおり表示する', async ({ page }) => {
		// サイト言語に依存しないカラムキーで「タイトル」「取引先」「日付」の順を確認する。
		const headers = await page.locator('.wp-list-table thead th:visible').evaluateAll((elements) =>
			elements.map((element) => {
				if (element.classList.contains('column-title')) return 'title';
				if (element.classList.contains('column-bill_client_name')) return 'bill_client_name';
				if (element.classList.contains('column-date')) return 'date';
				return '';
			}).filter(Boolean)
		);
		const titleIndex = headers.indexOf('title');
		expect(titleIndex).toBeGreaterThanOrEqual(0);
		expect(headers.slice(titleIndex, titleIndex + 3)).toEqual(['title', 'bill_client_name', 'date']);

		const cases = [
			['Webサイト制作見積（登録済取引先）', '株式会社ベクトル'],
			['単発ロゴ制作見積（イレギュラー）', '個人事業主 山田太郎'],
			['両方入力した見積', '手入力を優先する取引先'],
			['新規オフィス移転に伴う社内ネットワーク再構築およびセキュリティ強化対応一式のお見積', '株式会社グローバルテクノロジーソリューションズジャパンホールディングス東日本第二営業部'],
			['英字取引先テスト', 'InternationalBusinessMachinesCorporationJapanBranchOffice'],
		];

		for (const [title, client] of cases) {
			const cell = estimateRow(page, title).locator('.column-bill_client_name');
			await expect(cell).toHaveText(client);
			// 仕様どおり、取引先名にはリンクを付けない。
			await expect(cell.locator('a')).toHaveCount(0);
		}

		// 未設定と無題の登録済取引先は、どちらもダッシュ＋読み上げ文言になること。
		for (const title of ['取引先未設定の見積', '名称未設定の取引先を選んだ見積']) {
			const cell = estimateRow(page, title).locator('.column-bill_client_name');
			await expect(cell.locator('[aria-hidden="true"]')).toHaveText('—');
			await expect(cell.locator('.screen-reader-text')).toHaveText('取引先なし');
			// 書類自身の件名が取引先名として漏れていないこと。
			expect((await cell.textContent())?.replace('取引先なし', '').trim()).toBe('—');
		}

		// CSS の列幅指定と、テーマバージョン付き URL をブラウザ上の実値で確認する。
		const width = await page.locator('#bill_client_name').evaluate((element) =>
			getComputedStyle(element).width
		);
		expect(parseFloat(width)).toBeGreaterThan(0);
		const tableWidth = await page.locator('.wp-list-table').evaluate((element) => element.getBoundingClientRect().width);
		// SCSS の指定（26%）どおりの比率になっていること。前後 2 ポイントを許容する。
		expect(parseFloat(width) / tableWidth).toBeGreaterThan(CLIENT_COLUMN_WIDTH_RATIO - CLIENT_COLUMN_WIDTH_TOLERANCE);
		expect(parseFloat(width) / tableWidth).toBeLessThan(CLIENT_COLUMN_WIDTH_RATIO + CLIENT_COLUMN_WIDTH_TOLERANCE);

		/*
		 * バージョンのクエリ文字列が付いていることだけを確認する。
		 * バージョン番号を固定すると、テーマのバージョンを上げただけで
		 * 機能と無関係にこのテストが落ちるため、値は問わない。
		 */
		const styleHref = await page.locator('link[href*="/assets/css/admin-style.css"]').getAttribute('href');
		expect(styleHref).toMatch(/admin-style\.css\?ver=\d+\.\d+/);
	});

	test('指定幅で崩れず、モバイルでは取引先ラベル付きの積み上げ表示になる', async ({ page }) => {
		const widths = [1600, 1280, 1024, 782, 400];

		for (const width of widths) {
			await page.setViewportSize({ width, height: 1000 });
			await page.goto(LIST_PATH);
			await page.waitForLoadState('networkidle');

			// ページ全体に意図しない横スクロールが発生していないこと。
			const dimensions = await page.evaluate(() => ({
				scrollWidth: document.documentElement.scrollWidth,
				clientWidth: document.documentElement.clientWidth,
			}));
			expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth + 1);

			if (width === 1280) {
				const longJapaneseRow = estimateRow(
					page,
					'新規オフィス移転に伴う社内ネットワーク再構築およびセキュリティ強化対応一式のお見積'
				);
				// 日本語42文字の取引先名は2行以内、長い件名は1行を維持すること。
				expect(await renderedLineCount(longJapaneseRow.locator('.column-bill_client_name'))).toBeLessThanOrEqual(2);
				expect(await renderedLineCount(longJapaneseRow.locator('.column-title .row-title'))).toBe(1);

				// 英字1語の長い取引先名もセル外へはみ出さないこと。
				const englishCell = estimateRow(page, '英字取引先テスト').locator('.column-bill_client_name');
				const overflow = await englishCell.evaluate((element) => element.scrollWidth - element.clientWidth);
				expect(overflow).toBeLessThanOrEqual(1);
			}

			if (width <= 782) {
				const row = estimateRow(page, 'Webサイト制作見積（登録済取引先）');
				const toggle = row.locator('.toggle-row');
				await expect(toggle).toBeVisible();
				await toggle.click();
				const clientCell = row.locator('.column-bill_client_name');
				await expect(clientCell).toBeVisible();
				await expect(clientCell).toHaveAttribute('data-colname', '取引先');
				await expect(clientCell).toContainText('株式会社ベクトル');
			}
		}
	});

	test('表示オプションで列を切り替えられ、クイック編集でも崩れない', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 1000 });

		await page.locator('#show-settings-link').click();
		const option = page.locator('#bill_client_name-hide');
		await expect(option).toBeVisible();
		await expect(option).toBeChecked();

		// チェックを外すとヘッダー・各セルが非表示になること。
		try {
			await option.uncheck();
			await expect(page.locator('#bill_client_name')).toBeHidden();
			await expect(page.locator('td.column-bill_client_name').first()).toBeHidden();
		} finally {
			// 途中でアサーションが失敗しても、後続テストへ非表示設定を残さない。
			if (!(await option.isChecked())) {
				await option.check();
			}
		}
		await expect(page.locator('#bill_client_name')).toBeVisible();

		const row = estimateRow(page, '単発ロゴ制作見積（イレギュラー）');
		// WordPress の行アクションは視覚上表示されていても Playwright が画面外と
		// 判定することがあるため、実際のクリックと同じ click イベントを送る。
		await row.locator('.editinline').dispatchEvent('click');
		const inlineEditor = page.locator('#the-list tr.inline-edit-row');
		await expect(inlineEditor).toBeVisible();
		const editorBox = await inlineEditor.boundingBox();
		const tableBox = await page.locator('.wp-list-table').boundingBox();
		expect(editorBox).not.toBeNull();
		expect(tableBox).not.toBeNull();
		expect(editorBox.width).toBeLessThanOrEqual(tableBox.width + 1);
		await inlineEditor.locator('.cancel').dispatchEvent('click');
	});

	test('編集画面で既存の取引先値を保持したまま保存できる', async ({ page }) => {
		test.setTimeout(60000);
		const row = estimateRow(page, '単発ロゴ制作見積（イレギュラー）');
		await row.locator('.row-title').click();
		await expect(page.locator('#bill_client_name_manual')).toHaveValue('個人事業主 山田太郎');

		// 同じ値のまま更新し、入力値が消えたり書き換わったりしないことを確認する。
		// 更新ボタンのラベルはサイト言語で変わるため、英語・日本語の両方を受ける。
		const update = page.getByRole('button', { name: /^(Update|更新)$/ });
		await update.dispatchEvent('click');
		await expect(page.locator('#message')).toContainText(/更新しました|Post updated/);
		await expect(page.locator('#bill_client_name_manual')).toHaveValue('個人事業主 山田太郎');
	});

	test('HTMLタグを含む取引先名をテキストとして表示し、テスト投稿を片付ける', async ({ page }) => {
		test.setTimeout(60000);
		const suffix = `${Date.now()}`;
		const title = `PR297 HTMLエスケープ確認 ${suffix}`;
		const payload = '<script>window.__pr297_xss = 1</script>';

		try {
			// 中断した前回実行の同名テスト投稿がゴミ箱にあれば、UIから先に完全削除する。
			await permanentlyDeleteTestPosts(page, 'estimate', 'PR297 HTMLエスケープ確認');

			await page.goto('/wp-admin/post-new.php?post_type=estimate');
			await page.locator('#title').fill(title);
			await page.locator('#bill_client_name_manual').fill(payload);
			await page.locator('#publish').dispatchEvent('click');
			await expect(page.locator('#message')).toContainText(/公開しました|更新しました|Post published|Post updated/);

			await page.goto(LIST_PATH);
			const row = estimateRow(page, title);
			await expect(row).toHaveCount(1);
			const cell = row.locator('.column-bill_client_name');
			await expect(cell).toContainText(payload);
			await expect(cell.locator('script')).toHaveCount(0);
			expect(await page.evaluate(() => globalThis.__pr297_xss)).toBeUndefined();
		} finally {
			// 作成した検証投稿は UI からゴミ箱へ移し、既存データを汚さない。
			await page.goto(LIST_PATH);
			const row = estimateRow(page, title);
			if (await row.count()) {
				const trashHref = await row.locator('.submitdelete').getAttribute('href');
				if (trashHref) await page.goto(trashHref);
			}
			await permanentlyDeleteTestPosts(page, 'estimate', 'PR297 HTMLエスケープ確認');
		}
	});

	test('請求書一覧にも取引先列があり、CSVエクスポート操作も応答する', async ({ page }) => {
		test.setTimeout(60000);
		await page.goto('/wp-admin/edit.php');
		await page.waitForLoadState('networkidle');
		/*
		 * #297 の時点では請求書一覧に取引先列が無いことを回帰として固定していたが、
		 * PR #326 で請求書一覧にも同じ列を追加したため、あることの確認に変更した。
		 * 一覧の上下にヘッダー行（thead / tfoot）があるため2件になる。
		 */
		await expect(page.locator('th.column-bill_client_name')).toHaveCount(2);
		await expect(page.locator('.wp-list-table thead')).toContainText('取引先');

		const suffix = `${Date.now()}`;
		const title = `PR297 CSV回帰確認 ${suffix}`;
		const client = `PR297 CSV取引先 ${suffix}`;

		try {
			// 前回中断分があれば、同じブラウザ UI から完全に片付ける。
			await moveTestPostsToTrash(page, 'post', 'PR297 CSV回帰確認');
			await permanentlyDeleteTestPosts(page, 'post', 'PR297 CSV回帰確認');

			// ブラウザから一時的な請求書を作り、CSV内の実データまで確認する。
			await page.goto('/wp-admin/post-new.php');
			await page.locator('#title').fill(title);
			await page.locator('#bill_client_name_manual').fill(client);
			await page.locator('input[name="bill_items[0][name]"]').fill('CSV確認品目');
			await page.locator('input[name="bill_items[0][count]"]').fill('1');
			await page.locator('input[name="bill_items[0][price]"]').fill('1000');
			await page.locator('#publish').dispatchEvent('click');
			await expect(page.locator('#message')).toContainText(/公開しました|Post published/);

			// 従来画面から freee CSV をブラウザ操作でダウンロードでき、
			// ヘッダーと一時請求書の取引先名が出力されること。
			await page.goto('/');
			const exportButton = page.getByRole('button', { name: /freee用CSVエクスポート/ });
			await expect(exportButton).toBeVisible();
			await expect(exportButton).toHaveAttribute('name', 'action');
			await expect(exportButton).toHaveAttribute('value', 'csv_freee');

			// CSV エクスポートは nonce 必須のため、エクスポートフォームの
			// hidden フィールドから _wpnonce の値を取得する（issue #299 の対応）。
			const nonce = await page
				.locator('.export-box input[name="_wpnonce"]')
				.first()
				.inputValue();
			expect(nonce).toBeTruthy();

			// Playwright の同一ブラウザコンテキストから、ボタンと同じ GET を送り
			// Content-Disposition と実際の CSV 本文を検証する。
			const response = await page.request.get(
				`/?action=csv_freee&_wpnonce=${encodeURIComponent(nonce)}`
			);
			expect(response.ok()).toBe(true);
			expect(response.headers()['content-disposition']).toContain('export.csv');
			const csv = await response.text();
			expect(csv).toContain('"取引先"');
			expect(csv).toContain(`"${client}"`);
		} finally {
			// 作成した請求書を一覧からゴミ箱へ移し、さらに完全削除する。
			await moveTestPostsToTrash(page, 'post', 'PR297 CSV回帰確認');
			await permanentlyDeleteTestPosts(page, 'post', 'PR297 CSV回帰確認');
		}
	});
});
