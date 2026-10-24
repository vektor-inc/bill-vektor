[![Build Status](https://travis-ci.org/vektor-inc/bill-vektor.svg?branch=master)](https://travis-ci.org/vektor-inc/bill-vektor)

# BillVektor - 見積書・請求書管理システム -

請求書をエクセルで管理して、後から過去の請求書を探したり、請求書が入金・入力済なのか未処理なのか整理して管理するのが面倒ではありませんか？
BillVektorは請求書の管理をWordPressのテーマとして実装したオープンソースの請求書管理システムです。

## BillVektorでできる事

* 見積書の発行・管理
* 請求書の発行・管理（見積書データを元に簡単発行）
* 送付状の発行
* 請求書データからMFクラウド会計インポート用の仕分帳CSVデータ出力

## クラウドの他社請求書管理サービスとの違い

### 長所

* 郵送機能がない = 自分で手作業なので、任意の封筒・切手・同封書類が使える
* ウェブ制作者なら請求書のレイアウト・デザイン（コード）を自在にカスタマイズできる
* ウェブ制作者なら独自に欲しい機能を自力で拡張できる

### 短所

* ウェブサーバーでの運用の場合激しくセキュリティーやバックアップを厳重にする必要がある
* そもそもインストールが面倒
* 郵送機能は流石にない

## e2e テスト（Playwright）の実行手順

e2e テストは `tests/e2e` にあり、wp-env（Docker 上のローカル WordPress）に対して Playwright で実行します。
**テストデータはテーマ（BillVektor）が有効な状態で投入する必要があります。** テストデータ作成スクリプトがテーマの関数や投稿タイプに依存するためです（標準テーマのまま投入すると、スクリプトが途中で止まります）。この順番は `npm run e2e:setup` が守るので、次の手順をそのまま実行してください。

```bash
# 1. 依存パッケージのインストールと wp-env の起動（ポートを変える場合は .wp-env.override.json に port / testsPort を書く）
npm install
npx wp-env start

# 2. テーマの有効化 → テストデータの投入（tests/e2e/create-test-data*.php を全て実行）
npm run e2e:setup

# 3. e2e の実行（WP_BASE_URL は wp-env の port に合わせる。既定は http://localhost:8895）
WP_BASE_URL=http://localhost:8888 npm run e2e
```

* `npm run e2e:setup` は何度実行しても同じ状態になります（各データ作成スクリプトが冪等なため）。
* 管理者のログイン情報を変えている場合は、環境変数 `WP_TEST_USERNAME` / `WP_TEST_PASSWORD` で指定します（既定は admin / password）。
