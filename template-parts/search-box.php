<div class="search-box">
<form action="" method="get">
<dl class="search-radio">
<dt><label for="client">書類種別</label></dt>
<dd>
<?php
$post_type = ( isset( $_GET['post_type'] ) && $_GET['post_type'] ) ? esc_attr( $_GET['post_type'] ) : "";
$post_type_array = array(
	'estimate' => '見積書',
	'post' => '請求書',
	);
foreach ($post_type_array as $key => $label) {
	echo '<label class="checkbox-inline">';
	$selected = '';
	if ( $post_type == $key ) {
		$selected = ' checked="checked"';
	}
	echo '<input type="radio" class="" name="post_type" id="post_type_'.$key.'" value="'.$key.'"'.$selected.'>　'.$label;
	echo '</label>';
}
?>
</dd>
</dl>

<dl>
<dt><label for="client">取引先</label></dt>
<dd>
<?php
$args = array(
	'post_type' => 'client',
	'posts_per_page' => -1,
	);
$client_posts = get_posts($args);
$client_id = ( isset( $_GET['client'] ) && $_GET['client'] ) ? esc_attr( $_GET['client'] ) : "";
echo '<select name="client" id="client" class="form-control">';
echo '<option value="">- 未選択 -</option>';
if ( $client_posts ) {
	foreach ( $client_posts as $key => $post ) {
		$selected = '';
		if ( $client_id == $post->ID ) {
			$selected = ' selected';
		}
		echo '<option value="'.$post->ID.'"'.$selected.'>'.esc_attr( $post->post_title ).'</option>';
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
$end_date = ( isset( $_GET['end_date'] ) && $_GET['end_date'] ) ? $_GET['end_date'] : '';
?>
<input type="text" class="datepicker form-control" value="<?php echo esc_html($start_date);?>" name="start_date" id="start_date"> ～ 
<input type="text" class="datepicker form-control" value="<?php echo esc_html($end_date);?>" name="end_date" id="end_date">
</dd>
</dl>




<input type="submit" value="絞り込み" class="btn btn-block btn-primary" />
</form>
</div>