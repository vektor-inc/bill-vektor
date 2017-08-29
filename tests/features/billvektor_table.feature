Feature: Example Features
####################################################################
# 料金表に関するテスト（請求書）
####################################################################
  Scenario: Table price test - bill

    # スクリーンサイズ1200×800
    Given the screen size is 1200x800

    When I login as the "administrator" role

    ####################################################################
    # 数量に全角を入力したときに正しく計算されるかどうか
    ####################################################################

    # 請求書
    ####################################################################

    # 新規画面に移動
    And I am on "/wp-admin/post-new.php"

    # 項目入力
    # タイトル欄に「表の値のテスト」を入力
    And I fill in "title" with "表の値のテスト"
    And I fill in "bill_items[0][name]" with "全角数字のみ"
    And I fill in "bill_items[0][count]" with "１"
    And I fill in "bill_items[0][price]" with "５０００"
    And I fill in "bill_items[1][name]" with "全角数字全角コンマあり"
    And I fill in "bill_items[1][count]" with "１"
    And I fill in "bill_items[1][price]" with "５，０００"
    And I fill in "bill_items[2][name]" with "半角数字のみ"
    And I fill in "bill_items[2][count]" with "1"
    And I fill in "bill_items[2][price]" with "5000"
    And I fill in "bill_items[3][name]" with "半角数字半角コンマあり"
    And I fill in "bill_items[3][count]" with "1"
    And I fill in "bill_items[3][price]" with "5,000"

    # 公開ボタンをクリック
    And I press "公開"

    # 1秒待つ
    And I wait for 1 seconds

    # 管理画面の投稿を表示をクリック
    And I follow "投稿を表示"

    # スクリーンショットを撮る
    Then take a screenshot and save it to "_out/bill_table_test_0.png"

    # 請求書全体の合計金額が正しく計算されているかどうか
    Then I should see "1" in the "#bill-item-count-0" element

    # 請求書全体の合計金額が正しく計算されているかどうか
    Then I should see "21,600" in the "#bill-frame-total-price" element

    ####################################################################
    # テスト用の投稿を削除
    ####################################################################

      # 請求書一覧画面へ移動
      When I am on "/wp-admin/edit.php"

      # 『表の値のテスト』のテキストリンクをホバー
      And I hover over the "tbody#the-list tr:contains('表の値のテスト')" element

      # 『表の値のテスト』をゴミ箱に移動
      And I follow "ゴミ箱へ移動"


####################################################################
# 料金表に関するテスト(見積書)
####################################################################
  Scenario: Table price test - estimate

    # スクリーンサイズ1200×800
    Given the screen size is 1200x800

    When I login as the "administrator" role

    ####################################################################
    # 数量に全角を入力したときに正しく計算されるかどうか
    ####################################################################

    # 見積書
    ####################################################################

    # 新規画面に移動
    And I am on "/wp-admin/post-new.php?post_type=estimate"

    # 項目入力
    # タイトル欄に「表の値のテスト」を入力
    And I fill in "title" with "表の値のテスト"
    And I fill in "bill_items[0][count]" with "１"
    And I fill in "bill_items[0][price]" with "５０００"

    # 公開ボタンをクリック
    And I press "公開"

    # 1秒待つ
    And I wait for 1 seconds

    # 管理画面の投稿を表示をクリック
    And I follow "投稿を表示"

    # スクリーンショットを撮る
    Then take a screenshot and save it to "_out/bill_estimate_table_test_0.png"

    # 全角で入力した数量が半角に変換されているかどうか
    Then I should see "1" in the "#bill-item-count-0" element

    # 請求書全体の合計金額が正しく計算されているかどうか
    # Then I should see "5400" in the "#bill-total-0" element

    ####################################################################
    # テスト用の投稿を削除
    ####################################################################

      # 請求書一覧画面へ移動
      When I am on "/wp-admin/edit.php?post_type=estimate"

      # 『表の値のテスト』のテキストリンクをホバー
      And I hover over the "tbody#the-list tr:contains('表の値のテスト')" element

      # 『表の値のテスト』をゴミ箱に移動
      And I follow "ゴミ箱へ移動"
