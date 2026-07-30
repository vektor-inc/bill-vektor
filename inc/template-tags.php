<?php
/*
	bill_form_post_value()

	8桁の数字で保存されているデータをUnixタイムスタンプに変換
	bill_raw_date()

	bill_item_number()
	bill_item_price_total()

	書類の税抜き合計
	bill_total_no_tax()

	消費税を計算
	bill_tax()


	Chack post type info
	bill_get_post_type()

	bill_get_terms()
/*-------------------------------------------*/


function bill_form_post_value( $post_field, $type = false ) {
		$value = '';
		global $post;
	if ( isset( $post_field ) && $post_field ) {
		if ( isset( $type ) && $type == 'textarea' ) {
			// n2brはフォームにbrがそのまま入ってしまうので入れない
			$value = esc_textarea( $post_field );
		} else {
			$value = esc_attr( $post_field );
		}
	} elseif ( isset( $post->$post_field ) && $post->$post_field ) {
		$value = $post->$post_field;
	}
		return $value;
}


// 8桁の数字で保存されているデータをUnixタイムスタンプに変換
function bill_raw_date( $date ) {
	$year     = substr( $date, 0, 4 );
	$month    = substr( $date, 4, 2 );
	$day      = substr( $date, 6, 2 );
	$raw_date = strtotime( $year . '-' . $month . '-' . $day . ' 00:00:00' );
	return $raw_date;
}

function bill_item_number( $number = 0 ) {
	// 全角を半額に変換
	$number = mb_convert_kana( $number, 'a' );
	// , が入ってたら除去
	$number = str_replace( ',', '', $number );
	return $number;
}

/**
 * 単価を計算
 */
function bill_vektor_invoice_unit_plice( $price, $tax_rate, $tax_type ) {

	// 税込価格の場合は税抜価格を算出して返し、そうでない場合はそのまま返す
	if ( 'tax_included' === $tax_type ) {
		$unit_price = round( $price / ( 1 + $tax_rate ) );
	} elseif ( 'tax_included_ceil' === $tax_type ) {
		$unit_price = ceil( $price / ( 1 + $tax_rate ) );
	} elseif ( 'tax_included_floor' === $tax_type ) {
		$unit_price = floor( $price / ( 1 + $tax_rate ) );
	} else {
		$unit_price = $price;
	}

	return $unit_price;
}

/**
 * 品目ごとの合計金額を計算
 */
function bill_vektor_invoice_total_plice( $unit_price, $count ) {

	$total_price = $unit_price * $count;

	return $total_price;
}

/**
 * 品目ごとの消費税額計算
 */
function bill_vektor_invoice_tax_plice( $total_price, $tax_rate ) {

	$tax_price = $total_price * $tax_rate;

	return $tax_price;
}

/**
 * 品目1件分の消費税額を計算する共通ヘルパー
 *
 * 税込・税抜の入力種別によって消費税額の算出方法が異なるため、その分岐をここに集約する。
 * - 税込入力（tax_included, tax_included_ceil, tax_included_floor）：
 *   「元の税込合計（入力された単価 × 個数）－ 税抜合計」で消費税を確定する。
 *   税込→税抜変換時に発生する端数処理を二重にかけないための計算方法。
 * - 税抜入力（上記以外）：「税抜合計 × 税率」で消費税を算出する。
 *
 * この関数は端数処理（四捨五入・切り上げ・切り捨て）を一切行わず、算出した生の値をそのまま返す。
 * - table-price.php（品目テーブルの表示）では、返り値を品目ごとに即座に表示用の確定値として使用する。
 * - bill_vektor_invoice_each_tax()（合計表の計算）では、返り値を小数のまま税率ごとに合算し、
 *   合算後に bill_tax_fraction の設定に従って一括で丸め処理を行う。
 * 呼び出し元の用途に応じて丸め処理のタイミングが異なるため、丸め処理はこの関数の責務に含めない。
 *
 * @param string $tax_type       品目の税込・税抜種別（tax_included, tax_included_ceil, tax_included_floor, tax_excluded 等）。
 * @param float  $original_price 入力された元の単価（税込・税抜変換前の値）。
 * @param float  $count          個数。
 * @param float  $total_price    税抜合計金額（税抜単価 × 個数）。
 * @param float  $tax_rate       消費税率（小数、例: 10% の場合は 0.1）。
 * @return float $tax_value 品目1件分の消費税額（未丸め）。
 */
