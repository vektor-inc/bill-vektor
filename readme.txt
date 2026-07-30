=== BillVektor ===
Contributors: kurudrive,vektor-inc,rickaddison7634,una9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.12.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== Description ==


GitHub : https://github.com/vektor-inc/BillVektor

== Changelog ==

[ 機能追加 ] 見積書一覧画面に取引先名を表示するカラムを追加
[ 機能追加 ] 書類一覧の絞り込み検索に「キーワード」の入力欄を追加（書類の件名を対象に検索。CSVエクスポートの抽出条件にも反映）
[ 仕様変更 ] 書類一覧の絞り込み検索の「絞り込み」ボタンを「発行日」の下へ移動し、横幅いっぱいの表示に変更
[ 不具合修正 ] 管理画面用CSSの読み込みURLにバージョンのクエリ文字列が付与されておらず、テーマを更新しても古いCSSがブラウザキャッシュから読み込まれる不具合を修正
[ デザイン不具合修正 ] 絞り込み検索が狭い画面幅で1カラムに折り返されず、入力欄が重なって操作できなくなる不具合を修正
[ セキュリティ修正 ] CSVエクスポートに権限チェックとCSRF検証がなく、ログインしていない第三者に請求書データを取得されてしまう問題を修正

1.12.0
[ 機能追加 ] 請求書編集画面に「請求書を複製」ボタンを追加
[ その他 ] 品目の消費税額を計算するロジックを共通関数に集約する内部リファクタリング

1.11.11
[ 不具合修正 ] 見積書編集画面の複製・請求書発行ボタンのリンクにnonceが付与されておらず、クリック時に「リンクの有効期限切れです」エラーになる不具合を修正

1.11.10
[ 不具合修正 ] 配布パッケージのビルド処理で画像ファイルが破損し、ロゴ等の画像が表示されない不具合を修正

1.11.9
[ 不具合修正 ] 品目テーブルの並び替えがタップ全体に反応し、iPad等で入力欄をタップできない不具合を修正（#244）

1.11.8
[ 不具合修正 ] 税込入力の品目で消費税の端数処理が二重に適用され税込合計が1円ずれる不具合を修正
[ 不具合修正 ] PHP 8.xで書類複製時にURLパラメーターが欠落している場合にPHP警告が記録される問題を修正
[ セキュリティ修正 ] 書類複製機能に権限チェックとCSRF検証がなく不正に複製できる問題を修正

1.11.7
[ その他 ] フレキシブルテーブルのカスタムフィールドで select（プルダウン）タイプをサポート

1.11.6
[ 不具合修正 ] 請求書・領収書を印刷すると白紙の2ページ目が出力されてしまうケースを修正（1ページ分の内容のみの場合は自動的に改ページを入れないように調整）。

1.11.5
[ 不具合修正 ] サブディレクトリで運用された場合のリダイレクト不良修正

1.11.4
[ 不具合修正 ] Money Forward インポート用のデータ形式が変更になってインポートできなくなっていたため修正

1.11.3
[ 不具合修正 ] 単位が未入力だと合計金額が 0円になるなる不具合を修正

1.11.2
[ 仕様変更 ]　単位未入力の場合でも数値を表示するように変更
[ 微調整 ]　編集画面での税込 / 税抜及び消費税率の状態を見やすく

1.11.1
[ 不具合修正 ] インボイス対応版での表の項目の並び替え不具合修正

1.11.0
[ 仕様変更 ] 税率に非課税を追加

1.10.0
[ 仕様変更 ] 品目毎に税込単価から税抜単価を計算する際税抜単価の四捨五入・切り上げ・切り捨てが選択可能に
[ 仕様変更 ] 消費税の端数を処理する際の処理をの四捨五入・切り上げ・切り捨てが選択可能に

1.9.1
[ 不具合修正 ] テーブルでPHPエラーが発生していたので修正

1.9.0
[ デザイン調整 ] 請求・見積品目の表示サイズ調整
[ デザイン調整 ] 請求・見積品目の入力欄サイズ調整

1.8.4
[ 不具合修正 ] インボイス番号で最初のTが入力できない不具合を修正

1.8.3
[ 不具合修正 ] 1.8系以降で、過去の書類で消費税未指定の場合に消費税が加算されない不具合を修正

== Copyright ==

BillVektor WordPress theme, Copyright (C) 2017-2023 Vektor,Inc.
BillVektor WordPress theme is licensed under the GPL.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

All photos were taken by Vektor,Inc.