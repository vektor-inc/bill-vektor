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

	現在の一覧が単一の投稿タイプに絞り込まれている場合、そのスラッグを返す
	bill_get_single_post_type_slug()

	「書類」列のセル内容（HTML）を組み立てる
	bill_get_document_type_column()

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

/**
 * 現在表示中の一覧が単一の投稿タイプに絞り込まれている場合、そのスラッグを返す
 *
 * 書類一覧・取引先一覧（index.php）の「書類」列は、単一の投稿タイプに絞り込まれた
 * 一覧（請求書一覧・見積書一覧・取引先一覧）ではリンク先が現在表示中のページ自身に
 * なってしまうため、リンクにせずテキストで表示する必要がある。
 * その判定を「取引先一覧かどうか」のような個別分岐にせず、単一種別に絞り込まれた
 * 一覧すべてに共通で効くようにするため、この関数へロジックを集約する。
 *
 * inc/functions-pre-get-posts.php の bill_custom_home_post_type()（issue #318 / #331 で
 * post_type のサニタイズ方法が変更されている）により、post_type クエリー変数の状態は
 * ページの種類によって次のように変わる。
 * - フロントページ: post_type が文字列かつ sanitize_key() で空文字にならない場合のみ
 *   その投稿タイプ（スラッグの文字列）に絞り込まれる。未指定・配列指定（`post_type[]=xxx`）・
 *   sanitize_key() で空文字に丸められる値（日本語や記号だけの入力など）は、いずれも
 *   既定の混在表示 array( 'post', 'estimate' )（post_type クエリー変数が配列）に
 *   フォールバックする。
 * - フロントページ以外（投稿タイプアーカイブ、カテゴリー／タクソノミーアーカイブ、
 *   年別アーカイブ等）: 文字列指定はページの種類を問わず上書きされる一方、配列指定
 *   （`post_type[]=a&post_type[]=b` 等）はこの関数が上書きしないため WordPress 標準の
 *   挙動がそのまま有効になり、post_type クエリー変数が配列のまま複数の投稿タイプに
 *   絞り込まれることがある。そのため「フロントページ以外は必ず単一の投稿タイプに
 *   絞り込まれる」とは言えない。
 * 本関数は post_type クエリー変数が配列の場合（フロントページの混在表示、フロントページ
 * 以外での複数指定のいずれであっても）を一律「単一に絞り込まれていない」として扱う
 * （下記 is_scalar() のガード）。
 *
 * bill_get_post_type() は不明な場合に 'post' へフォールバックするため、
 * このフォールバック値とフロントページの混在表示（実際は 'post' 判定にはならない）が
 * 区別できず、この判定には使えない。そのためここでは判定できない場合に
 * フォールバックせず空文字を返す。
 *
 * カテゴリー／タクソノミーアーカイブは、そのタクソノミーに紐づく投稿タイプが
 * ちょうど1つの場合のみ「単一の投稿タイプに絞り込まれている」と判定する。
 * 紐づく投稿タイプが複数（または0個）の場合は、どの投稿タイプに絞り込まれているかを
 * 一意に決められないため、絞り込みなし扱い（空文字）にする。
 *
 * @return string 絞り込み対象の投稿タイプスラッグ。混在表示など単一に絞り込まれていない場合、
 *                または投稿タイプが特定できない場合は空文字。
 */