function bill_vektor_invoice_item_tax( $tax_type, $original_price, $count, $total_price, $tax_rate ) {
	if ( in_array( $tax_type, array( 'tax_included', 'tax_included_ceil', 'tax_included_floor' ), true ) ) {
		// 税込入力：元の税込合計から税抜合計を引いた値が消費税
		$original_total = $original_price * $count;
		$tax_value      = $original_total - $total_price;
	} else {
		// 税抜入力：税抜合計 × 税率
		$tax_value = bill_vektor_invoice_tax_plice( $total_price, $tax_rate );
	}

	return $tax_value;
}

/**
 * 品目ごとの税込金額計算
 */
function bill_vektor_invoice_full_plice( $total_price, $tax_price ) {

	$full_price = $total_price + $tax_price;

	return $full_price;
}

/**
 * 消費税率を処理
 *
 * @param string $tax_rate  現在設定されている税率
 * @param int    $old_tax_rate 過去に設定された全項目一括指定の税率
 * @param string $post_date 投稿日時
 *
 * @return string $tax_rate 税率
 */
function bill_vektor_fix_tax_rate( $old_tax_rate, $post_date ) {
	// 旧バージョンでの全項目一括指定の税率がある場合はそれの値を反映
	if ( ! empty( $old_tax_rate ) ) {
		$tax_rate = $old_tax_rate . '%';
	} else {
		// 書類の投稿日を取得取得
		$post_date = date( $post_date );
		// 消費税率が 10% にかわった日時
		$ten_start = date( '2019-10-01 00:00:00' );
		// 投稿日時によって税率を指定
		if ( strtotime( $post_date ) >= strtotime( $ten_start ) ) {
			$tax_rate = '10%';
		} else {
			$tax_rate = '8%';
		}
	}
	return $tax_rate;
}

/**
 * 税込・税抜を処理
 *
 * @param string $tax_type     現在設定されている税込・税抜
 * @param string $old_tax_type 過去に設定された税込・税抜
 *
 * @return string $tax_type 税込・税抜
 */
function bill_vektor_fix_tax_type( $old_tax_type ) {
	if ( 'tax_not_auto' === $old_tax_type ) {
		$tax_type = 'tax_included';
	} else {
		$tax_type = 'tax_excluded';
	}
	return $tax_type;
}

/**
 * インボイス対応の税率ごとの合計金額
 *
 * @param WP_Post $post 投稿オブジェクト。
 * @return array $tax_total 税率ごとの合計金額。
 *               各要素は rate（税率ラベル）・price（税抜合計）・tax（消費税額）・total（税込合計）を持つ配列。
 */
