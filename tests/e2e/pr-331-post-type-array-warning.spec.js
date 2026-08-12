// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { hasNextPage } = require('./list-pagination-helpers');

/**
 * PR #331 / issue #318 の e2e テスト
 *
 * 書類一覧（トップページ）の絞り込みフォーム由来のクエリー文字列
 * （post_type・bill_client・start_date・end_date）に配列を渡すと
 * PHP の警告（Array to string conversion）が発生していた不具合の修正確認と、
 * 絞り込みフォームからの通常操作（デグレ確認）を行う。
 *
 * 対象: inc/functions-pre-get-posts.php の bill_custom_home_post_type()
 * （この関数は閲覧制限より前に実行されるため、未ログインのリクエストでも通る経路である）。
 *
 * 事前準備（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-331.php
 *
 * 実行例:
 *   WP_BASE_URL=http://localhost:9116 npx playwright test tests/e2e/pr-331-post-type-array-warning.spec.js
 *
 * WP_DEBUG / WP_DEBUG_DISPLAY / WP_DEBUG_LOG を有効にした環境で実行することを想定している
 * （.wp-env.override.json 等で設定する）。無効な環境では「警告が出ないこと」の確認自体が
 * 成立しない（警告が出ても表示されないだけになる）ため、実行前に確認すること。
 */

// PHP の警告文言（このPRが解消の対象とする文言）
const ARRAY_WARNING_TEXT = 'Array to string conversion';

// このスクリプトが作成した書類・取引先の件名（tests/e2e/create-test-data-pr-331.php と対応）
const TITLES = {
	client: 'PR331テスト取引先',
	invoiceA: 'PR331契約書A', // post種別 / 2024-05-01 / 取引先あり
	invoiceB: 'PR331契約書B', // post種別 / 2024-06-01 / 取引先なし
	estimateA: 'PR331見積書A', // estimate種別 / 2024-05-15 / 取引先あり（invoiceAと同じ）
};

/**
 * 一覧テーブルの行（ヘッダー行を除く）から、件名セルの文字列を上から順に取得する。
 * 列構成は「書類 / 発行日 / 取引先 / 件名 / カテゴリー」（template-parts/search-box.php・index.php参照）。
 *
 * issue #310 対応で件名セルに別タブで開くことを予告する screen-reader-text
 * （「（新しいタブで開きます）」）が付くようになり、textContent ベースでは
 * 予告文言込みの文字列になってしまう。可視の件名だけを比較したいので、
 * セルを複製してから screen-reader-text 要素を取り除いた上でテキストを取得する
 * （allTextContents() を使わないのは、この除去処理をブラウザ側で行うため）。
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string[]>}
 */
async function getDocumentTitles(page) {
	return page
		.locator('table.table tr')
		.filter({ has: page.locator('td') })
		.locator('td:nth-child(4)')
		.evaluateAll((cells) =>
			cells.map((cell) => {
				const clone = cell.cloneNode(true);
				clone.querySelectorAll('.screen-reader-text').forEach((node) => node.remove());
				return clone.textContent.trim();
			})
		);
}

/**
 * 現在のページを含め、次ページが無くなるまで一覧をめくりながら件名を集める。
 *
 * issue #322: 絞り込み無し（または投稿タイプのみ等の緩い絞り込み）の一覧は、
 * 他の e2e テストデータ作成スクリプトが作った書類も対象に含む。他スペックの
 * データが積み重なるとページ送りが発生し、このPR用の書類（固定で2024年の
 * 日付を持つため、既定の「発行日の新しい順」では他スペックの当日日付の
 * データより下に来やすい）が1ページ目に無いことがある。全ページ分の
 * 件名を集約して判定することで、他スペックのデータ量に依存しない検証にする。
 *
 * 終了条件は固定のページ数ではなく、list-pagination-helpers.js の
 * hasNextPage() による「次ページへのリンクが無くなったか」で判定する
 * （コードレビュー指摘: 固定回数で打ち切ると、データが増えて正常にページ数が
 * 増えただけのケースまで誤判定してしまう。かつ not.toContain 系の検証が
 * 途中で打ち切られて素通りする恐れもある）。safetyLimit は異常検知用の保険。
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} [safetyLimit=30] 探索するページ数の上限（異常検知用）。
 * @param {string} [forbiddenText] 各ページに含まれていてはいけない文字列（見つかれば例外）。
 *   コードレビュー指摘: PHP警告の非存在確認が1ページ目でしか行われず、ヘルパーが
 *   めくった2ページ目以降を検査していなかったため、探索した全ページで検査できるようにする。
 * @returns {Promise<string[]>}
 */
