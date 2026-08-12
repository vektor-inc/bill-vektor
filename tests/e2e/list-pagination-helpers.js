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
 *
 * 対象範囲: このテーマのフロント一覧（index.php が呼ぶコアの
 * the_posts_pagination()）のみを想定している。管理画面（wp-admin/edit.php）の
 * 一覧はページネーションのマークアップが異なるため、このヘルパーの対象外。
 */

/**
 * フロント一覧に「次のページ」へのリンクがあるかを判定する。
 *
 * WordPress コアの the_posts_pagination() は、次ページへのリンクにだけ
 * "next" クラスを付与する（ページ番号リンクは "page-numbers" のみで "next" は
 * 付かない）。この違いを手がかりに、まだ後続のページが存在するかを判定する。
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<boolean>}
 */
async function hasNextPage(page) {
	return (await page.locator('nav.navigation a.next.page-numbers').count()) > 0;
}

/**
 * 一覧のページを送りながら、指定したロケーターが1件以上見つかるページまで進む。
 *
 * 終了条件は固定のページ数ではなく「次ページへのリンクが無くなったか」で判定する
 * （コードレビュー指摘: 固定回数で打ち切ると、データが増えて正常にページ数が
 * 増えただけのケースまで「見つからない」と誤判定してしまう）。
 * ただし、テーマ側の不具合等でページ送りが実質的に終わらない事故を防ぐため、
 * safetyLimit を超えてもまだ次ページが残っている場合は、その旨を明示した
 * エラーで止める（無限ループにも、検証漏れの静かな失敗にもしない）。
 *
 * @param {import('@playwright/test').Page} page                                   Playwright の Page。
 * @param {string} basePath                                                        一覧のパス（クエリ文字列を含んでよい）。
 * @param {(page: import('@playwright/test').Page) => import('@playwright/test').Locator} locatorFn
 *   探したい行・要素のロケーターを返す関数。ページ遷移のたびに呼び直される。
 * @param {number} [safetyLimit=30]                                                探索するページ数の上限（異常検知用）。
 * @return {Promise<import('@playwright/test').Locator>} 見つかったページで評価済みのロケーター。
 */
async function gotoPageContaining(page, basePath, locatorFn, safetyLimit = 30) {
	let paged = 1;

	// eslint-disable-next-line no-constant-condition
	while (true) {
		const separator = basePath.includes('?') ? '&' : '?';
		await page.goto(paged === 1 ? basePath : `${basePath}${separator}paged=${paged}`);
		await page.waitForLoadState('domcontentloaded');

		const locator = locatorFn(page);
		if (await locator.count()) {
			return locator;
		}

		if (!(await hasNextPage(page))) {
			throw new Error(
				`一覧の最終ページ（${paged}ページ目）まで探しましたが、対象の行・要素が見つかりませんでした（${basePath}）。`
			);
		}

		paged += 1;
		if (paged > safetyLimit) {
			throw new Error(
				`一覧が${safetyLimit}ページを超えても終端に達しませんでした（${basePath}）。` +
					'想定より大量のテストデータが投入されているか、次ページ判定に問題がある可能性があります。'
			);
		}
	}
}

module.exports = { gotoPageContaining, hasNextPage };