function bill_vektor_invoice_each_tax( $post ) {
	// カスタムフィールドを取得
	$bill_items = get_post_meta( $post->ID, 'bill_items', true );
	// 消費税率の配列
	$tax_array = bill_vektor_tax_array();
	// 税率ごとに税込み金額・消費税額・合計金額を算出した配列を初期化
	$tax_total       = array();
	$final_tax_total = array();
	// 古い消費税率
	$old_tax_rate = get_post_meta( $post->ID, 'bill_tax_rate', true );
	// 古い税込・税抜
	$old_tax_type = get_post_meta( $post->ID, 'bill_tax_type', true );
	// 消費税の丸め処理（税抜入力品目の消費税合算値に適用される）
	$tax_fraction = ! empty( get_post_meta( $post->ID, 'bill_tax_fraction', true ) ) ? get_post_meta( $post->ID, 'bill_tax_fraction', true ) : 'round';

	if ( is_array( $bill_items ) ) {

		// 行のループ
		foreach ( $bill_items as $bill_item ) {
			// 品目毎の税率の指定がない場合
			if ( empty( $bill_item['tax-rate'] ) ) {
				// 税率を取得
				$bill_item['tax-rate'] = bill_vektor_fix_tax_rate( $old_tax_rate, $post->post_date );
			}
			// 品目毎の税別・税込みの指定がない場合
			if ( empty( $bill_item['tax-type'] ) ) {
				$bill_item['tax-type'] = bill_vektor_fix_tax_type( $old_tax_type );
			}

			// すべてが埋まっていない行は算出対象外に
			if ( ! empty( $bill_item['name'] ) &&
				! empty( $bill_item['count'] ) &&
				// ! empty( $bill_item['unit'] ) &&
				! empty( $bill_item['price'] ) &&
				! empty( $bill_item['tax-rate'] ) &&
				! empty( $bill_item['tax-type'] )
			) {
				// 税率ごとのループ
				foreach ( $tax_array as $tax_rate ) {
					// 税率のループとカスタムフィールドのループが同じ値の場合
					if ( $bill_item['tax-rate'] === $tax_rate ) {

						// 税率を数値に変換
						$item_tax_rate = 0.01 * intval( str_replace( '%', '', $bill_item['tax-rate'] ) );

						// 入力された元の単価を数値に変換（税込・税抜変換前の値）
						$item_original_price = bill_item_number( $bill_item['price'] );

						// 単価を税抜価格に変換（税込入力の場合は税込→税抜に変換、税抜入力はそのまま）
						$item_price = bill_vektor_invoice_unit_plice( $item_original_price, $item_tax_rate, $bill_item['tax-type'] );

						// 個数を数値に変換
						$item_count = bill_item_number( $bill_item['count'] );

						// 上記３つが数値なら
						if ( is_numeric( $item_count ) && is_numeric( $item_price ) && is_numeric( $item_tax_rate ) ) {

							// 税抜合計金額を算出（税抜単価 × 個数）
							$item_total = bill_vektor_invoice_total_plice( $item_price, $item_count );

							// 消費税額の計算方法を税込・税抜によって分岐する処理は共通ヘルパーに集約している
							// （table-price.php の品目ごとの消費税額表示と同じロジックを使用）。
							// ここではまだ丸め処理をかけず、小数のまま保持する。
							// 税率ごとに合算した後、下記のループで bill_tax_fraction により一括で丸め処理を行う。
							$item_tax_value = bill_vektor_invoice_item_tax( $bill_item['tax-type'], $item_original_price, $item_count, $item_total, $item_tax_rate );

							// 税率何％の対象か
							$tax_total[ $tax_rate ]['rate'] = $bill_item['tax-rate'] . '対象';
							// 対象税率の税抜き合計金額
							$tax_total[ $tax_rate ]['price'] = ! empty( $tax_total[ $tax_rate ]['price'] ) ? $tax_total[ $tax_rate ]['price'] + $item_total : $item_total;
							// 対象税率の消費税額
							$tax_total[ $tax_rate ]['tax'] = ! empty( $tax_total[ $tax_rate ]['tax'] ) ? $tax_total[ $tax_rate ]['tax'] + $item_tax_value : $item_tax_value;
						}
					}
				}
			}
		}
		// 出来上がった配列の消費税と合計金額を調整
		foreach ( $tax_total as $tax_key => $tax_value ) {
			// 消費税の丸め処理
			// $tax_fraction には floor, round, ceil のいずれかが入っているので call_user_func でその関数を直接呼び出している
			// 税込入力品目の消費税は整数で確定済みのため、この丸め処理は主に税抜入力品目の小数分に効く
			$tax_total[ $tax_key ]['tax'] = call_user_func( $tax_fraction, $tax_value['tax'] );
			// 税抜金額と消費税から税込み金額を算出
			$tax_total[ $tax_key ]['total'] = $tax_value['price'] + $tax_total[ $tax_key ]['tax'];
		}

		// 税率の高い順に一応並び替え
		foreach ( $tax_array as $tax_rate ) {
			if ( ! empty( $tax_total[ $tax_rate ]['rate'] ) &&
				! empty( $tax_total[ $tax_rate ]['price'] ) &&
				! empty( $tax_total[ $tax_rate ]['tax'] || 0.0 === $tax_total[ $tax_rate ]['tax'] ) &&
				! empty( $tax_total[ $tax_rate ]['total'] ) &&
				$tax_rate . '対象' === $tax_total[ $tax_rate ]['rate']
			) {
				$final_tax_total[ $tax_rate ]['rate']  = $tax_total[ $tax_rate ]['rate'];
				$final_tax_total[ $tax_rate ]['price'] = $tax_total[ $tax_rate ]['price'];
				$final_tax_total[ $tax_rate ]['tax']   = $tax_total[ $tax_rate ]['tax'];
				$final_tax_total[ $tax_rate ]['total'] = $tax_total[ $tax_rate ]['total'];
			}
		}
		$tax_total = $final_tax_total;
	}

	return $tax_total;
}

