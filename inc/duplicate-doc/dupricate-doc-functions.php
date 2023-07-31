<?php
/*
	新規複製 _ 保存する関数
	bill_copy_post
-------------------------------------------
  新規複製 _ 複製トリガー
	bill_copy_redirect
-------------------------------------------
	記事リスト _ 複製して編集へのリンクを追加
	bill_row_actions_add_duplicate_link
/*-------------------------------------------*/


/*
  新規複製 _ 保存する関数
/*-------------------------------------------*/
function bill_copy_post( $post_id, $post_type = 'post', $table_copy_type = 'all', $duplicate_type = 'full' ) {
	$post = get_post( $post_id );
	$tax_array = bill_vektor_tax_array();

	if ( empty( $post ) ) {
		return null;
	}

	/*
	  投稿を複製 _ 投稿基本情報
	/*-------------------------------------------*/
	// 複製投稿する基本情報データ
	$post_var = array(
		'post_content'   => $post->post_content,
		'post_name'      => $post->post_name,
		'post_title'     => $post->post_title,
		'post_status'    => 'draft',
		'ping_status'    => $post->ping_status,
		'post_parent'    => $post->post_parent,
		'menu_order'     => 0,
		'to_ping'        => $post->to_ping,
		'pinged'         => $post->pinged,
		'post_password'  => $post->post_password,
		'post_excerpt'   => $post->post_excerpt,
		// 'post_date'      => $post->post_date,
		// 'post_date_gmt'  => $post->post_date_gmt,
		'comment_status' => $post->comment_status,
		'post_type'      => $post_type,
	);

	/*
	  投稿を複製 _ カテゴリー情報
	/*-------------------------------------------*/
	if ( $duplicate_type == 'full' ) {

		$taxonomys = get_object_taxonomies( $post );
		// var_dump($taxonomys);
		$set_terms = array();
		foreach ( $taxonomys as $taxonomy ) {
			$tm = wp_get_object_terms( $post_id, $taxonomy );
			if ( empty( $tm ) ) {
				continue;
			}
			$set_terms[ $taxonomy ] = array();
			foreach ( $tm as $t ) {
				$set_terms[ $taxonomy ][] = $t->term_taxonomy_id;
			}
		}
		$post_var['tax_input'] = $set_terms;

	}

	/*
	  投稿を複製 _ 複製を実行
	/*-------------------------------------------*/
	$new_post = wp_insert_post( $post_var );

	// 投稿が失敗したらその場で return
	if ( is_wp_error( $new_post ) ) {
		return false;
	}

	/*
	  投稿meta情報の保存 _ 顧客名・消費税率・消費税
	/*-------------------------------------------*/
	$bill_client = get_post_meta( $post->ID, 'bill_client', true );
	add_post_meta( $new_post, 'bill_client', $bill_client );

	$bill_tax_rate = get_post_meta( $post->ID, 'bill_tax_rate', true );
	add_post_meta( $new_post, 'bill_tax_rate', $bill_tax_rate );

	$bill_tax_type = get_post_meta( $post->ID, 'bill_tax_type', true );
	add_post_meta( $new_post, 'bill_tax_type', $bill_tax_type );
	
	/*
	  投稿meta情報の保存 _ 品目テーブル
	/*-------------------------------------------*/
	// bill_items はシリアライズされた内容で複製されてしまうので
	// 他のカスタムフォールドとは別でアップデートする
	if ( $table_copy_type == 'all' ) {

		// テーブルをそのまま複製する場合
		$bill_items = get_post_meta( $post->ID, 'bill_items', true );
		if ( $bill_items ) {
			add_post_meta( $new_post, 'bill_items', $bill_items );
		}

		// 給与明細のテーブル内容複製
		if ( $post_type == 'salary' ) {

			$kazei_additional = get_post_meta( $post->ID, 'kazei_additional', true );

			if ( $kazei_additional ) {
				add_post_meta( $new_post, 'kazei_additional', $kazei_additional );
			}
			$hikazei_additional = get_post_meta( $post->ID, 'hikazei_additional', true );
			if ( $hikazei_additional ) {
				add_post_meta( $new_post, 'hikazei_additional', $hikazei_additional );
			}
		}
	}
	else {

		// 一括にしてテーブルを保存する
		//
		// 合計金額を算出する
		$old_bill_items = bill_vektor_invoice_total_tax( $post );
		$new_bill_items = array();

		foreach ( $old_bill_items as $bill_item ) {
			$new_bill_item[] = array(
				'name'     => $new_post->post_title . ' ( ' . $bill_item['rate'] . ' ) ',
				'count'    => $bill_item['price'],
				'unit'     => '式',
				'price'    => $bill_total,
				'tax-rate' => str_replace( '対象', '', $bill_item['rate'] ),
				'tax-type' => 'tax_excluded',
			);
		}

		// 余白分数行追加しておく
		for ( $i = 1; $i <= 7;$i++ ) {
			$new_bill_items[ $i ] = array(
				'name'     => '',
				'count'    => '',
				'unit'     => '',
				'price'    => '',
				'tax-rate' => $tax_array[0],
				'tax-type' => 'tax_excluded',
			);
		}
	

		add_post_meta( $new_post, 'bill_items', $new_bill_items );
	}

	/*
	  投稿meta情報の保存 _ 同じ投稿タイプで複製
	/*-------------------------------------------*/
	if ( $duplicate_type == 'full' ) {

		// まずは $post_id に紐付いてるカスタムフィールドのデータを全部取得する
		$metas = get_post_custom( $post_id );
		// 複製元のIDを一応保存
		$metas['copy_master_id'][0] = $post_id;

		$copy_metas = array();
		foreach ( $metas as $k => $v ) {
	
			if ( $k == '_wp_page_template' ) {
				$copy_metas[ $k ] = $v;
			}
			if ( $k == '_thumbnail_id' ) {
				$copy_metas[ $k ] = $v;
			}
			if ( ! preg_match( '/^_/', $k ) ) {
				$copy_metas[ $k ] = $v;
			}

		}

		foreach ( $copy_metas as $k => $v ) {
			foreach ( $v as $vv ) {
				add_post_meta( $new_post, $k, $vv );
			}

		}

		$bill_total_price_display = get_post_meta( $post_id, 'bill_total_price_display', true );
		add_post_meta( $new_post, 'bill_total_price_display', $bill_total_price_display );

	}

	return $new_post;
}

