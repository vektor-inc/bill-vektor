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

		/*	期間の絞り込み
		/*-------------------------------------------*/
		$start_date = ( isset( $_GET['start_date'] ) && $_GET['start_date'] ) ? $_GET['start_date'] : '';
		$end_date = ( isset( $_GET['end_date'] ) && $_GET['end_date'] ) ? $_GET['end_date'] : '';
		if ( $start_date && $end_date ){
			// $start_date = $start_date.' 00:00:00';
			$end_date = $end_date.' 23:59:59';
			$date_query = array(
				array(
					'compare'=>'BETWEEN',
					// 'inclusive'=>ture,
					'after'=> $start_date,
					'before'=> $end_date
					)
				);
			$query->set( 'date_query', $date_query );
		}

        return;
    }
}
add_action( 'pre_get_posts', 'bill_custom_home_post_type' );