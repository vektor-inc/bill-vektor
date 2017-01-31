<?php
/*-------------------------------------------*/
/*  新規複製 _ 保存する関数
/*-------------------------------------------*/
/*  記事リスト _ 複製して編集へのリンクを追加
/*-------------------------------------------*/
/*  新規複製 _ 複製トリガー
/*-------------------------------------------*/
/*  複製metabox
/*-------------------------------------------*/


/*-------------------------------------------*/
/*  新規複製 _ 保存する関数
/*-------------------------------------------*/
function bill_copy_post( $post_id, $duplicate_type='full' , $post_status='draft' ){

    $post = get_post($post_id);

    if( empty($post) ) return null;
 
    // 複製したユーザー情報
	$user = wp_get_current_user();
	$author_id = $user->ID;

    $post_var = array(
        'post_content'   => $post->post_content,
        'post_name'      => $post->post_name,
        'post_title'     => $post->post_title,
        'post_status'    => $post_status,
        // 'post_author'    => $author_id,
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
    );

    /*-------------------------------------------*/
    /*  複製する投稿情報 _ 同じ投稿タイプで複製
    /*-------------------------------------------*/
    if ( $duplicate_type == 'full' ) {

        $post_var['post_type'] = $post->post_type;
        $taxonomys = get_object_taxonomies( $post );
        // var_dump($taxonomys);
        $set_terms = array();
        foreach( $taxonomys as $taxonomy ){
            $tm = wp_get_object_terms( $post_id, $taxonomy );
            if( empty($tm) ) continue;
            $set_terms[$taxonomy] = array();
            foreach( $tm as $t ){
                $set_terms[$taxonomy][] = $t->term_taxonomy_id;
            }
        }
        $post_var['tax_input'] = $set_terms;

    /*-------------------------------------------*/
    /*  複製する投稿情報 _ 見積から請求を作成
    /*-------------------------------------------*/
    } else if ( $duplicate_type == 'estimate_to_bill' ){

        $post_var['post_type'] = 'post';

    /*-------------------------------------------*/
    /*  複製する投稿情報 _ 見積の件名一式で請求書を作成
    /*-------------------------------------------*/
    } else if ( $duplicate_type == 'estimate_to_bill_total' ){

        $post_var['post_type'] = 'post';

    }

    // return;
    $new_post = wp_insert_post($post_var);
    
    // 投稿が失敗したらその場で return
    if( is_wp_error( $new_post ) ) return false;

    /*-------------------------------------------*/
    /*  投稿meta情報の保存 _ 同じ投稿タイプで複製
    /*-------------------------------------------*/
    if ( $duplicate_type == 'full' ) {

        $metas = get_post_custom($post_id);
        $metas['copy_master_id'][0] = $post_id;

        $copy_metas = array();
        while( list($k,$v) = each( $metas ) ){
            if( $k == '_wp_page_template' ) $copy_metas[$k] = $v;
            if( $k == '_thumbnail_id' ) $copy_metas[$k] = $v;
            if( ! preg_match('/^_/', $k) ) $copy_metas[$k] = $v;
        }

        while( list($k,$v) = each($copy_metas) ){
            foreach( $v as $vv ) {
                add_post_meta( $new_post, $k, $vv );
            }
        }

        // bill_items はシリアライズされた内容で複製されてしまうので個別でアップデート
        $bill_items = get_post_meta( $post->ID, 'bill_items', true );
        update_post_meta( $new_post,'bill_items', $bill_items );

    /*-------------------------------------------*/
    /*  投稿meta情報の保存 _ 見積から請求を作成
    /*-------------------------------------------*/
    } else if ( $duplicate_type == 'estimate_to_bill' ){

        $bill_client = get_post_meta( $post->ID, 'bill_client', true );
        add_post_meta( $new_post,'bill_client', $bill_client );  
        
        $bill_items = get_post_meta( $post->ID, 'bill_items', true );
        add_post_meta( $new_post,'bill_items', $bill_items );  

    /*-------------------------------------------*/
    /*  投稿meta情報の保存 _ 見積の件名一式で請求書を作成
    /*-------------------------------------------*/
    } else if ( $duplicate_type == 'estimate_to_bill_total' ){

        $bill_client = get_post_meta( $post->ID, 'bill_client', true );
        add_post_meta( $new_post,'bill_client', $bill_client );

        // 合計金額を算出する
        $bill_items = get_post_meta( $post->ID, 'bill_items', true );
        $bill_total = 0;
        foreach ($bill_items as $key => $value) {
            if ( isset( $value['count'] ) && isset( $value['price'] ) ){
                $count = intval( $bill_items[$key]['count'] );
                $price = intval( $bill_items[$key]['price'] );
                $bill_total += $count * $price;
            }
        }

        $new_bill_items[0] = array(
            'name' => $post->post_title,
            'count' => 1,
            'unit' => '式',
            'price' => $bill_total,
        );
        for ( $i = 1; $i <= 7 ;$i++ ) {
            $new_bill_items[$i] = array(
                'name' => '',
                'count' => '',
                'unit' => '',
                'price' => '',
             );
        }

        add_post_meta( $new_post,'bill_items', $new_bill_items );  
    }


    return $new_post;
}

