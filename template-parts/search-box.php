<div class="search-box">
<dl>
<dt><label for="client">書類種別</label></dt>
<dd>
	<?php
	$post_type       = ( isset( $_GET['post_type'] ) && $_GET['post_type'] ) ? esc_attr( $_GET['post_type'] ) : '';
	$post_type_array = array(
		'estimate' => '見積書',
		'post'     => '請求書',
	);
	$post_type_array = apply_filters( 'bill_vektor_post_types', $post_type_array );
	echo '<select name="post_type" id="post_type" class="form-control">';

	foreach ( $post_type_array as $post_type_key => $post_type_name ) {
		$selected = '';
		if ( $post_type_key == $post_type ) {
			$selected = ' selected';
		}
		echo '<option value="' . $post_type_key . '"' . $selected . '>' . esc_attr( $post_type_name ) . '</option>';
	}
	echo '</select>';
	?>
</dd>
</dl>

<dl>
<dt><label for="client">取引先</label></dt>
<dd>
<?php
$args         = array(
	'post_type'      => 'client',
	'posts_per_page' => -1,
	'order'          => 'ASC',
	'orderby'        => 'title',
);
$client_posts = get_posts( $args );

$client_id = ( isset( $_GET['bill_client'] ) && $_GET['bill_client'] ) ? esc_attr( $_GET['bill_client'] ) : '';
echo '<select name="bill_client" id="bill_client" class="form-control">';
echo '<option value="">- 未選択 -</option>';
if ( $client_posts ) {
	foreach ( $client_posts as $post ) {
		$selected = '';
		if ( $client_id == $post->ID ) {
			$selected = ' selected';
		}
		$client_name = get_the_title( $post->ID );
		// プルダウンに表示するかしないかの情報を取得
		$client_hidden = get_post_meta( $post->ID, 'client_hidden', true );
		// プルダウン非表示にチェックが入っていない項目だけ出力
		if ( ! $client_hidden ) {
			echo '<option value="' . $post->ID . '"' . $selected . '>' . esc_attr( $client_name ) . '</option>';
		}
	}
}
echo '</select>';
?>
</dd>
</dl>

<dl class="search-date">
<dt>発行日</dt>
<dd>
<?php
$start_date = ( isset( $_GET['start_date'] ) && $_GET['start_date'] ) ? $_GET['start_date'] : '';
$end_date   = ( isset( $_GET['end_date'] ) && $_GET['end_date'] ) ? $_GET['end_date'] : '';
?>
<input type="text" class="datepicker form-control" value="<?php echo esc_html( $start_date ); ?>" name="start_date" id="start_date"> ～
<input type="text" class="datepicker form-control" value="<?php echo esc_html( $end_date ); ?>" name="end_date" id="end_date">
</dd>
</dl>

<button type="submit" name="action" value="send" class="search-submit btn btn-block btn-primary">絞り込み　<span class="glyphicon glyphicon-search"></span></button>

</div>
