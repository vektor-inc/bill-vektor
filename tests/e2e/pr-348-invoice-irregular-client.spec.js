// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { AUTH_STATE_PATH } = require('./auth-helpers');

/**
 * PR #348 の UI / e2e テスト。
 *
 * 見積書の編集画面にある「この内容で請求書を発行」「件名を品目一式にして請求書を発行」で
 * 請求書を作成すると、見積書の「取引先（イレギュラー）」欄（bill_client_name_manual）の
 * 入力内容が引き継がれず空欄になっていた不具合（issue #347）の修正を確認する。
 * あわせて、書類の複製処理に追加された「複製先の書類の種類を作成できる権限があること」の
 * 確認（PR #348 のセキュリティ修正部分）が、通常の複製操作を壊していないことも確認する。
 *
 * このテストは前提データのスクリプトに依存せず、実行のたびに見積書・取引先を UI から新規作成する
 * （タイトルにタイムスタンプを含めるため、複数回実行しても既存データと衝突しない）。
 * 生成した投稿は afterAll でゴミ箱へ移動する（完全削除はしない。ベストエフォートで、
 * 後始末に失敗してもテスト結果自体は変えない）。
 *
 * 実行コマンド（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 *   WP_BASE_URL=http://localhost:9120 npx playwright test tests/e2e/pr-348-invoice-irregular-client.spec.js
 *
 * スクリーンショットは tests/e2e/screenshots/ 配下に保存する（.gitignore 済み）。
 */

test.use({ storageState: AUTH_STATE_PATH });

// このテスト実行だけで一意になるようにタイムスタンプを含める
const RUN_ID = Date.now();
const ESTIMATE_TITLE = `PR348見積書${RUN_ID}`;
const IRREGULAR_CLIENT_NAME = `PR348株式会社テスト商事${RUN_ID}`;
const ITEM_NAME = `PR348商品A${RUN_ID}`;

const REGISTERED_CLIENT_TITLE = `PR348取引先${RUN_ID}`;
const ESTIMATE_TITLE_REGISTERED = `PR348見積書（登録済取引先）${RUN_ID}`;

// 手順6（一覧の「複製」行アクション）専用のタイトル。
// ESTIMATE_TITLE は手順4で同じタイトルのまま複製されるため、一覧を件名検索すると
// 手順4の複製先まで一致してしまい「行が1件だけ見つかること」の前提が崩れる。
// 手順6の対象は他の手順で複製されない、専用の投稿にする。
const LIST_ESTIMATE_TITLE = `PR348一覧複製確認見積書${RUN_ID}`;
const LIST_INVOICE_TITLE = `PR348一覧複製確認請求書${RUN_ID}`;
const LIST_IRREGULAR_CLIENT_NAME = `PR348一覧複製確認取引先イレギュラー${RUN_ID}`;

const SCREENSHOT_DIR = 'tests/e2e/screenshots';

/**
 * スクリーンショットを撮る（ベストエフォート）。
 *
 * この環境の wp-admin 画面は、ヘッドレス実行時に Playwright の
 * screenshot() が要求する「画面が数フレーム安定する」まで待つ処理が長時間ハングすることがある
 * （管理画面右上のアバター画像がオフライン環境で読み込めず再描画を繰り返す等が疑われる。
 * PR #348 のコードとは無関係な、このテスト環境固有の事象）。
 * スクリーンショットは確認の補助資料であって合否の判定根拠ではないため、失敗しても
 * テスト自体は失敗させず、警告を出すだけに留める。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} filename
 */
async function safeScreenshot(page, filename) {
	try {
		// 直前のクリック操作でボタン上にマウスカーソルが残っていると、管理画面のホバー系
		// アニメーションの影響でスクリーンショットが不安定になり長時間ハングすることがあるため、
		// 撮影前にカーソルを何もない場所へ逃がしておく
		await page.mouse.move(0, 0);
		await page.screenshot({ path: `${SCREENSHOT_DIR}/${filename}`, fullPage: false, timeout: 8000 });
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(`PR348スクリーンショット: ${filename} の撮影に失敗しました（テスト結果には影響しません）: ${e}`);
	}
}

// 後始末（ゴミ箱へ移動）の対象にする投稿の編集画面 URL を貯めておく
const createdPostEditUrls = [];

/**
 * 書類（見積書 / 請求書）を新規作成する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ postType?: string, title: string, irregularClientName?: string, registeredClientLabel?: string, itemName?: string }} opts
 *   postType 省略時は 'estimate'（見積書）。請求書を直接作りたい場合は 'post' を指定する
 *   （bill_client_name_manual 等のカスタムフィールドは post_type=post の編集画面にもある。
 *   custom-field-normal-bill.php 参照）。
 * @return {Promise<string>} 作成した書類の編集画面 URL（post.php?post=ID&action=edit）。
 */