/**
 * インボイス対応の合計金額
 * 
 * @param object $post 投稿オブジェクト
 * @return int $bill_total 合計金額
 */
function bill_vektor_invoice_total_tax( $post ) {
	// 税率毎の合計を配列で取得
	$total_array = bill_vektor_invoice_each_tax( $post );
	// 合計金額の初期化
	$bill_total  = 0;
	// 合計金額を算出
	foreach ( $total_array as $tax_value ) {
		// var_dump($tax_value);
		$bill_total = $bill_total + $tax_value['total'];
	}

	return $bill_total;
}

/*
	Chack post type info
	bill_get_post_type()
/*-------------------------------------------*/
function bill_get_post_type() {

	// Get post type slug
	/*-------------------------------------------*/
	global $wp_query;
	if ( is_post_type_archive() || $wp_query->query_vars['post_type'] ) {
		$post_type['slug'] = $wp_query->query_vars['post_type'];
	} elseif ( is_tax() || is_category() ) {
		$taxonomy = get_queried_object()->taxonomy;
		if ( $taxonomy ) {
			$post_type['slug'] = get_taxonomy( $taxonomy )->object_type[0];
		}
	} elseif ( is_front_page() ) {
		$post_type['slug'] = 'post';
	} else {
		$post_type['slug'] = 'post';
	}

	if ( ! post_type_exists( $post_type['slug'] ) ) {
		$post_type['slug'] = 'post';
	}

	// Get post type name
	/*-------------------------------------------*/
	$post_type_object = get_post_type_object( $post_type['slug'] );

	$post_type['name'] = esc_html( $post_type_object->labels->name );
	$post_type['url']  = home_url() . '/?post_type=' . $post_type['slug'];

	return $post_type;
}

/*
	bill_get_terms()
/*-------------------------------------------*/
function bill_get_terms() {
	global $post;
	$postType = get_post_type();
	if ( $postType == 'post' ) {
		$taxonomySlug = 'category';
	} else {
		$taxonomies = get_the_taxonomies();
		// print '<pre style="text-align:left">';print_r($taxonomies);print '</pre>';
		if ( $taxonomies ) {
			foreach ( $taxonomies as $taxonomySlug => $taxonomy ) {
			}
		} else {
			$taxonomySlug = '';
		}
	}

	$taxo_catelist = get_the_term_list( $post->ID, $taxonomySlug, ' ', ', ', '' );
	return $taxo_catelist;
}

function bill_get_client_name( $post ) {
	if ( $post->bill_client_name_manual ) {
		$client_name = $post->bill_client_name_manual;
	} else {
		$client_name = get_the_title( $post->bill_client );
	}
	return $client_name;
}

/**
 * 投稿IDまたは投稿オブジェクトから書類の取引先名を取得する
 *
 * 管理画面の投稿一覧（manage_{$post_type}_posts_custom_column）のように
 * 投稿IDしか受け取れない箇所から取引先名を取得するためのラッパー。
 * 取引先名の組み立ては bill_get_client_name() に委譲するため、
 * 書類本体・PDFタイトル・CSVエクスポートと必ず同じ結果になる。
 *
 * @param int|WP_Post $post 書類の投稿IDまたは投稿オブジェクト。
 * @return string 取引先名。取引先が未設定の場合や投稿が存在しない場合は空文字。
 */
