<?php
function bill_custom_home_post_type($query){
    if ( !is_admin() && !is_singular() && $query->is_main_query() ) {

		$client_id = ( isset( $_GET['client'] ) && $_GET['client'] ) ? esc_attr( $_GET['client'] ) : "";
		$post_type = ( isset( $_GET['post_type'] ) && $_GET['post_type'] ) ? esc_attr( $_GET['post_type'] ) : "";

		$query->set( 'post_type', $post_type );
		if ( $client_id ) {
			$meta_query[] = array( 'key'=>'bill_client','value' => $client_id );
			$query->set( 'meta_query', $meta_query );
		}

        return;
    }
}
add_action( 'pre_get_posts', 'bill_custom_home_post_type' );