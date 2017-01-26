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
function bill_copy_post( $post_id, $post_status='draft' ){

    $post = get_post($post_id);

    if( empty($post) ) return null;
 
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
    // var_dump($set_terms);

    $metas = get_post_custom($post_id);
    $metas['copy_master_id'][0] = $post_id;

    $copy_metas = array();
    while( list($k,$v) = each( $metas ) ){
        if( $k == '_wp_page_template' ) $copy_metas[$k] = $v;
        if( $k == '_thumbnail_id' ) $copy_metas[$k] = $v;
        if( ! preg_match('/^_/', $k) ) $copy_metas[$k] = $v;
    }
    // var_dump($copy_metas);

    // 複製したユーザー情報
	$user = wp_get_current_user();
	$author_id = $user->ID;

    $post_var = array(
        'post_content'   => $post->post_content,
        'post_name'      => $post->post_name,
        'post_title'     => $post->post_title,
        'post_status'    => $post_status,
        'post_type'      => $post->post_type,
        'post_author'    => $author_id,
        'ping_status'    => $post->ping_status,
        'post_parent'    => $post->post_parent,
        'menu_order'     => 0,
        'to_ping'        => $post->to_ping,
        'pinged'         => $post->pinged,
        'post_password'  => $post->post_password,
        'post_excerpt'   => $post->post_excerpt,
        'post_date'      => $post->post_date,
        'post_date_gmt'  => $post->post_date_gmt,
        'comment_status' => $post->comment_status,
        'tax_input'      => $set_terms,
    );
    // echo "post_var\n";
    // var_dump($post_var);

    // return;
    $new_post = wp_insert_post($post_var);
    // var_dump($new_post);
    if( is_wp_error( $new_post ) ) return false;

    while( list($k,$v) = each($copy_metas) ){
        foreach( $v as $vv ) {
        	add_post_meta( $new_post, $k, $vv );
        }
    }

    // bill_items はシリアライズされた内容で複製されてしまうので個別でアップデート
    $bill_items = get_post_meta( $post->ID, 'bill_items', true );
    update_post_meta( $new_post,'bill_items', $bill_items );

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
	$links = admin_url().'post-new.php?post_type='.$post_type.'&master_id='.$post->ID;
	$actions['newlink'] =  '<a href="'.$links.'">複製</a>';
	return $actions;
}

/*-------------------------------------------*/
/*  新規複製 _ 複製トリガー
/*-------------------------------------------*/
function bill_duplicate_redirect(){
	// 管理画面のURLに複製識別用のURLが含まれていたら
	if ( isset($_GET['master_id']) ){
		$master_id = esc_html($_GET['master_id']);
		// 記事の複製を実行
		$copy_post_id = bill_copy_post( $master_id );
		// 複製した記事の編集画面へリダイレクト
		$url = admin_url().'post.php?post='.$copy_post_id.'&action=edit';
		wp_safe_redirect( $url );
	}
}
add_action('admin_init','bill_duplicate_redirect');

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
	<a href="<?php echo esc_url($links);?>" class="button button-default button-block">複製</a>
<!-- 		
<input type="submit" name="send_review_mail" id="send_review_mail" class="button button-default button-block" value="複製">
-->
	</div><!-- [ / #review_section ] -->
	<?php
}