function bill_get_single_post_type_slug() {
	global $wp_query;

	$post_type_query_var = isset( $wp_query->query_vars['post_type'] ) ? $wp_query->query_vars['post_type'] : '';

	if ( is_post_type_archive() || ( is_scalar( $post_type_query_var ) && '' !== (string) $post_type_query_var ) ) {
		// 投稿タイプアーカイブ、または post_type クエリー変数が単一のスラッグ（文字列）の場合。
		// post_type クエリー変数が配列の場合（フロントページの混在表示、フロントページ以外
		// での post_type[]=a&post_type[]=b のような複数指定のいずれも）はここには来ない。
		$slug = $post_type_query_var;
	} elseif ( is_tax() || is_category() ) {
		// カテゴリー／タクソノミーアーカイブは、そのタクソノミーが紐づく投稿タイプに絞り込まれている。
		$queried_object = get_queried_object();

		// get_queried_object() は WP_Term を返すのが通常だが、想定外の状態では
		// それ以外（false 等）を返すことがあるため、taxonomy プロパティへの
		// アクセス前に型を確認して警告を出さないようにする。
		$taxonomy = ( $queried_object instanceof WP_Term ) ? $queried_object->taxonomy : '';

		// get_taxonomy() は未登録のタクソノミー名を渡されると false を返す。
		$taxonomy_object = $taxonomy ? get_taxonomy( $taxonomy ) : false;

		// object_type が空、またはプロパティ自体が無い場合に備えて配列へフォールバックする。
		$object_types = ( $taxonomy_object && ! empty( $taxonomy_object->object_type ) ) ? $taxonomy_object->object_type : array();

		/*
		 * 紐づく投稿タイプがちょうど1つのときだけ、その投稿タイプに絞り込まれていると判定する。
		 * 要素が1つでも、配列のキーが 0 である保証は無い（プラグインが投稿タイプの登録を
		 * 解除すると array( 1 => 'estimate' ) のようにキーが詰まらないまま残ることがある）ため、
		 * $object_types[0] ではなく reset() で先頭要素を取得する。
		 */
		$slug = ( 1 === count( $object_types ) ) ? reset( $object_types ) : '';
	} else {
		// フロントページの混在表示、またはそれ以外の判定できないケースは絞り込みなし扱い。
		$slug = '';
	}

	if ( ! is_scalar( $slug ) || ! post_type_exists( $slug ) ) {
		return '';
	}

	return (string) $slug;
}

/**
 * 書類一覧・取引先一覧（index.php）の「書類」列のセル内容（HTML）を組み立てる
 *
 * 単一の投稿タイプに絞り込まれた一覧（請求書一覧・見積書一覧・取引先一覧など）では、
 * この列のリンク先が現在表示中のページ自身になってしまうため、リンクにせずラベルを
 * テキストとして返す。それ以外（フロントページの請求書・見積書の混在一覧）では、
 * 行の投稿タイプの一覧に絞り込むリンクを返す。
 *
 * index.php に分岐を直接書くと、リンクになる側（今回の issue #316 で直したURL組み立て）を
 * PHPUnit で検証できない（index.php はテンプレートパーツの都合で1プロセス中に1回しか
 * レンダリングできない）ため、ロジックをこの関数へ切り出している。URL の組み立てもこの
 * 関数1箇所に集約し、書き間違いが起きても直す場所が1箇所になるようにする。
 *
 * @param string $post_type_slug        行（投稿）の投稿タイプスラッグ（get_post_type() の戻り値）。
 * @param string $single_list_post_type 現在の一覧が絞り込まれている投稿タイプスラッグ
 *                                       （bill_get_single_post_type_slug() の戻り値。単一に
 *                                       絞り込まれていない場合は空文字）。
 * @return string 「書類」列に出力する HTML（エスケープ済み）。投稿タイプのラベルが
 *                取得できない場合（未登録の投稿タイプ等）は空文字。
 */
