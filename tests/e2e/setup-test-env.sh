#!/usr/bin/env bash
#
# e2e テスト用の WordPress 環境（wp-env）を準備する。
#
# 実行内容（この順番で行う）:
#   1. このリポジトリのテーマ（BillVektor）を有効化する
#   2. tests/e2e/create-test-data*.php を全て実行してテストデータを投入する
#
# テーマを有効化してからデータを投入する理由:
#   データ作成スクリプトはテーマの関数（bill_vektor_invoice_total_tax() など）や
#   カスタム投稿タイプ・メタ保存フックに依存する。標準テーマが有効なまま投入すると
#   スクリプトが途中で止まったり、メタが保存されなかったりして、
#   e2e が環境起因で失敗する（issue #360）。
#
# 使い方（事前に npx wp-env start で wp-env を起動しておく）:
#   npm run e2e:setup
#
# 各データ作成スクリプトは冪等に作られているため、何度実行しても同じ状態になる。
set -euo pipefail

# リポジトリのルートへ移動する（どこから呼ばれても同じ結果にする）
cd "$(dirname "$0")/../.."

# ワークツリー（作業用の別ディレクトリ）などでディレクトリ名が bill-vektor 以外でも動くよう、
# ディレクトリ名からテーマ名を求める
THEME_DIR="$(basename "$PWD")"
ENV_CWD="wp-content/themes/${THEME_DIR}"

echo "==> テーマ ${THEME_DIR} を有効化します"
npx wp-env run cli wp theme activate "${THEME_DIR}"

# 各スクリプトは互いに独立しているため、実行順に依存しない
for script in tests/e2e/create-test-data*.php; do
	echo "==> テストデータ投入: ${script}"
	npx wp-env run cli --env-cwd="${ENV_CWD}" wp eval-file "${script}"
done

echo "==> 完了。続けて npx playwright test で e2e を実行できます（ポートを変えている場合は WP_BASE_URL を指定）"