/*
  新規複製 _ 複製トリガー
	admin_init のタイミングでURLにコピー元の master_id と duplicate_type が含まれていたら
	複製する関数を実行する
	複製完了したら出来た記事にリダイレクト
/*-------------------------------------------*/
function bill_copy_redirect() {
	// 管理画面のURLに複製識別用のURLが含まれていたら
	if ( isset( $_GET['master_id'] ) ) {
		$master_id       = esc_html( $_GET['master_id'] );
		$post_type       = esc_html( $_GET['post_type'] );
		$table_copy_type = esc_html( $_GET['table_copy_type'] );

		$duplicate_type = ( isset( $_GET['duplicate_type'] ) && $_GET['duplicate_type'] ) ? esc_html( $_GET['duplicate_type'] ) : '';

		// 記事の複製を実行
		$copy_post_id = bill_copy_post( $master_id, $post_type, $table_copy_type, $duplicate_type );
		// 複製した記事の編集画面へリダイレクト
		$url = admin_url() . 'post.php?post=' . $copy_post_id . '&action=edit';
		wp_safe_redirect( $url );
	}
}
add_action( 'admin_init', 'bill_copy_redirect' );

/*
  記事リスト _ 複製して編集へのリンクを追加
/*-------------------------------------------*/
function bill_row_actions_add_duplicate_link( $actions, $post ) {
	$post_type          = get_post_type();
	$links              = admin_url() . 'post-new.php?post_type=' . $post_type . '&master_id=' . $post->ID . '&table_copy_type=all&duplicate_type=full';
	$actions['newlink'] = '<a href="' . $links . '">複製</a>';
	return $actions;
}