function bill_get_document_type_column( $post_type_slug, $single_list_post_type ) {
	$post_type_object = get_post_type_object( $post_type_slug );

	// 未登録の投稿タイプ（salary など）では $post_type_object が取得できない。
	// href="" の空リンクだけでなく、リンク文字列が空になる事態も避けるため、
	// ラベルが取得できない場合は何も出力しない。
	if ( ! $post_type_object ) {
		return '';
	}

	$post_type_label = $post_type_object->labels->name;

	if ( '' === $post_type_label ) {
		return '';
	}

	if ( $single_list_post_type === $post_type_slug ) {
		/*
		 * 現在の一覧がこの行の投稿タイプ単体に絞り込まれている場合
		 * （請求書一覧・見積書一覧・取引先一覧など）は、リンク先が
		 * 現在表示中のページ自身になってしまうためリンクにしない。
		 *
		 * なお、検索フォームの「書類種別」セレクト（template-parts/search-box.php）は
		 * 請求書・見積書の2択のみで取引先の選択肢が無く、取引先一覧では現在地を
		 * 示せていない（選択肢に無いため既定表示の「請求書」が選択された状態になる）。
		 * この改善は本不具合修正のスコープ外のため、ここでは aria-current 等の
		 * 現在地マークアップの追加は行わない。
		 */
		return esc_html( $post_type_label );
	}

	/*
	 * フロントページなど複数の投稿タイプが混在する一覧では、
	 * 行の投稿タイプの一覧に絞り込むリンクにする。
	 * URL の形式は bill_get_post_type() の 'url' と同じ（?post_type=<slug>）にする。
	 * 同一サイト内の一覧切り替えのため target="_blank" は付けない。
	 */
	return '<a href="' . esc_url( home_url( '/?post_type=' . $post_type_slug ) ) . '">' . esc_html( $post_type_label ) . '</a>';
}

/**
 * 別タブで開くことを予告するアイコン（装飾用）のHTMLを返す
 *
 * target="_blank" のリンクが新しいタブで開くことを、外部リンクアイコンと screen-reader-text の
 * 併用で予告する（issue #310）。このアイコンは装飾のため aria-hidden="true" を付けて
 * 音声読み上げには乗せない。bill_get_new_window_notice() から使うほか、既存の翻訳文字列に
 * 予告文言を合成する箇所（index.php の「名称未設定の取引先」など）でも、アイコン部分の
 * マークアップを重複させないためにこの関数を直接使う。
 *
 * @return string アイコンのHTML（エスケープ不要な固定マークアップ）。
 */
function bill_get_new_window_icon() {
	return '<span class="glyphicon glyphicon-new-window" aria-hidden="true"></span>';
}

/**
 * 別タブで開くことを予告する文言（括弧付き・エスケープ済み）を返す
 *
 * 画面拡大利用者にはアイコンの aria-hidden が届かないため、screen-reader-text で
 * 別タブで開くことをテキストとしても予告する（issue #310）。
 * 既存の翻訳文字列（「名称未設定の取引先」等）と1つの screen-reader-text span 内で
 * 連結したい場合はこの関数を、アイコンとセットでそのまま追記したい場合は
 * bill_get_new_window_notice() を使う。
 *
 * @param bool $is_external 遷移先が外部サイトかどうか。true の場合は「外部サイトが新しいタブで
 *                           開きます」、false の場合は「新しいタブで開きます」の文言になる。
 * @return string 括弧付きのエスケープ済み文言（例:「（新しいタブで開きます）」）。
 */
function bill_get_new_window_notice_text( $is_external = false ) {
	if ( $is_external ) {
		return esc_html__( '（外部サイトが新しいタブで開きます）', 'bill-vektor' );
	}

	return esc_html__( '（新しいタブで開きます）', 'bill-vektor' );
}

/**
 * 別タブで開くことを予告するマークアップ（アイコン＋screen-reader-text）を返す
 *
 * target="_blank" のリンクのテキストの直後に連結して使う。集約前は取引先一覧の取引先名リンク・
 * 書類一覧の取引先（登録済）リンク・件名リンク・お知らせ（RSS）リンクの4箇所で同じ
 * マークアップが重複しており、この関数に集約した（issue #310 レビュー対応）。現在は
 * footer.php・template-parts/export-box.php の外部リンク3箇所も加わり、合計7箇所で使用している。
 *
 * @param bool $is_external 遷移先が外部サイトかどうか。bill_get_new_window_notice_text() に委譲する。
 * @return string エスケープ済みのHTML（アイコン span ＋ screen-reader-text span）。
 */
