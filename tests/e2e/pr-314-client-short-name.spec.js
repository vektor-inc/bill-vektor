// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');

/**
 * PR #314 取引先の省略名優先判定の共通関数化にともなう UI / e2e テスト。
 *
 * 確認対象:
 *   1. 書類一覧（フロントトップ）の取引先欄の表示とリンク先
 *   2. 取引先一覧（?post_type=client）の取引先欄（デグレ確認の要）
 *   3. CSV エクスポートの取引先名
 *   4. アクセシビリティツリー上の読み上げテキスト
 *   5. レイアウトのデグレ（横スクロール・レスポンシブ）
 *
 * 前提となる検証データ（wp-cli で投入済みであること）:
 *   - 取引先「株式会社テスト取引先」（省略名: テスト取引先）
 *   - 取引先「有限会社サンプル商会」（省略名: サンプル商会）
 *   - 取引先「合同会社ショートネームなし」（省略名なし）
 *   - 請求書A取引先あり        → 取引先（登録済）= 株式会社テスト取引先
 *   - 請求書B取引先なし        → 取引先の設定なし
 *   - 請求書C_省略名なし取引先 → 取引先（登録済）= 合同会社ショートネームなし
 *   - 請求書D_イレギュラー取引先 → 取引先（イレギュラー）= イレギュラー商店
 *
 * bill-vektor テーマはログインが必要なため、
 * global-setup.js で取得したログイン済み storageState を使い回す。
 *
 * 実行例:
 *   WP_BASE_URL=http://localhost:9140 npx playwright test tests/e2e/pr-314-client-short-name.spec.js
 */

test.use({ storageState: 'tests/e2e/.auth-state.json' });

// 書類一覧（フロントトップ）と取引先一覧のパス
const DOC_LIST_PATH = '/';
const CLIENT_LIST_PATH = '/?post_type=client';

/**
 * 一覧テーブルの行を「件名（または取引先名）」のテキストから特定する。
 *
 * 投稿 ID に依存させないため、行内のテキストで絞り込む。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function listRow(page, title) {
	return page.locator('table.table tr').filter({ hasText: title });
}

/**
 * ページ送りの見出しを確認するため表示件数を絞っているので、
 * 目的の行が見つかるまでページを送りながら該当行を探す。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} basePath 一覧のパス
 * @param {string} title 探す件名
 * @param {number} maxPages 探索するページ数の上限
 */
async function gotoPageContaining(page, basePath, title, maxPages = 5) {
	for (let paged = 1; paged <= maxPages; paged += 1) {
		const separator = basePath.includes('?') ? '&' : '?';
		await page.goto(paged === 1 ? basePath : `${basePath}${separator}paged=${paged}`);
		await page.waitForLoadState('domcontentloaded');
		if (await listRow(page, title).count()) {
			return listRow(page, title);
		}
	}
	throw new Error(`一覧に「${title}」の行が見つかりませんでした（${basePath}）。`);
}

/**
 * 取引先ページのリンクを開き、その取引先のページに着地することを確認する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} href
 * @param {string} clientFullName 取引先の投稿タイトル
 * @param {string} [notContain] 着地したページに出てはいけない文字列（書類自身の件名など）
 */
async function expectClientPage(page, href, clientFullName, notContain) {
	const clientPage = await page.context().newPage();
	try {
		await clientPage.goto(href);
		// 取引先の送付状ページに取引先名が表示されていること
		await expect(clientPage.locator('.client-name, body')).toContainText(clientFullName);
		if (notContain) {
			// 書類自身のページへ飛んでいないこと
			expect(await clientPage.locator('body').innerText()).not.toContain(notContain);
		}
	} finally {
		await clientPage.close();
	}
}

/**
 * 行の「取引先」セルを取得する。
 *
 * 書類一覧では 3 列目（書類 / 発行日 / 取引先 / 件名 / カテゴリー）、
 * 取引先一覧では 2 列目（書類 / 取引先）が取引先欄になる。
 *
 * @param {import('@playwright/test').Locator} row
 * @param {number} index 0 始まりの列番号
 */
