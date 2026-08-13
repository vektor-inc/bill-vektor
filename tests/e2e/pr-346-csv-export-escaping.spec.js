// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
const { requireTestDataPresent } = require('./require-test-data');
const { AUTH_STATE_PATH, withAuthenticatedPage } = require('./auth-helpers');

/**
 * PR #346（issue #313）CSV エクスポートの出力値エスケープ処理の e2e テスト。
 *
 * PR #346 は、CSV へ書き出す各マスの組み立てを共通処理
 * CsvExport::format_csv_cell() に統一した修正（CSVインジェクション対策・
 * " の二重化・esc_html() 除去による & 化けの解消）。
 * PHPUnit（tests/test-csv-export.php）は format_csv_cell() 単体のテストまでしか
 * 担保できない。export_csv() は最後に die() するため PHPUnit から直接は
 * 呼べず、各出力箇所が実際にこの共通処理を経由しているか（置き換え漏れが
 * 無いか）は検証できていない。このスペックは、画面のエクスポートボタンから
 * 実際に CSV をダウンロードし、共通処理を通った出力になっていることを確認する。
 *
 * 実行前に、参照する書類を作成しておく必要がある。
 * テーマのディレクトリ（このリポジトリのルート）で実行する。
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-346.php
 *   npx playwright test tests/e2e/pr-346-csv-export-escaping.spec.js
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める。
 *
 * wp-env のポートを既定（8895）から変えている場合は WP_BASE_URL で指定する。
 *   WP_BASE_URL=http://localhost:9131 npx playwright test tests/e2e/pr-346-csv-export-escaping.spec.js
 *
 * データ作成スクリプトは同じ件名の書類が既にあれば作成をスキップするため、
 * 何度実行しても行が増えない。
 *
 * bill-vektor テーマはログインが必要なため、
 * global-setup.js で取得したログイン済み storageState を使い回す。
 * nonce が必要なエンドポイントのため、画面のエクスポートボタンから実際に
 * 遷移させてダウンロードを取得する（issue-299 のダウンロード方式と同じ）。
 */

test.use({ storageState: AUTH_STATE_PATH });

const LIST_PATH = '/wp-admin/edit.php?post_type=post';

// データ作成スクリプト未実行の環境で、各テストが既定タイムアウトを積み重ねて
// 落ちるのを防ぐため、前提データ（書類）が存在するかを先に確認する。
const SETUP_HINT =
	'PR #346 のテストデータを作成してから実行してください:\n' +
	'  npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-346.php';

test.beforeAll(async ({ browser }) => {
	await withAuthenticatedPage(browser, (page) =>
		requireTestDataPresent(
			page,
			`${LIST_PATH}&s=${encodeURIComponent('PR346請求書A_数式取引先')}`,
			(p) => p.locator('.row-title', { hasText: 'PR346請求書A_数式取引先' }),
			'PR #346 の書類「PR346請求書A_数式取引先」',
			SETUP_HINT
		)
	);
});

/**
 * 要素をページ内でネイティブに click する。
 *
 * Playwright の locator.click() は要素の位置が安定しているかを
 * requestAnimationFrame で確認するが、負荷の高い環境では rAF が滞って
 * クリックがハングすることがある（issue-299 のスペックと同じ理由）。
 *
 * @param {import('@playwright/test').Locator} locator
 */
async function clickNative(locator) {
	await locator.evaluate((element) => {
		if (element instanceof HTMLElement) {
			element.click();
		}
	});
}

/**
 * エクスポートボタンを実際に押して CSV をダウンロードし、本文を返す。
 *
 * トップページのエクスポート欄は検索条件（発行日・取引先・キーワード）と
 * 同じ GET フォーム内にあり、_wpnonce も hidden フィールドで一緒に送出される
 * ため、ボタンを押すだけで nonce 付きのリクエストになる（issue-299 と同じ経路）。
 *
 * @param {import('@playwright/test').Page} page
 * @param {RegExp} buttonName
 * @param {'utf8'|'shift_jis'} encoding
 */
async function downloadCsv(page, buttonName, encoding) {
	const [download] = await Promise.all([
		page.waitForEvent('download'),
		clickNative(page.getByRole('button', { name: buttonName })),
	]);
	const stream = await download.createReadStream();
	if (!stream) {
		throw new Error('CSV ダウンロードストリームを取得できません。');
	}
	const chunks = [];
	for await (const chunk of stream) {
		chunks.push(chunk);
	}
	const buffer = Buffer.concat(chunks);
	return encoding === 'shift_jis'
		? new TextDecoder('shift_jis').decode(buffer)
		: buffer.toString('utf8');
}

