// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { gotoPageContaining } = require('./list-pagination-helpers');

/**
 * PR #311 取引先名フォールバック修正の UI / e2e テスト。
 *
 * 実行前（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 * npx wp-env run cli wp eval-file "wp-content/themes/$(basename "$PWD")/tests/e2e/create-test-data-pr-311.php"
 *
 * 実行後:
 * npx wp-env run cli wp eval-file "wp-content/themes/$(basename "$PWD")/tests/e2e/cleanup-test-data-pr-311.php"
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * $(basename "$PWD") でカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 */

test.use({ storageState: 'tests/e2e/.auth-state.json' });

/**
 * フロント一覧の件名から対象行を取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function frontRow(page, title) {
	return page.locator('#main table tr').filter({
		has: page.locator('td a', { hasText: title }),
	});
}

/**
 * フロント一覧の取引先セルを取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function frontClientCell(page, title) {
	return frontRow(page, title).locator('td').nth(2);
}

/**
 * フロント一覧の件名セルを取得する。
 * 列構成は 書類 / 発行日 / 取引先 / 件名 / カテゴリー の順（0始まり）。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function frontSubjectCell(page, title) {
	return frontRow(page, title).locator('td').nth(3);
}

/**
 * 管理画面の件名から対象行を取得する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 */
function adminEstimateRow(page, title) {
	return page.locator('#the-list tr').filter({
		has: page.locator('.column-title .row-title', { hasText: title }),
	});
}