/*-------------------------------------------*/
/*  記事リスト _ 複製して編集へのリンクを追加
/*-------------------------------------------*/
function bill_post_list_add_filter() {
    add_filter( 'post_row_actions', 'bill_row_actions_add_duplicate_link', 10, 2 );
	add_filter( 'estimate_row_actions', 'bill_row_actions_add_duplicate_link', 10, 2 );
}
add_action( 'admin_init', 'bill_post_list_add_filter' );

function bill_row_actions_add_duplicate_link( $actions, $post ) {
	$post_type = get_post_type();
	$links = admin_url().'post-new.php?post_type='.$post_type.'&master_id='.$post->ID.'&duplicate_type=full';
	$actions['newlink'] =  '<a href="'.$links.'">複製</a>';
	return $actions;
}

/*-------------------------------------------*/
/*  新規複製 _ 複製トリガー
/*-------------------------------------------*/
function bill_copy_redirect(){
	// 管理画面のURLに複製識別用のURLが含まれていたら
	if ( isset($_GET['master_id']) ){
		$master_id = esc_html($_GET['master_id']);

        $duplicate_type = ( isset($_GET['duplicate_type']) && $_GET['duplicate_type'] ) ? esc_html( $_GET['duplicate_type'] ):'';

		// 記事の複製を実行
		$copy_post_id = bill_copy_post( $master_id, $duplicate_type );
		// 複製した記事の編集画面へリダイレクト
		$url = admin_url().'post.php?post='.$copy_post_id.'&action=edit';
		wp_safe_redirect( $url );
	}
}
add_action('admin_init','bill_copy_redirect');

/*-------------------------------------------*/
/*  複製metabox
/*-------------------------------------------*/
add_action( 'post_submitbox_start','bill_duplicate' );
function bill_duplicate(){
	global $post;
	$post_type = get_post_type();
	$links = admin_url().'post-new.php?post_type='.$post_type.'&master_id='.$post->ID;
    ?>
	<div class="duplicate-section">
	<a href="<?php echo esc_url($links).'&duplicate_type=full';?>" class="button button-default button-block">複製</a>
    <?php if ( get_post_type() == 'estimate' ) { ?>
    <a href="<?php echo esc_url($links).'&duplicate_type=estimate_to_bill';?>" class="button button-default button-block">この内容で請求書を発行</a>
    <a href="<?php echo esc_url($links).'&duplicate_type=estimate_to_bill_total';?>" class="button button-default button-block">件名を品目一式にして請求書を発行</a>
    <?php } ?>
	</div><!-- [ / #review_section ] -->
	<?php
}