async function createEstimate(page, { postType = 'estimate', title, irregularClientName, registeredClientLabel, itemName }) {
	await page.goto(`/wp-admin/post-new.php?post_type=${postType}`);

	await page.locator('#title').fill(title);

	if (irregularClientName) {
		await page.locator('#bill_client_name_manual').fill(irregularClientName);
	}
	if (registeredClientLabel) {
		await page.locator('#bill_client').selectOption({ label: registeredClientLabel });
	}

	if (itemName) {
		await page.locator('[name="bill_items[0][name]"]').fill(itemName);
		await page.locator('[name="bill_items[0][count]"]').fill('1');
		await page.locator('[name="bill_items[0][unit]"]').fill('式');
		await page.locator('[name="bill_items[0][price]"]').fill('10000');
	}

	await page.locator('#publish').click({ force: true });
	await page.waitForURL('**/post.php?post=*&action=edit');
	// サイトのロケール（英語/日本語）によって文言が変わるため、通知の色（notice-success）だけで判定する
	await expect(page.locator('#message.notice-success')).toBeVisible();

	const url = page.url();
	createdPostEditUrls.push(url);
	return url;
}

/**
 * 取引先（client 投稿タイプ）を新規作成する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 * @return {Promise<string>} 作成した取引先の編集画面 URL。
 */
async function createClient(page, title) {
	await page.goto('/wp-admin/post-new.php?post_type=client');
	await page.locator('#title').fill(title);
	await page.locator('#publish').click({ force: true });
	await page.waitForURL('**/post.php?post=*&action=edit');
	// サイトのロケール（英語/日本語）によって文言が変わるため、通知の色（notice-success）だけで判定する
	await expect(page.locator('#message.notice-success')).toBeVisible();

	const url = page.url();
	createdPostEditUrls.push(url);
	return url;
}

/**
 * 見積書の編集画面で、指定テキストの複製・発行リンクをクリックし、遷移後の編集画面 URL を返す。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} linkText
 * @return {Promise<string>}
 */
async function clickDuplicateSectionLink(page, linkText) {
	// この <a> にも JS のクリックハンドラーは付いていない（bill_duplicate() が出力する
	// 素の href リンク）。click() だとマウスカーソルがボタン上に残り、直後の
	// スクリーンショットが管理画面のホバー系アニメーションの影響で不安定になり長時間ハング
	// することがあったため、href を取得して直接遷移する（duplicateFromList と同じ対処）
	const link = page.locator('.duplicate-section a', { hasText: linkText });
	const href = await link.getAttribute('href');
	expect(href, `「${linkText}」リンクの href が取得できること`).toBeTruthy();
	await page.goto(href);

	const url = page.url();
	createdPostEditUrls.push(url);
	return url;
}

/**
 * 一覧画面（edit.php）で、指定タイトルの行の行アクション「複製」をクリックし、
 * 遷移後の編集画面 URL を返す。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} listPath 例: '/wp-admin/edit.php?post_type=estimate'
 * @param {string} title
 * @return {Promise<string>}
 */
async function duplicateFromList(page, listPath, title) {
	await page.goto(`${listPath}&s=${encodeURIComponent(title)}`);
	const row = page.locator('#the-list tr').filter({
		has: page.locator('.row-title', { hasText: title }),
	});
	await expect(row, `一覧に「${title}」の行が1件見つかること`).toHaveCount(1);

	// 行アクションはホバー時のみ視覚的に表示される（visibility:hidden で隠れているだけで
	// DOM 上には常に存在する）。この環境では hover() や click() が「要素が安定する／
	// viewport 内にある」まで待つ処理でハングすることがあるため、hover はせず、
	// リンクの取得は次の行で href を直接読む方式にする
	const duplicateLink = row.locator('.row-actions .newlink a', { hasText: '複製' });
	await expect(duplicateLink).toHaveCount(1);
	// 行アクションは visibility:hidden で画面外の位置になっており、click() だと
	// 「viewport の外」判定でハングすることがある。この <a> に JS のクリックハンドラーは
	// 付いていない（bill_row_actions_add_duplicate_link() が出力する素の href リンク）ため、
	// href を取得して直接遷移することでクリックと同じ結果を安定して再現する
	const href = await duplicateLink.getAttribute('href');
	expect(href, '複製リンクの href が取得できること').toBeTruthy();
	await page.goto(href);

	const url = page.url();
	createdPostEditUrls.push(url);
	return url;
}