test.describe('PR #311: 取引先名フォールバック', () => {
	test('フロントの見積書一覧で各取引先パターンを正しく表示する', async ({ page }) => {
		await page.goto('/?post_type=estimate');
		await page.waitForLoadState('networkidle');

		// 未設定・不正値・削除済み ID は、書類自身や無関係な投稿へリンクせずダッシュにする。
		for (const title of [
			'PR311 未設定（メタなし）の見積',
			'PR311 未設定（空文字）の見積',
			'PR311 配列値の見積',
			'PR311 削除済み取引先の見積',
		]) {
			const cell = frontClientCell(page, title);
			await expect(cell.locator('[aria-hidden="true"]')).toHaveText('—');
			await expect(cell.locator('.screen-reader-text')).toHaveText('取引先なし');
			await expect(cell.locator('a')).toHaveCount(0);
			await expect(cell).not.toContainText(title);
			await expect(cell).not.toContainText('Hello world!');
		}

		// 登録済み取引先は省略名を表示し、取引先ページへ別タブリンクを張る。
		const registeredCell = frontClientCell(page, 'PR311 登録済み取引先の見積');
		await expect(registeredCell).toContainText('PR311 テスト社');
		await expect(registeredCell.locator('a')).toHaveCount(1);
		await expect(registeredCell.locator('a')).toHaveAttribute('href', /[?&]client=\d+|\/client\//);
		await expect(registeredCell.locator('a')).toHaveAttribute('target', '_blank');
		// issue #310: window.opener 経由の操作を防ぐ rel="noopener" が付与されていること
		await expect(registeredCell.locator('a')).toHaveAttribute('rel', /\bnoopener\b/);
		// issue #310: 別タブで開くことを読み上げ用テキストで予告していること
		await expect(registeredCell.locator('.screen-reader-text')).toHaveText('（新しいタブで開きます）');

		// 手入力の取引先は文字列だけを表示し、リンクは張らない。
		const manualCell = frontClientCell(page, 'PR311 手入力取引先の見積');
		await expect(manualCell).toHaveText('PR311 手入力の取引先');
		await expect(manualCell.locator('a')).toHaveCount(0);

		// issue #310: 件名リンクにも別タブで開くことの予告（rel="noopener"・アイコン・screen-reader-text）が付与されている
		const subjectCell = frontSubjectCell(page, 'PR311 登録済み取引先の見積');
		const subjectLink = subjectCell.locator('a');
		await expect(subjectLink).toHaveCount(1);
		await expect(subjectLink).toHaveAttribute('target', '_blank');
		await expect(subjectLink).toHaveAttribute('rel', /\bnoopener\b/);
		await expect(subjectLink.locator('.glyphicon-new-window')).toHaveCount(1);
		await expect(subjectLink.locator('.screen-reader-text')).toHaveText('（新しいタブで開きます）');
	});

	test('取引先一覧で名前あり・無題の行をアクセシブルに表示する', async ({ page }) => {
		// issue #322: 取引先一覧は既定の件数でページ送りされるため、他スペックが
		// 作ったデータが積み重なると対象がページ外へ押し出されうる。見つかる
		// ページまで自動で送ることで、他スペックのデータ量に依存しない検証にする。

		// 名前ありの取引先は自身のページへの別タブリンクを維持する。
		// issue #310: 別タブで開くことを screen-reader-text で予告するため、
		// アクセシブルネームにも予告文言が連結される。
		// マークアップ上はテキストノード・aria-hiddenのアイコンspan・screen-reader-textのspanと
		// 複数の子要素が並んでおり、ブラウザのアクセシブルネーム算出は各要素の寄与をスペースで
		// 連結するため、社名と括弧の間に半角スペースが1つ入る（実測値に合わせる）。
		const namedLink = await gotoPageContaining(page, '/?post_type=client', (p) =>
			p.getByRole('link', {
				name: 'PR311 株式会社テスト取引先 （新しいタブで開きます）',
				exact: true,
			})
		);
		await expect(namedLink).toHaveCount(1);
		await expect(namedLink).toHaveAttribute('target', '_blank');
		// issue #310: window.opener 経由の操作を防ぐ rel="noopener" が付与されていること
		await expect(namedLink).toHaveAttribute('rel', /\bnoopener\b/);

		// 無題の取引先は空アンカーにせず、ダッシュと読み上げ用テキストをリンク内に置く。
		// issue #310: 既存の screen-reader-text に「新しいタブで開きます」を合成しているため、
		// アクセシブルネームもその合成後の文言になる。
		//
		// issue #322: 「名称未設定の取引先」という読み上げテキストは、タイトルを空で
		// 保存した取引先を作る他スペック（pr-297 / pr-314 / pr-326 等）とも共通のため、
		// アクセシブルネームだけでは PR311 が作った1件に絞り込めない
		// （フルスイート実行時に他スペックの無題取引先と衝突し、件数が1件を超えて落ちる）。
		// create-test-data-pr-311.php が固定したスラッグ（pr311-nameless-client）を
		// href の末尾（/{スラッグ}/）で厳密に照合することで、他スペックのデータと衝突しない
		// ようにする。
		//
		// コードレビュー指摘:
		// - href*= の部分一致だと、別スペックのスラッグがこちらのスラッグを部分文字列として
		//   含む場合（例: 過去に検討した 'pr311-untitled-client' は pr-314 の
		//   'untitled-client' を含んでいた）に誤ヒットする。href$= で「/スラッグ/」の
		//   末尾一致にし、テーブル内（table.table td）にスコープすることで、他スペックの
		//   スラッグを含む・含まれるどちらの衝突も避ける。
		// - 末尾一致（/スラッグ/）はパーマリンク構造が末尾スラッシュ付きであることが前提。
		//   /%postname% のようにスラッシュ無しの構造を持ち込む DB でも一致するよう、
		//   末尾スラッシュあり・なしの2パターンをカンマ区切りで併記する
		//   （境界一致は保ったまま、どちらの構造にも対応する）。
		// - 孤児データ（前回実行の削除漏れ）が残っていると、WordPress のスラッグ
		//   重複回避により「今回新しく作った本物」の方が 'pr311-nameless-client-2'
		//   のように連番付きへずれる（連番なしの元のスラッグは先に存在する孤児が
		//   持ったまま）。href$= の末尾一致は複数件ヒットこそ防げるが、その代わりに
		//   本物（連番あり）を取りこぼし、孤児（連番なし）の方を検証対象にしてしまう
		//   か、孤児がゴミ箱等で一覧に現れない場合は「見つからない」で落ちる。
		//   つまりこの照合方式は孤児データの存在を前提にしており、
		//   create-test-data-pr-311.php が実行冒頭でマーカー付き投稿を削除してから
		//   作り直す設計（孤児を残さない）に依存している。孤児が残る事故が起きたら、
		//   まずそちらの削除処理を疑うこと。
		const untitledLink = await gotoPageContaining(page, '/?post_type=client', (p) =>
			p.locator(
				'table.table td a[href$="/pr311-nameless-client/"], table.table td a[href$="/pr311-nameless-client"]'
			)
		);
		await expect(untitledLink).toHaveCount(1);
		await expect(untitledLink).toHaveAttribute('target', '_blank');
		await expect(untitledLink).toHaveAttribute('rel', /\bnoopener\b/);
		// コードレビュー指摘: href によるスラッグ特定だけでは、issue #310 が守ろうとした
		// 「ブラウザが実際に算出する読み上げ名（アクセシブルネーム）」の検証が抜け落ちる。
		// toHaveAccessibleName() で退行を確実に検知できるようにする。
		await expect(untitledLink).toHaveAccessibleName('名称未設定の取引先（新しいタブで開きます）');
		/*
		 * issue #310: ダッシュ用のaria-hiddenと新しいタブアイコン用のaria-hiddenの2つが
		 * この順で並ぶ。個数と順序（1つ目=ダッシュ、2つ目=アイコン）の両方を検証することで、
		 * 将来アイコンが先頭に来る配置変更が起きても気づけるようにする。
		 */
		const hiddenSpans = untitledLink.locator('[aria-hidden="true"]');
		await expect(hiddenSpans).toHaveCount(2);
		await expect(hiddenSpans.nth(0)).toHaveText('—');
		await expect(hiddenSpans.nth(1)).toHaveClass(/glyphicon-new-window/);
		await expect(untitledLink.locator('.screen-reader-text')).toHaveText('名称未設定の取引先（新しいタブで開きます）');
		expect((await untitledLink.innerHTML()).trim()).not.toBe('');
	});

	test('取引先未設定の見積単体で件名を取引先欄と title に重複表示しない', async ({ page }) => {
		await page.goto('/?post_type=estimate');
		const row = frontRow(page, 'PR311 未設定（メタなし）の見積');
		const detailLink = row.getByRole('link', { name: 'PR311 未設定（メタなし）の見積' });
		const href = await detailLink.getAttribute('href');
		expect(href).toBeTruthy();

		await page.goto(href);
		await page.waitForLoadState('networkidle');

		// 書類の取引先欄に件名が誤表示されず、空欄になる。
		await expect(page.locator('.bill-wrap')).toBeVisible();
		await expect(page.locator('.bill-destination-client')).toBeEmpty();

		// ブラウザタイトルでも件名は 1 回だけにする。
		const title = await page.title();
		expect(title.split('PR311 未設定（メタなし）の見積')).toHaveLength(2);
	});

	test('管理画面の見積書一覧で取引先あり・未設定を従来どおり表示する', async ({ page }) => {
		await page.goto('/wp-admin/edit.php?post_type=estimate');
		await page.waitForLoadState('networkidle');

		await expect(
			adminEstimateRow(page, 'PR311 登録済み取引先の見積').locator('.column-bill_client_name')
		).toHaveText('PR311 株式会社テスト取引先');

		for (const title of [
			'PR311 未設定（メタなし）の見積',
			'PR311 未設定（空文字）の見積',
			'PR311 配列値の見積',
			'PR311 削除済み取引先の見積',
		]) {
			const cell = adminEstimateRow(page, title).locator('.column-bill_client_name');
			await expect(cell.locator('[aria-hidden="true"]')).toHaveText('—');
			await expect(cell.locator('.screen-reader-text')).toHaveText('取引先なし');
			await expect(cell).not.toContainText(title);
			await expect(cell).not.toContainText('Hello world!');
		}
	});
});