/**
 * CSV の1行を、ダブルクォート二重化を考慮してセル配列にパースする。
 *
 * このテーマの CSV 出力は CsvExport::format_csv_cell() により、すべてのマスが
 * 必ず " で囲まれ、値中の " は "" に二重化される（PR #346 の共通化）。
 * その前提に沿って、マスの区切り（終端の " の直後のカンマ）とマス内の ""
 * を正しく読み分け、値を復元する。
 *
 * @param {string} line CSV の1行（末尾の改行は含まない）。
 * @return {string[]} マスごとの復元済み値（前後の " を外し、"" を " に戻したもの）。
 */
function parseCsvLine(line) {
	const cells = [];
	let i = 0;
	const len = line.length;
	while (i < len) {
		if (line[i] !== '"') {
			// この行のマスがすべて " で囲まれている前提が崩れている
			// （format_csv_cell() を通っていないマスが混ざっている可能性）
			throw new Error(
				`CSV のマスが " で始まっていません（position ${i}）: ${line}`
			);
		}
		i++; // 開始の " を読み飛ばす
		let value = '';
		while (i < len) {
			if (line[i] === '"') {
				if (line[i + 1] === '"') {
					value += '"';
					i += 2;
				} else {
					i++; // 終端の " を読み飛ばす
					break;
				}
			} else {
				value += line[i];
				i++;
			}
		}
		cells.push(value);
		if (line[i] === ',') {
			i++;
		}
	}
	return cells;
}

/**
 * CSV 本文の中から、指定した目印文字列を含む行をすべて探し、パースして返す。
 *
 * MF クラウド会計用 CSV は同じ書類につき「売掛金用のレコード」と「入金用の
 * レコード」の2行が出力され、どちらの摘要欄にも書類の件名が含まれるため、
 * 目印だけでは行を一意に特定できない。呼び出し側で借方勘定科目などの
 * 列値を見て、目的の行を選び分けること。
 *
 * @param {string} csvText CSV 全体の本文。
 * @param {string} marker  行を特定する目印（他の行と衝突しない文字列。書類の件名など）。
 * @return {string[][]} 見つかった行それぞれをマス配列にしたもの。
 */
function findRowsContaining(csvText, marker) {
	return csvText
		.split('\r\n')
		.filter((line) => line.includes(marker))
		.map(parseCsvLine);
}

/**
 * freee 用 CSV から、指定した書類の行を1件だけ取得する。
 *
 * freee 用 CSV は書類1件につき（税率グループが1つなら）1行しか出力されない
 * ため、目印に一致する行が複数あればテストデータ側の想定が崩れている。
 *
 * @param {string} csvText
 * @param {string} marker
 * @return {string[]}
 */
function findFreeeRow(csvText, marker) {
	const rows = findRowsContaining(csvText, marker);
	expect(rows.length, `freee用CSVに「${marker}」を含む行が1件だけ見つかること`).toBe(1);
	return rows[0];
}