function clientCell(row, index) {
	return row.locator('td').nth(index);
}

test.describe('PR #314: 書類一覧・取引先一覧の取引先欄', () => {
	test('書類一覧: 取引先の有無・省略名の有無ごとに正しい表示とリンクになる', async ({ page }) => {
		// --- 取引先（登録済）＋省略名あり → 省略名を表示し、取引先ページへリンクする ---
		const withClient = clientCell(
			await gotoPageContaining(page, DOC_LIST_PATH, '請求書A取引先あり'),
			2
		);
		// issue #310: 別タブで開くことを予告するscreen-reader-textがセルのテキストに合成される
		await expect(withClient).toHaveText('テスト取引先（新しいタブで開きます）');
		const withClientLink = withClient.locator('a');
		await expect(withClientLink).toHaveCount(1);
		// リンク先が「書類自身」ではなく「取引先」のページであること
		const href = await withClientLink.getAttribute('href');
		expect(href).toBeTruthy();
		await expect(withClientLink).toHaveAttribute('target', '_blank');
		// issue #310: window.opener 経由の操作を防ぐ rel="noopener" が付与されていること
		await expect(withClientLink).toHaveAttribute('rel', 'noopener');

		// 実際に開いて取引先ページに着地することを確認する
		await expectClientPage(page, href, '株式会社テスト取引先', '請求書A取引先あり');

		// --- 取引先（登録済）＋省略名なし → 取引先名（フルネーム）を表示しリンクする ---
		const noShortName = clientCell(
			await gotoPageContaining(page, DOC_LIST_PATH, '請求書C_省略名なし取引先'),
			2
		);
		// issue #310: 別タブで開くことを予告するscreen-reader-textがセルのテキストに合成される
		await expect(noShortName).toHaveText('合同会社ショートネームなし（新しいタブで開きます）');
		await expect(noShortName.locator('a')).toHaveCount(1);

		// --- 取引先（イレギュラー）のみ → 手入力名をテキストで表示し、リンクにしない ---
		const manual = clientCell(
			await gotoPageContaining(page, DOC_LIST_PATH, '請求書D_イレギュラー取引先'),
			2
		);
		await expect(manual).toHaveText('イレギュラー商店');
		await expect(manual.locator('a')).toHaveCount(0);

		// --- 取引先が未設定 → ダッシュ表示・リンクなし・書類自身の件名が出ないこと ---
		const noClientRow = await gotoPageContaining(page, DOC_LIST_PATH, '請求書B取引先なし');
		const noClient = clientCell(noClientRow, 2);
		// リンクになっていないこと（#306 の修正点）
		await expect(noClient.locator('a')).toHaveCount(0);
		// 視覚表示はダッシュのみ
		await expect(noClient.locator('[aria-hidden="true"]')).toHaveText('—');
		// 読み上げ用の代替テキストが入っていること
		await expect(noClient.locator('.screen-reader-text')).toHaveText('取引先なし');
		// 書類自身の件名が取引先名として漏れていないこと（#293 の回帰）
		const noClientText = (await noClient.textContent()) || '';
		expect(noClientText.replace('取引先なし', '').trim()).toBe('—');
		expect(noClientText).not.toContain('請求書B取引先なし');
	});

	test('取引先一覧: 取引先のフルネームが表示され取引先ページへのリンクになっている', async ({ page }) => {
		await page.goto(CLIENT_LIST_PATH);
		await page.waitForLoadState('domcontentloaded');

		// 取引先一覧では「発行日」「件名」列が出ないため、列構成は 書類 / 取引先 の2列
		const headers = await page.locator('table.table th').allTextContents();
		expect(headers.map((text) => text.trim())).toEqual(['書類', '取引先']);

		const clients = [
			// [表示されるべきフルネーム, 省略名（表示されてはいけない）]
			['株式会社テスト取引先', 'テスト取引先'],
			['有限会社サンプル商会', 'サンプル商会'],
			['合同会社ショートネームなし', null],
		];

		for (const [fullName, shortName] of clients) {
			const cell = clientCell(
				await gotoPageContaining(page, CLIENT_LIST_PATH, fullName),
				1
			);
			// フルネームがそのまま表示されていること（省略名ではない）。
			// issue #310: 別タブで開くことを予告するscreen-reader-textがセルのテキストに合成される。
			await expect(cell).toHaveText(`${fullName}（新しいタブで開きます）`);
			// ダッシュになっていないこと（ここが「—」ならデグレ）
			await expect(cell).not.toHaveText('—');
			// 取引先ページへの唯一の導線となるリンクが存在すること
			const link = cell.locator('a');
			await expect(link).toHaveCount(1);
			// issue #310: window.opener 経由の操作を防ぐ rel="noopener" が付与されていること
			await expect(link).toHaveAttribute('rel', 'noopener');

			if (shortName) {
				// 省略名だけの表示になっていないことを明示的に確認する
				expect(await cell.textContent()).not.toBe(shortName);
			}

			// リンクを開くとその取引先のページに着地すること
			const href = await link.getAttribute('href');
			expect(href).toBeTruthy();
			await expectClientPage(page, href, fullName);
		}
	});

	test('取引先一覧: 無題の取引先でもリンクに読み上げ可能な名前がある', async ({ page }) => {
		/*
		 * 取引先一覧の「取引先」列は取引先ページを開く唯一の導線のため、
		 * 名前を付けずに保存された取引先でもリンクが何を指すのか読み上げられる必要がある。
		 * issue #310 対応後は、ダッシュ＋代替テキスト「名称未設定の取引先（新しいタブで開きます）」
		 * （別タブで開くことの予告を合成した文言）を出している。
		 */
		await page.goto(CLIENT_LIST_PATH);
		await page.waitForLoadState('domcontentloaded');

		const untitledLink = page.locator('table.table td a[href*="untitled-client"]');
		await expect(untitledLink).toHaveCount(1);

		// issue #310: window.opener 経由の操作を防ぐ rel="noopener" が付与されていること
		await expect(untitledLink).toHaveAttribute('rel', 'noopener');

		// リンクにアクセシブルネーム（読み上げられる文字列）があること
		const accessibleName = await untitledLink.evaluate((element) => element.innerText.trim());
		expect(
			accessibleName,
			'無題の取引先のリンクが空になっており、読み上げ時に何のリンクか分からない'
		).not.toBe('');
		// issue #310: 別タブで開くことを予告する文言が実際に読み上げ対象になっていること
		expect(accessibleName).toContain('（新しいタブで開きます）');
	});

	test('CSVエクスポート: 省略名が入り、取引先未設定の行は空になる', async ({ page }) => {
		await page.goto(DOC_LIST_PATH);
		await page.waitForLoadState('domcontentloaded');

		// CSV エクスポートは nonce 必須のため、フォームの hidden から取得する
		const nonce = await page
			.locator('.export-box input[name="_wpnonce"]')
			.first()
			.inputValue();
		expect(nonce).toBeTruthy();

		// ボタンと同じ GET をログイン済みコンテキストから送る
		const response = await page.request.get(
			`/?action=csv_freee&_wpnonce=${encodeURIComponent(nonce)}`
		);
		expect(response.ok()).toBe(true);
		const csv = await response.text();

		// 行を件名で特定して、取引先名の列を確認する
		const rows = csv.split(/\r?\n/);

		// 取引先を設定した行 → 省略名が入っている
		const rowA = rows.find((row) => row.includes('請求書A取引先あり'));
		expect(rowA).toBeTruthy();
		expect(rowA).toContain('"テスト取引先"');

		// 省略名が無い取引先の行 → 取引先名（フルネーム）が入っている
		const rowC = rows.find((row) => row.includes('請求書C_省略名なし取引先'));
		expect(rowC).toBeTruthy();
		expect(rowC).toContain('"合同会社ショートネームなし"');

		// 取引先を設定していない行 → 取引先名は空で、書類の件名が入らないこと
		const rowB = rows.find((row) => row.includes('請求書B取引先なし'));
		expect(rowB).toBeTruthy();
		// 件名が取引先名の列に漏れていないこと（"請求書B取引先なし" が1回だけ出現する）
		expect((rowB.match(/請求書B取引先なし/g) || []).length).toBe(1);
		expect(rowB).toContain('""');
	});

	test('アクセシビリティ: 取引先なしが読み上げられ、ページ送り見出しは視覚的に隠れている', async ({ page }) => {
		// --- 取引先未設定セルの読み上げ内容 ---
		const noClient = clientCell(
			await gotoPageContaining(page, DOC_LIST_PATH, '請求書B取引先なし'),
			2
		);
		const srText = noClient.locator('.screen-reader-text');

		// アクセシビリティツリーに残っていること（display:none だと消える）
		const ariaSnapshot = await noClient.ariaSnapshot();
		expect(ariaSnapshot).toContain('取引先なし');

		// 画面上は見えない大きさに潰されていること（1px 以下）
		const srBox = await srText.evaluate((element) => {
			const rect = element.getBoundingClientRect();
			const style = getComputedStyle(element);
			return {
				width: rect.width,
				height: rect.height,
				display: style.display,
				position: style.position,
				clipPath: style.clipPath,
			};
		});
		// display:none だと読み上げ対象から外れるため NG
		expect(srBox.display).not.toBe('none');
		expect(srBox.position).toBe('absolute');
		expect(srBox.width).toBeLessThanOrEqual(1);
		expect(srBox.height).toBeLessThanOrEqual(1);

		// ダッシュ側は aria-hidden で読み上げから除外されていること
		await expect(noClient.locator('span[aria-hidden="true"]')).toHaveText('—');

		// --- ページ送りの見出し ---
		const paginationHeading = page.locator('.pagination .screen-reader-text, nav.navigation .screen-reader-text').first();
		await expect(paginationHeading).toHaveCount(1);
		const headingText = ((await paginationHeading.textContent()) || '').trim();
		expect(headingText.length).toBeGreaterThan(0);

		// 見出しがアクセシビリティツリーに存在すること
		const navSnapshot = await page.locator('nav.navigation').first().ariaSnapshot();
		expect(navSnapshot).toContain('heading');

		// 画面上には出ていないこと（1px に潰されている）
		const headingBox = await paginationHeading.evaluate((element) => {
			const rect = element.getBoundingClientRect();
			return { width: rect.width, height: rect.height, display: getComputedStyle(element).display };
		});
		expect(headingBox.display).not.toBe('none');
		expect(headingBox.width).toBeLessThanOrEqual(1);
		expect(headingBox.height).toBeLessThanOrEqual(1);
	});

	test('レイアウト: 各画面幅で横スクロールが発生しない', async ({ page }) => {
		const widths = [1600, 1280, 1024, 768, 414, 375];

		for (const path of [DOC_LIST_PATH, CLIENT_LIST_PATH]) {
			for (const width of widths) {
				await page.setViewportSize({ width, height: 900 });
				await page.goto(path);
				await page.waitForLoadState('domcontentloaded');

				// ページ全体に意図しない横スクロールが出ていないこと
				const dimensions = await page.evaluate(() => ({
					scrollWidth: document.documentElement.scrollWidth,
					clientWidth: document.documentElement.clientWidth,
				}));
				expect(
					dimensions.scrollWidth,
					`${path} / ${width}px で横スクロールが発生している`
				).toBeLessThanOrEqual(dimensions.clientWidth + 1);

				// 絞り込みフォーム・CSVエクスポート欄が表示されていること
				await expect(page.locator('#search-box')).toBeVisible();
				await expect(page.locator('#csv-export')).toBeVisible();
			}
		}
	});
});