function bill_get_client_name_by_post( $post ) {
	/*
	 * 空の値を get_post() に渡すとグローバルの $post が返るため、
	 * 意図しない書類の取引先名を返さないよう先に判定する。
	 */
	if ( empty( $post ) ) {
		return '';
	}

	// 投稿IDでも投稿オブジェクトでも受け取れるように WP_Post に正規化する
	$post = get_post( $post );

	// 投稿が存在しない場合は空文字を返す
	if ( ! $post instanceof WP_Post ) {
		return '';
	}

	/*
	 * 取引先（イレギュラー）・取引先（登録済）ともに未設定の場合は空文字を返す。
	 * bill_get_client_name() は内部で get_the_title( $post->bill_client ) を呼ぶが、
	 * get_the_title() に空の値を渡すとグローバルの $post が参照されるため、
	 * 管理画面の一覧のようにグローバルの $post がセットされている場所では
	 * 書類自身の件名が取引先名として返ってしまう。それを避けるため事前に判定する。
	 */
	if ( empty( $post->bill_client_name_manual ) && empty( $post->bill_client ) ) {
		return '';
	}

	return (string) bill_get_client_name( $post );
}

function bill_get_client_honorific( $post ) {
	if ( empty( $post->bill_client_name_manual ) ) {
		$client_honorific = esc_html( get_post_meta( $post->bill_client, 'client_honorific', true ) );
		if ( $client_honorific ) {
			echo $client_honorific;
		} else {
			echo '御中';
		}
	}
}

/**
 * 絞り込み検索フォームで指定されたキーワードを取得
 *
 * 書類一覧の絞り込みフォーム（template-parts/search-box.php）から送信されたキーワードを
 * サニタイズして返す。書類一覧の絞り込み（inc/functions-pre-get-posts.php）と
 * CSV エクスポートの抽出条件（inc/export/class.csv-export.php）で同じ値を使うため、
 * 受け取り処理をこの関数に集約している。
 *
 * WordPress 標準の `s` ではなく独自の `bill_keyword` をパラメーター名に使っているのは、
 * `s` を送ると WordPress がそのページを検索結果ページと判定してしまい、
 * index.php の一覧表示の分岐（is_front_page() / is_archive() / is_tax()）から外れて
 * 書類一覧の表組みが表示されなくなるため。
 *
 * 返り値はスラッシュを除去した状態（ユーザーが入力したままの文字列）。
 * WP_Query の検索条件（`s`）へ渡す際は WP_Query 側で stripslashes() されるため、
 * 呼び出し側で wp_slash() を付け直す必要がある点に注意。
 *
 * @return string サニタイズ済みのキーワード。未指定・空文字・空白のみの場合は空文字。
 */
function bill_get_search_keyword() {
	// パラメーター自体が無い場合は絞り込みなしとして空文字を返す。
	// bill_keyword[]=xxx のように配列で渡された場合も PHP エラーを避けるため空文字を返す
	if ( ! isset( $_GET['bill_keyword'] ) || ! is_string( $_GET['bill_keyword'] ) ) {
		return '';
	}

	// sanitize_text_field() はタグ除去・不正なUTF-8の除去に加えて前後の空白も除去するため、
	// 空白のみが入力された場合はこの時点で空文字になる（＝絞り込みなしになる）
	return sanitize_text_field( wp_unslash( $_GET['bill_keyword'] ) );
}

/**
 * 絞り込み検索のキーワード検索対象を書類の件名だけに限定するクエリー引数を返す
 *
 * WordPress 標準のキーワード検索は post_title に加えて post_excerpt・post_content も
 * 対象にするため、そのままでは以下の2点で絞り込みフォームの用途と食い違う。
 * 1. 件名に含まないキーワードで書類がヒットしてしまう。
 * 2. 検索語がある場合は「件名に一致する投稿を優先する並べ替え」が ORDER BY の先頭に
 *    挿入されるため、書類一覧の発行日順の並びが崩れる。
 * 検索対象を件名だけに絞ると全件が件名一致になるため、どちらも解消される。
 *
 * WP_Query の `search_columns` 引数（WordPress 6.2 以降）を使う。
 * post_search_columns フィルターと違ってクエリー単位の指定なので、
 * 他の検索処理に影響することがない。
 * 6.2 より前のバージョンでは未知の引数として無視され、
 * 従来どおり本文・抜粋も検索対象になるだけなので致命的な問題は起きない。
 *
 * @return string[] WP_Query の search_columns に渡すカラム名の配列。
 */
function bill_get_search_columns() {
	return array( 'post_title' );
}