async function collectDocumentTitlesAcrossPages(page, safetyLimit = 30, forbiddenText) {
	// e2e ルール（testing/e2e.md）: page.goto() には絶対URLではなく相対パスを渡す。
	// ベースURLは playwright.config.js の baseURL / WP_BASE_URL 環境変数に任せる
	// （CIとローカルでポートが異なるため）。list-pagination-helpers.js の
	// gotoPageContaining() が basePath を相対パスで受け取る流儀と揃える
	// （コードレビュー指摘: 以前は page.url() の絶対URLをそのまま組み立て直して
	// 渡しており、このファイルの他の page.goto() 呼び出しと不統一だった）。
	const currentUrl = new URL(page.url());
	const basePath = currentUrl.pathname + currentUrl.search;
	/** @type {string[]} */
	const titles = [];
	let paged = 1;

	// eslint-disable-next-line no-constant-condition
	while (true) {
		if (paged > 1) {
			const separator = basePath.includes('?') ? '&' : '?';
			const response = await page.goto(`${basePath}${separator}paged=${paged}`, {
				waitUntil: 'domcontentloaded',
			});
			// コードレビュー指摘: hasNextPage() で「次ページへのリンクがある」と
			// 判定した上で遷移しているため、ここで応答が失敗するのは想定外の異常
			// （ネットワークエラー・サーバーエラー等）のときだけ。以前はここで
			// 静かに break していたが、それだと titles が不完全なまま返り、
			// 呼び出し側の not.toContain() 系の検証が誤って通過してしまう
			// （fail-open）。異常時こそ明示的に失敗させる。
			expect(response?.ok(), `${paged}ページ目の取得に失敗しました`).toBeTruthy();
		}

		if (forbiddenText) {
			const bodyText = await page.content();
			if (bodyText.includes(forbiddenText)) {
				throw new Error(`一覧の${paged}ページ目に禁止文字列が含まれていました: ${forbiddenText}`);
			}
		}

		const pageTitles = await getDocumentTitles(page);
		titles.push(...pageTitles);

		if (!(await hasNextPage(page))) break;

		paged += 1;
		if (paged > safetyLimit) {
			throw new Error(
				`一覧が${safetyLimit}ページを超えても終端に達しませんでした。` +
					'想定より大量のテストデータが投入されている可能性があります。'
			);
		}
	}

	return titles;
}

/**
 * 一覧テーブルの発行日セル（YYYY.MM.DD 表記）を上から順に取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string[]>}
 */
async function getDocumentDates(page) {
	return page
		.locator('table.table tr')
		.filter({ has: page.locator('td') })
		.locator('td:nth-child(2)')
		.allTextContents();
}

/**
 * 絞り込みフォームを送信し、画面遷移が終わるまで待つ。
 *
 * @param {import('@playwright/test').Page} page
 */
async function submitFilters(page) {
	await page.getByRole('button', { name: /絞り込み/ }).click();
	expect(new URL(page.url()).searchParams.get('action')).toBe('send');
}

test.describe('PR #331: 配列パラメーターによる PHP 警告の解消（未ログイン）', () => {
	// この関数はログイン前（bill_no_login_redirect() より前）に実行される経路のため、
	// 未ログイン状態で確認する。前のテストの認証状態を持ち越さない。
	test.use({ storageState: { cookies: [], origins: [] } });

	const arrayParamCases = [
		{ name: 'post_type[]', qs: 'post_type[]=estimate&post_type[]=post' },
		{ name: 'bill_client[]', qs: 'bill_client[]=1' },
		{ name: 'start_date[]', qs: 'start_date[]=2024-01-01' },
		{ name: 'end_date[]', qs: 'end_date[]=2024-12-31' },
	];

	for (const { name, qs } of arrayParamCases) {
		test(`${name} を渡しても警告が出ず、ログイン画面へのリダイレクトが壊れない`, async ({
			page,
		}) => {
			// page.goto() はリダイレクトを自動で追従してしまい response.status() が
			// 最終遷移後（ログイン画面 = 200）になるため、最初の応答（302 のはず）を
			// そのまま見るには maxRedirects: 0 を指定した request API を使う。
			const response = await page.request.get(`/?${qs}`, {
				maxRedirects: 0,
			});

			// 未ログイン時はログイン画面へ 302 で転送されること、および警告が
			// 出力されないことを検証する。警告が出力されると転送が正しく行われないため、
			// あわせて確認している。
			expect(response.status()).toBe(302);
			expect(response.headers()['location']).toContain('wp-login.php');

			const bodyText = await response.text();
			expect(bodyText).not.toContain(ARRAY_WARNING_TEXT);
			expect(bodyText).not.toContain('headers already sent');
		});
	}
});

