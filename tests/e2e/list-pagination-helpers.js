// @ts-check

/**
 * ページ送り（pagination）されている一覧から、目的の行を安全に見つけるための共通ヘルパー。
 *
 * 背景（issue #322）:
 * 複数の e2e テストデータ作成スクリプトが同じ DB にデータを投入すると、一覧の
 * 表示件数（既定 10 件/ページなど）を超え、あるスペックが作った投稿が
 * 1ページ目から押し出されることがある。これに気づかず「1ページ目だけを見て
 * 存在しない」と判定するテストは、他スペックがどれだけデータを積み上げているか
 * という外部要因に結果が左右され、フルスイート実行時にだけ失敗する原因になる。
 *
 * このヘルパーは、対象のロケーターが見つかるまでページを送ることで、
 * 他スペックのデータ量（＝ページ送りの発生有無や発生ページ数）に依存しない
 * 検証を可能にする。
 */

/**
 * 一覧のページを送りながら、指定したロケーターが1件以上見つかるページまで進む。
 *
 * @param {import('@playwright/test').Page} page                                   Playwright の Page。
 * @param {string} basePath                                                        一覧のパス（クエリ文字列を含んでよい）。
 * @param {(page: import('@playwright/test').Page) => import('@playwright/test').Locator} locatorFn
 *   探したい行・要素のロケーターを返す関数。ページ遷移のたびに呼び直される。
 * @param {number} [maxPages=5]                                                    探索するページ数の上限。
 * @return {Promise<import('@playwright/test').Locator>} 見つかったページで評価済みのロケーター。
 */
async function gotoPageContaining(page, basePath, locatorFn, maxPages = 5) {
	for (let paged = 1; paged <= maxPages; paged += 1) {
		const separator = basePath.includes('?') ? '&' : '?';
		await page.goto(paged === 1 ? basePath : `${basePath}${separator}paged=${paged}`);
		await page.waitForLoadState('domcontentloaded');

		const locator = locatorFn(page);
		if (await locator.count()) {
			return locator;
		}
	}

	throw new Error(
		`一覧の${maxPages}ページ以内に対象の行・要素が見つかりませんでした（${basePath}）。` +
			'他のテストデータが積み重なってページ送りが増えている可能性があります。'
	);
}

module.exports = { gotoPageContaining };