test.afterAll(async ({ browser }) => {
	// 作成した投稿をゴミ箱へ移動する（ベストエフォート。失敗してもテスト結果には影響させない）
	const context = await browser.newContext({ storageState: AUTH_STATE_PATH });
	const page = await context.newPage();
	try {
		for (const url of createdPostEditUrls) {
			try {
				await page.goto(url);
				const trashLink = page.locator('#delete-action a.submitdelete');
				if (await trashLink.count()) {
					await trashLink.click({ force: true });
				}
			} catch (e) {
				// 後始末の失敗はログに残すのみ（テスト自体は afterAll では失敗させない）
				// eslint-disable-next-line no-console
				console.warn(`PR348後始末: ${url} のゴミ箱移動に失敗しました: ${e}`);
			}
		}
	} finally {
		await context.close();
	}
});

test.describe('PR #348 見積書の取引先（イレギュラー）引き継ぎの確認', () => {
	/** @type {string} */
	let estimateEditUrl;
	/** @type {string} */
	let registeredEstimateEditUrl;
	/** @type {string} */
	let clientEditUrl;

	test.beforeAll(async ({ browser }, testInfo) => {
		// beforeAll で書類を5件（見積書3件・請求書1件・取引先1件）作成するため、既定の30秒では
		// タイムアウトすることがある。作成件数に余裕を持たせて120秒にする
		testInfo.setTimeout(120000);
		const context = await browser.newContext({ storageState: AUTH_STATE_PATH });
		const page = await context.newPage();
		try {
			// 手順1: 取引先（イレギュラー）を入力した見積書
			estimateEditUrl = await createEstimate(page, {
				title: ESTIMATE_TITLE,
				irregularClientName: IRREGULAR_CLIENT_NAME,
				itemName: ITEM_NAME,
			});

			// 手順5用: 取引先（登録済）を選んだ見積書
			clientEditUrl = await createClient(page, REGISTERED_CLIENT_TITLE);
			registeredEstimateEditUrl = await createEstimate(page, {
				title: ESTIMATE_TITLE_REGISTERED,
				registeredClientLabel: REGISTERED_CLIENT_TITLE,
				itemName: ITEM_NAME,
			});

			// 手順6用: 一覧の行アクション「複製」専用の見積書・請求書
			// （手順2〜4で作った投稿はタイトルが使い回されて件名検索が1件に絞れなくなるため、専用に用意する）
			await createEstimate(page, {
				postType: 'estimate',
				title: LIST_ESTIMATE_TITLE,
				irregularClientName: LIST_IRREGULAR_CLIENT_NAME,
				itemName: ITEM_NAME,
			});
			await createEstimate(page, {
				postType: 'post',
				title: LIST_INVOICE_TITLE,
				irregularClientName: LIST_IRREGULAR_CLIENT_NAME,
				itemName: ITEM_NAME,
			});
		} finally {
			await context.close();
		}
	});

	test('手順2: 「この内容で請求書を発行」で取引先（イレギュラー）と品目が請求書に引き継がれる', async ({
		page,
	}) => {
		await page.goto(estimateEditUrl);
		await clickDuplicateSectionLink(page, 'この内容で請求書を発行');

		await expect(page.locator('#bill_client_name_manual')).toHaveValue(IRREGULAR_CLIENT_NAME);
		await expect(page.locator('#bill_client')).toHaveValue('');
		await expect(page.locator('[name="bill_items[0][name]"]')).toHaveValue(ITEM_NAME);
		await expect(page.locator('[name="bill_items[0][price]"]')).toHaveValue('10000');

		await safeScreenshot(page, 'pr-348-step2-issue-invoice-all.png');
	});

	test('手順3: 「件名を品目一式にして請求書を発行」で取引先（イレギュラー）が引き継がれ品目が1行にまとまる', async ({
		page,
	}) => {
		await page.goto(estimateEditUrl);
		await clickDuplicateSectionLink(page, '件名を品目一式にして請求書を発行');

		await expect(page.locator('#bill_client_name_manual')).toHaveValue(IRREGULAR_CLIENT_NAME);
		await expect(page.locator('#bill_client')).toHaveValue('');

		// 品目が「件名 ( 税率 )」の形で1行にまとめられていること（bill_copy_post() の table_copy_type=total 分岐）
		await expect(page.locator('[name="bill_items[0][name]"]')).toHaveValue(
			new RegExp(`^${ESTIMATE_TITLE} \\( .+ \\) $`)
		);

		await safeScreenshot(page, 'pr-348-step3-issue-invoice-total.png');
	});

	test('手順4: 「見積書を複製」で取引先（イレギュラー）が引き継がれ、値が二重に入らず、更新後も保たれる', async ({
		page,
	}) => {
		await page.goto(estimateEditUrl);
		const duplicatedUrl = await clickDuplicateSectionLink(page, '見積書を複製');

		// 複製直後: 値が正しく（二重に連結されず）引き継がれていること
		await expect(page.locator('#bill_client_name_manual')).toHaveValue(IRREGULAR_CLIENT_NAME);
		await expect(page.locator('[name="bill_items[0][name]"]')).toHaveValue(ITEM_NAME);

		await safeScreenshot(page, 'pr-348-step4a-duplicate-estimate.png');

		// 複製先は下書きのため「下書き保存」ボタンで更新し、値が消えたり壊れたりしないことを確認する
		await page.locator('#save-post').click({ force: true });
		await page.waitForLoadState('load');
		// サイトのロケールによって文言が変わるため、通知の色（notice-success）だけで判定する
		await expect(page.locator('#message.notice-success')).toBeVisible();

		await expect(page.locator('#bill_client_name_manual')).toHaveValue(IRREGULAR_CLIENT_NAME);
		await expect(page.locator('[name="bill_items[0][name]"]')).toHaveValue(ITEM_NAME);

		// 再度ページを開き直しても（DBへの保存が反映された状態でも）値が保たれていることを確認する
		await page.goto(duplicatedUrl);
		await expect(page.locator('#bill_client_name_manual')).toHaveValue(IRREGULAR_CLIENT_NAME);

		// この画面は同じ page で3回目のフルアクション（複製 → 下書き保存 → 再訪問）となり、
		// スクリーンショットの「安定待ち」がハングしやすい傾向があったため、
		// 撮影直前に1回リロードして状態をリセットしてから撮る
		await page.reload();
		await expect(page.locator('#bill_client_name_manual')).toHaveValue(IRREGULAR_CLIENT_NAME);
		await safeScreenshot(page, 'pr-348-step4b-duplicate-estimate-resaved.png');
	});

	test('手順5: 取引先（登録済）を選んだ見積書は、登録済が引き継がれイレギュラー欄は空のまま', async ({
		page,
	}) => {
		await page.goto(registeredEstimateEditUrl);
		await clickDuplicateSectionLink(page, 'この内容で請求書を発行');

		await expect(page.locator('#bill_client_name_manual')).toHaveValue('');
		// #bill_client の選択中のラベルが登録済取引先のタイトルと一致すること
		await expect(page.locator('#bill_client')).toHaveValue(/.+/);
		const selectedLabel = await page.locator('#bill_client option:checked').textContent();
		expect(selectedLabel?.trim()).toBe(REGISTERED_CLIENT_TITLE);

		await safeScreenshot(page, 'pr-348-step5-registered-client.png');
	});

	test('手順6a/6b: 見積書一覧・請求書一覧の「複製」行アクションが従来どおり動く', async ({ page }) => {
		// 見積書一覧
		await duplicateFromList(page, '/wp-admin/edit.php?post_type=estimate', LIST_ESTIMATE_TITLE);
		await expect(page.locator('#bill_client_name_manual')).toHaveValue(LIST_IRREGULAR_CLIENT_NAME);
		await safeScreenshot(page, 'pr-348-step6a-list-duplicate-estimate.png');

		// 請求書一覧（post_type=post が既定のため post_type 指定なし）
		await duplicateFromList(page, '/wp-admin/edit.php?', LIST_INVOICE_TITLE);
		await expect(page.locator('#bill_client_name_manual')).toHaveValue(LIST_IRREGULAR_CLIENT_NAME);
		await safeScreenshot(page, 'pr-348-step6b-list-duplicate-invoice.png');
	});

	test('手順6c: 取引先・送付状一覧の「複製」行アクションが従来どおり動く', async ({ page }) => {
		// 事前に inc/duplicate-doc/duplicate-doc.php を読んで、bill_post_list_add_filter() が
		// 'post_row_actions' と 'estimate_row_actions' しかフックしておらず 'client_row_actions' を
		// 登録していないため、取引先・送付状一覧には複製リンクが出ないはず、と予想していた。
		// しかし実際にブラウザで確認したところ複製リンクは存在し、正常に動作した。
		// WP core の行アクションは 'page' 以外の投稿タイプに対して post_type 非依存の
		// 'post_row_actions' フィルターを使う（'estimate_row_actions' の登録は不要な冗長コード）
		// と考えられる。実機確認の結果を優先し、見積書・請求書と同様の複製確認を行う。
		const url = await duplicateFromList(page, '/wp-admin/edit.php?post_type=client', REGISTERED_CLIENT_TITLE);
		expect(url).toMatch(/post\.php\?post=\d+&action=edit/);
		await expect(page.locator('#title')).toHaveValue(REGISTERED_CLIENT_TITLE);

		await safeScreenshot(page, 'pr-348-step6c-list-duplicate-client.png');
	});
});