test.describe('PR #346: CSV エクスポートの出力値エスケープ処理（format_csv_cell 共通化）', () => {
	test('freee用CSV: 取引先名が "=1+1"（数式）の場合、取引先欄の先頭に \' が付いて無害化される', async ({
		page,
	}) => {
		await page.goto('/');
		const csv = await downloadCsv(page, /freee用CSVエクスポート/, 'utf8');

		const row = findFreeeRow(csv, 'PR346請求書A_数式取引先');
		// 収支区分,管理番号,発生日,支払期日,取引先,勘定科目,税区分,金額,... の17列で、列がずれていないこと
		expect(row.length).toBe(17);
		// 取引先列（index 4）が、元の値の先頭に ' を付けた形になっていること
		expect(row[4]).toBe("'=1+1");
		// 品目列（index 11）は書類の件名そのものであること（列ずれの追加確認）
		expect(row[11]).toBe('PR346請求書A_数式取引先');
		// CSV の生テキストにも、PR本文が示す想定どおりの表記で現れること
		expect(csv).toContain('"\'=1+1"');
	});

	test('freee用CSV: 取引先名に " を含む場合、"" に二重化され列がずれない', async ({
		page,
	}) => {
		await page.goto('/');
		const csv = await downloadCsv(page, /freee用CSVエクスポート/, 'utf8');

		const row = findFreeeRow(csv, 'PR346請求書B_ダブルクォート取引先');
		expect(row.length).toBe(17);
		// パース結果（"" → " に復元済み）が元の値と一致すること
		expect(row[4]).toBe('テスト"商事');
		expect(row[11]).toBe('PR346請求書B_ダブルクォート取引先');
		// CSVの生テキスト上でも " が2つに増えた形で書き出されていること
		expect(csv).toContain('"テスト""商事"');
	});

	test('freee用CSV: 取引先名に & を含む場合、&amp; に化けずそのまま出力される（esc_html() 除去の確認）', async ({
		page,
	}) => {
		await page.goto('/');
		const csv = await downloadCsv(page, /freee用CSVエクスポート/, 'utf8');

		const row = findFreeeRow(csv, 'PR346請求書C_アンパサンド取引先');
		expect(row.length).toBe(17);
		expect(row[4]).toBe('A&B商事');
		// 旧不具合の再発防止: & が &amp; に化けていないこと
		expect(csv).not.toContain('A&amp;B商事');
	});

	test('freee用CSV: 金額欄には \' が付かず、カンマ区切りの数値として扱われる', async ({
		page,
	}) => {
		await page.goto('/');
		const csv = await downloadCsv(page, /freee用CSVエクスポート/, 'utf8');

		const row = findFreeeRow(csv, 'PR346請求書D_通常取引先');
		// 金額列（index 7）。テストデータの品目（10,000円 + 消費税10%）で 11,000 になる想定
		expect(row[7]).toBe('11,000');
		// 通常の取引先名も従来どおり変化なく出力されること（デグレ確認）
		expect(row[4]).toBe('株式会社PR346通常取引先');
	});

	test('MFクラウド会計用CSV: 文字化けせず列がずれず、共通処理（format_csv_cell）を経由している', async ({
		page,
	}) => {
		await page.goto('/');
		const csv = await downloadCsv(page, /MFクラウド会計用CSVエクスポート/, 'shift_jis');

		// 「売掛金用のレコード」（借方勘定科目が "売掛金" の行）を、
		// 同じ書類の「入金用のレコード」（借方勘定科目が "普通預金"）と区別して特定する
		const rowsA = findRowsContaining(csv, 'PR346請求書A_数式取引先');
		const invoiceRowA = rowsA.find((cells) => cells[2] === '売掛金');
		expect(invoiceRowA, 'MF用CSVに売掛金用レコードの行が見つかること').toBeTruthy();
		// 取引No,取引日,借方勘定科目,...,最終更新者 の27列で、列がずれていないこと
		expect(invoiceRowA.length).toBe(27);
		// 借方取引先（index 5）・貸方取引先（index 13）のどちらも先頭に ' が付いて無害化されること
		expect(invoiceRowA[5]).toBe("'=1+1");
		expect(invoiceRowA[13]).toBe("'=1+1");
		// 借方金額(円)（index 8）・貸方金額(円)（index 16）には ' が付かないこと
		expect(invoiceRowA[8]).toBe('11,000');
		expect(invoiceRowA[16]).toBe('11,000');

		// 通常の取引先名（PR346請求書D）で、SJIS 変換を経ても文字化けせず、
		// 列もずれていないことを確認する（デグレ確認）
		const rowsD = findRowsContaining(csv, 'PR346請求書D_通常取引先');
		const invoiceRowD = rowsD.find((cells) => cells[2] === '売掛金');
		expect(invoiceRowD, 'MF用CSVに通常取引先の売掛金用レコードの行が見つかること').toBeTruthy();
		expect(invoiceRowD.length).toBe(27);
		expect(invoiceRowD[5]).toBe('株式会社PR346通常取引先');
		expect(invoiceRowD[13]).toBe('株式会社PR346通常取引先');
	});

	test('レスポンスヘッダーに Content-Disposition: attachment と X-Content-Type-Options: nosniff が付く', async ({
		page,
	}) => {
		// トップページのエクスポートボックスから nonce の hidden フィールド値を取得し、
		// 実際にエクスポートに使われているものと同じ nonce で直接リクエストする
		// （issue-299-csv-export-auth.spec.js と同じ手法）。
		await page.goto('/');
		const nonce = await page
			.locator('.export-box input[name="_wpnonce"]')
			.first()
			.inputValue();
		expect(nonce).toBeTruthy();

		for (const action of ['csv_freee', 'csv_mf']) {
			const response = await page.request.get(
				`/?action=${action}&_wpnonce=${encodeURIComponent(nonce)}`
			);
			expect(response.ok()).toBe(true);

			const contentDisposition = response.headers()['content-disposition'] || '';
			expect(contentDisposition).toContain('attachment');
			expect(contentDisposition).toContain('filename=export.csv');

			expect(response.headers()['x-content-type-options']).toBe('nosniff');
		}
	});
});
