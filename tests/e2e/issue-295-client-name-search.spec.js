// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
const { requireTestDataPresent } = require('./require-test-data');
const { AUTH_STATE_PATH, withAuthenticatedPage } = require('./auth-helpers');

/**
 * issue #295 e2e テスト
 *
 * 管理画面の書類一覧（edit.php）で、取引先（イレギュラー）の手入力名・
 * 取引先（登録済）の投稿タイトルの両方で検索できること、および取引先カラムの
 * 見出しで並び替えても取引先未設定の書類が一覧から消えないことをブラウザ上で確認する。
 *
 * このファイル名は PR 番号が決まるまでの暫定で issue 番号ベース（295）にしている。
 * PR 番号が決まり次第、司の指示で issue-295-*.spec.js から pr-XXX-*.spec.js へ
 * リネームする（tests/e2e/issue-299-csv-export-auth.spec.js と同じ命名の前例に合わせた）。
 *
 * 実行前に、テストデータ作成スクリプトで書類・取引先を作成しておくこと。
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-295.php
 *
 * スクリプトは繰り返し実行しても同じ結果になる（同じ件名の書類があれば再利用する）。
 * DB の import / reset / export は行わない。
 */

// global-setup.js で保存したログイン済み Cookie を使用する。
test.use({ storageState: AUTH_STATE_PATH });

const SETUP_HINT =
	'issue #295 のテストデータを作成してから実行してください:\n' +
	'  npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-295.php';

test.beforeAll(async ({ browser }) => {
	await withAuthenticatedPage(browser, (page) =>
		requireTestDataPresent(
			page,
			'/wp-admin/edit.php?post_type=post&s=' +
				encodeURIComponent('issue295登録済取引先の請求書'),
			(p) => p.locator('.row-title', { hasText: 'issue295登録済取引先の請求書' }),
			'issue #295 の請求書「issue295登録済取引先の請求書」',
			SETUP_HINT
		)
	);
});

test.describe('issue #295: 取引先名での検索・並び替え', () => {

	test('登録済取引先の投稿タイトルで検索すると該当の請求書がヒットする', async ({ page }) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=post&s=' + encodeURIComponent('イーツーイーサーチ')
		);

		// 「イーツーイーサーチ」は取引先（登録済）の投稿タイトルにしか含まれず、
		// どの請求書の件名にも含まれないため、これがヒットすること自体が
		// 取引先名検索の拡張が効いていることの証拠になる。
		await expect(page.locator('.row-title', { hasText: 'issue295登録済取引先の請求書' })).toBeVisible();
		await expect(page.locator('.row-title', { hasText: 'issue295両方設定の請求書' })).toBeVisible();
		// 取引先を設定していない請求書は含まれない
		await expect(page.locator('.row-title', { hasText: 'issue295手入力取引先の請求書' })).toHaveCount(0);
	});

	test('取引先（イレギュラー）の手入力名で検索すると該当の請求書がヒットする', async ({ page }) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=post&s=' + encodeURIComponent('issue295手入力太郎')
		);

		await expect(page.locator('.row-title', { hasText: 'issue295手入力取引先の請求書' })).toBeVisible();
		await expect(page.locator('.row-title', { hasText: 'issue295登録済取引先の請求書' })).toHaveCount(0);
	});

	test('既存の件名検索が壊れていない（回帰）', async ({ page }) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=post&s=' + encodeURIComponent('issue295両方設定の請求書')
		);

		await expect(page.locator('.row-title', { hasText: 'issue295両方設定の請求書' })).toBeVisible();
	});

	test('取引先カラムの見出しで並び替えても、取引先未設定の見積書が一覧から消えない', async ({ page }) => {
		// 見積書一覧に絞る（他のテストデータの影響を避けるため取引先カラムの見出しリンクを直接使う）
		await page.goto('/wp-admin/edit.php?post_type=estimate');

		const clientColumnHeader = page.locator('#bill_client_name a');
		await expect(clientColumnHeader).toBeVisible();

		// リンク先URL（WordPress コアが生成するもの。自前で組み立てない）を読み取り、
		// そこへ移動する。click() ではなく goto() にしているのは、管理バーの折りたたみ
		// アニメーション等でクリック直後に要素が動き続け、Playwright のアクショナビリティ
		// 判定（要素が安定するまで待つ）がまれにタイムアウトすることがあるため
		// （実際の並び替え結果はリンク先URLへの移動そのもので検証できるので、
		// クリック操作自体の安定性に依存しない形にしている）。
		const ascHref = await clientColumnHeader.getAttribute('href');
		expect(ascHref).toContain('orderby=bill_client_name');
		await page.goto(ascHref);

		// 昇順（初回クリック相当）で、取引先未設定の見積書
		// （issue295取引先未設定の見積書）が一覧から消えずに表示され続けることを確認する
		// （植草さんの必須要件）。
		await expect(
			page.locator('.row-title', { hasText: 'issue295取引先未設定の見積書' })
		).toBeVisible();

		// aria-sort 属性が WordPress コアによって付与されていることを確認する
		// （自前で並び替えリンクを組み立てるとこの属性が欠落するため）。
		await expect(page.locator('th#bill_client_name')).toHaveAttribute('aria-sort', /ascending|descending/);

		// 見出しのリンク先（次にクリックすると切り替わる降順）へ移動しても、
		// 同じ書類が消えないことを確認する
		const descHref = await page.locator('#bill_client_name a').getAttribute('href');
		expect(descHref).toContain('order=desc');
		await page.goto(descHref);
		await expect(
			page.locator('.row-title', { hasText: 'issue295取引先未設定の見積書' })
		).toBeVisible();
	});
});