function bill_get_new_window_notice( $is_external = false ) {
	return bill_get_new_window_icon() . '<span class="screen-reader-text">' . bill_get_new_window_notice_text( $is_external ) . '</span>';
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

/**
 * 書類の取引先名を取得する
 *
 * 取引先（イレギュラー）が入力されていればそれを優先し、
 * 入力がなければ取引先（登録済）の投稿タイトルを返す。
 *
 * @param WP_Post $post 書類の投稿オブジェクト。
 * @return string 取引先名。取引先が未設定の場合は空文字。
 */
function bill_get_client_name( $post ) {
	/*
	 * 取引先（イレギュラー）が入力されている場合はそちらを優先する。
	 * この値も保存時にサニタイズされておらず配列などが入り得るため、
	 * 文字列・数値以外は未入力として扱う（戻り値を必ず文字列にする）。
	 */
	if ( is_scalar( $post->bill_client_name_manual ) && '' !== (string) $post->bill_client_name_manual ) {
		return (string) $post->bill_client_name_manual;
	}

	/*
	 * 取引先（登録済）のIDの検証は bill_get_client_id() に集約する。
	 * absint() だけでは -123 が 123 になり、無関係な投稿のタイトルを
	 * 取引先名として返してしまうため。
	 */
	$client_id = bill_get_client_id( $post );

	/*
	 * 取引先が未選択（空・0・不正な値）の場合は空文字を返す。
	 * get_the_title() は引数が空だとグローバルの $post を参照するため、
	 * そのまま渡すと書類自身の件名が取引先名として返ってしまう。
	 */
	if ( ! $client_id ) {
		return '';
	}

	// 登録済取引先の投稿タイトルを返す
	return get_the_title( $client_id );
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
	 * 取引先が未設定の場合に空文字を返す判定は bill_get_client_name() 側で行う。
	 * 「イレギュラーと登録済のどちらを優先するか」という業務ルールを
	 * 2箇所に持たせないため、ここでは判定せず委譲する。
	 */
	return (string) bill_get_client_name( $post );
}

/**
 * 書類に紐づく取引先（登録済）の投稿IDを取得する
 *
 * bill_client には取引先の投稿IDが保存されるが、未設定の書類では空文字が入り、
 * 保存時にサニタイズされていないため配列・オブジェクト・負数なども入り得る。
 * これらを get_post_meta() や get_the_permalink() にそのまま渡すと、
 * 数値へのキャストで無関係な投稿を参照したり、グローバルの $post が参照されて
 * 書類自身の情報を取引先の情報として扱ってしまう。
 * その検証をこの関数に集約し、呼び出し側は「有効な取引先IDかどうか」だけを見れば済むようにしている。
 *
 * @param int|WP_Post $post 書類の投稿IDまたは投稿オブジェクト。
 * @return int 取引先の投稿ID。未設定・不正値・取引先が存在しない場合や、
 *             取引先（client）以外の投稿を指している場合は 0。
 */
function bill_get_client_id( $post ) {
	/*
	 * 空の値を get_post() に渡すとグローバルの $post が返るため、
	 * 意図しない書類の取引先IDを返さないよう先に判定する。
	 */
	if ( empty( $post ) ) {
		return 0;
	}

	// 投稿IDでも投稿オブジェクトでも受け取れるように WP_Post に正規化する
	$post = get_post( $post );

	// 投稿が存在しない場合は取引先なしとして 0 を返す
	if ( ! $post instanceof WP_Post ) {
		return 0;
	}

	// 配列・オブジェクトなど数値（数値文字列を含む）以外は取引先が未設定として扱う
	if ( ! isset( $post->bill_client ) || ! is_numeric( $post->bill_client ) ) {
		return 0;
	}

	/*
	 * 負数や小数が入っていた場合、absint() は符号や端数を落として別のIDに変えてしまう。
	 * get_post_meta() も内部で absint() を通すため、-123 を渡すと
	 * 投稿ID 123 のメタ値（＝別の取引先の省略名）を読んでしまう。
	 * 参照先がずれないよう、元の値が正の整数そのものでない場合は
	 * どの取引先を指しているか確定できないものとして不正値扱いにする。
	 */
	$client_id = absint( $post->bill_client );
	if ( ! $client_id || (string) $client_id !== (string) $post->bill_client ) {
		return 0;
	}

	/*
	 * 削除済みなど実在しない取引先IDが残っている場合は取引先なしとして扱う。
	 * 取引先（client）以外の投稿を指している場合も同様に扱う。
	 * 非公開ページなど別の投稿のIDが保存されていると、その投稿のタイトルが
	 * 取引先名として書類やその一覧に表示されてしまうため。
	 */
	$client = get_post( $client_id );
	if ( ! $client || 'client' !== $client->post_type ) {
		return 0;
	}

	return $client_id;
}

/**
 * 表示用の取引先名（省略名優先）を取得する
 *
 * 取引先（登録済）に省略名（client_short_name）が登録されていればその省略名を返し、
 * 登録されていなければ通常の取引先名を返す。
 * 書類一覧の取引先欄と CSV エクスポートで同じ判定を使うため、この関数に集約している。
 * （同じ業務ルールが複数箇所に散っていると、仕様変更時に片方だけ直して取り残される事故が起きるため）
 *
 * 「取引先（イレギュラー）と（登録済）のどちらを優先するか」の業務ルールは
 * bill_get_client_name() だけが持つため、この関数では判定しない。
 *
 * @param int|WP_Post $post 書類の投稿IDまたは投稿オブジェクト。
 * @return string 表示用の取引先名。取引先が未設定の場合や投稿が存在しない場合は空文字。
 */
function bill_get_client_short_name( $post ) {
	/*
	 * 空の値を get_post() に渡すとグローバルの $post が返るため、
	 * 意図しない書類の取引先名を返さないよう先に判定する。
	 */
	if ( empty( $post ) ) {
		return '';
	}

	/*
	 * 投稿IDでも投稿オブジェクトでも受け取れるように WP_Post に正規化する。
	 * この正規化は、後述する検証済みIDの差し替え（clone）が効くための前提でもあるため、
	 * WP_Post を受け取る場合でも省略しないこと。
	 */
	$post = get_post( $post );

	// 投稿が存在しない場合は空文字を返す
	if ( ! $post instanceof WP_Post ) {
		return '';
	}

	// 取引先（登録済）のID（未設定・不正値の場合は 0）
	$client_id = bill_get_client_id( $post );

	if ( $client_id ) {
		$client_short_name = get_post_meta( $client_id, 'client_short_name', true );

		/*
		 * 省略名も無加工の $_POST が保存されるため配列などが入り得る。
		 * 文字列・数値以外は未登録として扱い、登録されていればそれを返す。
		 */
		if ( is_scalar( $client_short_name ) && '' !== (string) $client_short_name ) {
			return (string) $client_short_name;
		}
	}

	/*
	 * 省略名が無い場合の取引先名は bill_get_client_name_by_post() に委譲する。
	 * 委譲先も bill_get_client_id() で同じ検証を行うため差し替えなしでも結果は同じだが、
	 * この関数が「検証済みのIDだけを渡す」ことをコード上で明示するために差し替える。
	 * 取引先（イレギュラー）の値はそのまま渡すので、優先順位の判定は委譲先のままになる。
	 *
	 * 差し替えは、冒頭の get_post() による正規化で $post の filter が raw に
	 * なっていることが前提（filter が raw 以外だと委譲先で WP_Post が作り直され、
	 * 代入した bill_client が失われる）。
	 */
	$validated_post              = clone $post;
	$validated_post->bill_client = $client_id;

	return (string) bill_get_client_name_by_post( $validated_post );
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