test.describe('PR #331: 挙動確認・デグレ確認（ログイン済み）', () => {
	// global-setup.js が保存したログイン済み Cookie を使用する。
	test.use({ storageState: 'tests/e2e/.auth-state.json' });

	test.beforeEach(async ({ page }) => {
		await page.goto('/');
		await expect(page.locator('#post_type')).toBeVisible();
	});

	test('post_type[] 配列でも画面に警告が出ず、既定の一覧（0件でない）が表示される', async ({
		page,
	}) => {
		await page.goto('/?post_type[]=estimate&post_type[]=post');

		// コードレビュー指摘: 以前は1ページ目の bodyText だけを確認しており、
		// ページ送りで探索する2ページ目以降の警告混入は見逃していた。
		// forbiddenText を渡し、探索する全ページで確認する。
		const titles = await collectDocumentTitlesAcrossPages(page, undefined, ARRAY_WARNING_TEXT);
		expect(titles.length).toBeGreaterThan(0);
		// 配列指定は「未指定」と同じ扱いになり、請求書・見積書の両方が既定表示される。
		expect(titles).toContain(TITLES.invoiceA);
		expect(titles).toContain(TITLES.estimateA);
	});

	test('post_type=見積（日本語・sanitize_keyで空文字化）でも請求書だけにならず、既定の一覧になる', async ({
		page,
	}) => {
		// 「見積」は sanitize_key() で空文字に丸められる。空文字を post_type にそのまま
		// 渡すと WP_Query が post_type='post' 相当に解釈し見積書が消える、というのが
		// この PR が対象にした不具合（配列の警告とは別の既存の修正点）。
		await page.goto(`/?post_type=${encodeURIComponent('見積')}`);

		const titles = await collectDocumentTitlesAcrossPages(page);
		expect(titles).toContain(TITLES.invoiceA);
		expect(titles).toContain(TITLES.estimateA);
	});

	test('post_type=（空文字）でも既定の一覧になる', async ({ page }) => {
		await page.goto('/?post_type=');

		const titles = await collectDocumentTitlesAcrossPages(page);
		expect(titles).toContain(TITLES.invoiceA);
		expect(titles).toContain(TITLES.estimateA);
	});

	test('デグレ確認: 投稿タイプ「見積書」を選ぶと見積書だけが表示される', async ({
		page,
	}) => {
		await page.locator('#post_type').selectOption('estimate');
		await submitFilters(page);

		const titles = await collectDocumentTitlesAcrossPages(page);
		expect(titles).toContain(TITLES.estimateA);
		expect(titles).not.toContain(TITLES.invoiceA);
		expect(titles).not.toContain(TITLES.invoiceB);
	});

	test('デグレ確認: 取引先で絞り込むとその取引先の書類だけが表示される', async ({
		page,
	}) => {
		// #post_type は <select> のため、触らなくても常に何らかの値（初期状態は
		// 1番目の選択肢＝請求書）が送信される。そのため取引先だけを変更した場合、
		// 実際のフォーム送信は「投稿タイプ＝請求書 かつ 取引先＝指定した取引先」になる
		// （見積書を含めて絞り込みたい場合は投稿タイプも明示的に選ぶ必要があり、
		// これは組み合わせ絞り込みのテストで別途確認する）。
		await page.locator('#bill_client').selectOption({ label: TITLES.client });
		await submitFilters(page);

		const titles = await getDocumentTitles(page);
		// invoiceA は指定した取引先、invoiceB は取引先未設定のため除外される。
		expect(titles).toContain(TITLES.invoiceA);
		expect(titles).not.toContain(TITLES.invoiceB);
	});

	test('デグレ確認: 発行日の開始・終了で絞り込むとその期間の書類だけが表示される', async ({
		page,
	}) => {
		// #post_type は触らないため請求書（post）のみが対象になる
		// （取引先テストと同じ理由。上の補足コメント参照）。
		// datepicker の入力欄はハイフン無しの8桁表記で送信される
		// （既存の pr-298-keyword-search.spec.js 等と同じ形式）。
		await page.locator('#start_date').fill('20240501');
		await page.locator('#end_date').fill('20240531');
		await submitFilters(page);

		const titles = await getDocumentTitles(page);
		// invoiceA（05-01）は範囲内、invoiceB（06-01）は範囲外。
		expect(titles).toContain(TITLES.invoiceA);
		expect(titles).not.toContain(TITLES.invoiceB);
	});

	test('デグレ確認: キーワードで件名の一部を絞り込め、発行日順の並びが保たれる', async ({
		page,
	}) => {
		// #post_type は触らないため請求書（post）のみが対象になる（上の補足コメント参照）。
		// このPR用の請求書2件（契約書A・契約書B）だけを対象にし、
		// 他PRのテストデータの影響を受けずに並び順を確認する。
		await page.locator('#bill_keyword').fill('PR331');
		await submitFilters(page);

		const titles = await getDocumentTitles(page);
		expect(titles).toEqual([
			TITLES.invoiceB, // 2024-06-01（最新）
			TITLES.invoiceA, // 2024-05-01（最古）
		]);

		const dates = await getDocumentDates(page);
		expect(dates).toEqual(['2024.06.01', '2024.05.01']);
	});

	test('デグレ確認: 投稿タイプ・取引先・発行日を組み合わせて絞り込める', async ({
		page,
	}) => {
		// 見積書 + 同じ取引先 + 5月の範囲 → estimateA だけがヒットする。
		await page.locator('#post_type').selectOption('estimate');
		await page.locator('#bill_client').selectOption({ label: TITLES.client });
		await page.locator('#start_date').fill('20240501');
		await page.locator('#end_date').fill('20240531');
		await submitFilters(page);

		const titles = await getDocumentTitles(page);
		expect(titles).toEqual([TITLES.estimateA]);
	});

	test('デグレ確認: 条件を指定せずトップページを開くと発行日の新しい順で表示される', async ({
		page,
	}) => {
		await page.goto('/');

		const dates = await getDocumentDates(page);
		expect(dates.length).toBeGreaterThan(0);

		// YYYY.MM.DD を比較可能な形にして新しい順（降順）になっていることを確認する。
		const timestamps = dates.map((d) => new Date(d.replace(/\./g, '-')).getTime());
		const sorted = [...timestamps].sort((a, b) => b - a);
		expect(timestamps).toEqual(sorted);
	});

	test('デグレ確認: 日付アーカイブ（2024年5月）を開いても一覧が従来どおり表示される', async ({
		page,
	}) => {
		const response = await page.goto('/2024/05/', {
			waitUntil: 'domcontentloaded',
		});
		expect(response && response.status()).toBe(200);

		const bodyText = await page.content();
		expect(bodyText).not.toContain(ARRAY_WARNING_TEXT);

		// 日付アーカイブは post_type を制限しないテーマの仕様（コードコメント参照）どおり、
		// invoiceA（2024-05-01・post種別）が表示される。
		const titles = await getDocumentTitles(page);
		expect(titles).toContain(TITLES.invoiceA);
	});

	test('デグレ確認: カテゴリーアーカイブを開いても一覧が従来どおり表示される', async ({
		page,
	}) => {
		const response = await page.goto('/category/uncategorized/', {
			waitUntil: 'domcontentloaded',
		});
		expect(response && response.status()).toBe(200);

		// コードレビュー指摘: 以前はここだけ独自に1ページ目の bodyText を確認しており、
		// 「PHP警告の非存在確認」の流儀が2通り同居していた（他のテストは
		// collectDocumentTitlesAcrossPages の forbiddenText 引数で全ページ検査する）。
		// カテゴリーアーカイブもページ送りされうるため、同じ流儀に寄せる。
		// PR331契約書A・B は既定のカテゴリー（Uncategorized）のまま作成しているため表示される。
		const titles = await collectDocumentTitlesAcrossPages(page, undefined, ARRAY_WARNING_TEXT);
		expect(titles).toContain(TITLES.invoiceA);
	});
});
