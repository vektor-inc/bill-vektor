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
 * @param int $old_tax_rate 過去に設定された全項目一括指定の税率
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
 */
function bill_vektor_invoice_each_tax( $post ) {
	// カスタムフィールドを取得
	$bill_items = get_post_meta( $post->ID, 'bill_items', true );
	// 消費税率の配列
	$tax_array = bill_vektor_tax_array();
	// 税率ごとに税込み金額・消費税額・合計金額を算出した配列を初期化
	$tax_total = array();
	$final_tax_total = array();
	// 古い消費税率
	$old_tax_rate = get_post_meta( $post->ID, 'bill_tax_rate', true );
	// 古い税込・税抜
	$old_tax_type = get_post_meta( $post->ID, 'bill_tax_type', true );
	// 消費税の丸め処理
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
			if ( 
				! empty( $bill_item['name'] ) &&
				! empty( $bill_item['count'] ) &&
				! empty( $bill_item['unit'] ) &&
				! empty( $bill_item['price'] ) &&
				! empty( $bill_item['tax-rate'] ) &&
				! empty( $bill_item['tax-type'] ) 
			) {
				// 税率ごとのループ
				foreach( $tax_array as $tax_rate ) {
					// 税率のループとカスタムフィールドのループが同じ値の場合
					if ( $bill_item['tax-rate'] === $tax_rate ) {

						// 税率を数値に変換
						$item_tax_rate  = 0.01 * intval( str_replace( '%', '', $bill_item['tax-rate'] ) );

						// 単価を数値に変換
						$item_price = bill_vektor_invoice_unit_plice( bill_item_number( $bill_item['price'] ), $item_tax_rate, $bill_item['tax-type'] );
		
						// 個数を数値に変換						
						$item_count = bill_item_number( $bill_item['count'] );

						// 上記３つが数値なら
						if ( is_numeric( $item_count ) && is_numeric( $item_price ) && is_numeric( $item_tax_rate ) ) {

							// 合計金額を算出
							$item_total     = bill_vektor_invoice_total_plice( $item_price,  $item_count );						
							// 品目ごとの消費税額
							$item_tax_value = bill_vektor_invoice_tax_plice( $item_total, $item_tax_rate );
							// 品目ごとの税込合計金額
							$item_tax_total = bill_vektor_invoice_full_plice( $item_total, $item_tax_value );

							// 税率何％の対象か
							$tax_total[$tax_rate]['rate']  = $bill_item['tax-rate'] . '対象';
							// 対象税率の税抜き合計金額
							$tax_total[$tax_rate]['price'] = ! empty( $tax_total[$tax_rate]['price'] ) ? $tax_total[$tax_rate]['price'] + $item_total : $item_total;
							// 対象税率の消費税額
							$tax_total[$tax_rate]['tax']   = ! empty( $tax_total[$tax_rate]['tax'] )   ? $tax_total[$tax_rate]['tax'] + $item_tax_value : $item_tax_value;
						}
					}
				}
			}
		}
		// 出来上がった配列の消費税と合計金額を調整
		foreach( $tax_total as $tax_key => $tax_value ) {
			// 消費税の丸め処理
			// $tax_fraction には floor, round, ceil のいずれかが入っているので call_user_func でその関数を直接呼び出している
			$tax_total[$tax_key]['tax']   = call_user_func( $tax_fraction, $tax_value['tax'] );
			// 税抜金額と消費税から税込み金額を算出
			$tax_total[$tax_key]['total'] = $tax_value['price'] + $tax_total[$tax_key]['tax'];
		}

		// 税率の高い順に一応並び替え
		foreach( $tax_array as $tax_rate ) {
			if ( 
				! empty ( $tax_total[$tax_rate]['rate'] ) &&
				! empty ( $tax_total[$tax_rate]['price'] ) &&
				! empty ( $tax_total[$tax_rate]['tax'] || 0.0 === $tax_total[$tax_rate]['tax'] ) &&				
				! empty ( $tax_total[$tax_rate]['total'] ) &&
				$tax_rate  . '対象' === $tax_total[$tax_rate]['rate']
			) {
				$final_tax_total[$tax_rate]['rate']  = $tax_total[$tax_rate]['rate'];
				$final_tax_total[$tax_rate]['price'] = $tax_total[$tax_rate]['price'];
				$final_tax_total[$tax_rate]['tax']   = $tax_total[$tax_rate]['tax'];
				$final_tax_total[$tax_rate]['total'] = $tax_total[$tax_rate]['total'];
			}
		}
		$tax_total = $final_tax_total;
	}

	return $tax_total;
}

/**
 * インボイス対応の合計金額
 */
function bill_vektor_invoice_total_tax( $post ) {
	$total_array = bill_vektor_invoice_each_tax( $post );
	$bill_total  = 0;

	foreach( $total_array as $tax_value ) {
		//var_dump($tax_value);
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

	if( !post_type_exists( $post_type['slug'] ) ) {
	    $post_type['slug'] = "post";
    }

	// Get post type name
	/*-------------------------------------------*/
	$post_type_object = get_post_type_object( $post_type['slug'] );

	$post_type['name'] = esc_html( $post_type_object->labels->name );
	$post_type['url']  = home_url() . '/?post_type=' . $post_type['slug'];

	return $post_type;
}

/*
-------------------------------------------*/
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
	if ( $post->bill_client_name_manual ){
		$client_name = $post->bill_client_name_manual;
	} else {
		$client_name = get_the_title( $post->bill_client );
	}
	return $client_name;
}

function bill_get_client_honorific( $post ){
	if ( empty( $post->bill_client_name_manual ) ){
		$client_honorific = esc_html( get_post_meta( $post->bill_client, 'client_honorific', true ) );
		if ( $client_honorific ) {
			echo $client_honorific;
		} else {
			echo '御中';
		}
	}
}