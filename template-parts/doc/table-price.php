<table class="table table-bordered table-bill">
<thead>
<tr>
<th class="text-center">品目</th>
<th class="text-center">数量</th>
<th class="text-center">単位</th>
<th class="text-center">単価</th>
<th class="text-center">金額</th>
</tr>
</thead>
<tbody>
<?php 
$bill_items = get_post_meta( $post->ID, 'bill_items', true );
$bill_item_sub_fields = array( 'name', 'count', 'unit', 'price' );
$bill_total = 0;

if ( is_array( $bill_items ) ) {

// 行のループ
foreach ($bill_items as $key => $value) { ?>

	<tr>
	<?php
	// $item_count
	if ( $bill_items[$key]['count'] === '' ){
		$item_count = '';
	} else {
		// intvalだと小数点が切り捨てられるので使用していない
		$item_count = $bill_items[$key]['count'];
		$item_count = mb_convert_kana ( $item_count, 'a');
	}

	// $item_price
	if ( $bill_items[$key]['price'] === '' ) {
		$item_price = '';
		$item_price_print = '';
	} else {
		$item_price = mb_convert_kana( $bill_items[$key]['price'], 'a' );
		$item_price = intval( $item_price );
		$item_price_print = '¥ '.number_format( $item_price );
	}

	// $item_total
	if ( is_numeric( $item_count ) && is_numeric( $item_price ) ) {
		$item_price_total = $item_count * $item_price;
		$item_price_total_print = '¥ '.number_format( $item_price_total );
	} else {
		$item_price_total = '';
		$item_price_total_print = '';
	}
	?>
	<?php if ( $bill_items[$key]['name'] ){
		$bill_item_name = $bill_items[$key]['name'];
	} else {
		$bill_item_name = '　';
	} ?>
	<td><?php echo esc_html( $bill_item_name );?></td>
	<td class="text-center"><?php echo esc_html( $item_count) ;?></td>
	<td class="text-center"><?php echo esc_html( $bill_items[$key]['unit'] );?></td>
	<td class="price"><?php echo esc_html( $item_price_print );?></td>
	<td class="price"><?php echo esc_html( $item_price_total_print );?></td>
	</tr>

	<?php 
	// 小計
	$bill_total += $item_price_total;

} // foreach ($bill_items as $key => $value) {

} // if ( is_array( $bill_items ) ) {

$tax = round( $bill_total * 0.08 );
$bill_total_add_tax = $bill_total + $tax;
?>
</tbody>
</table>
<?php 
global $post;
$bill_total_price_display = ( isset($post->bill_total_price_display[0]) ) ? $post->bill_total_price_display[0]:'';
if ( $bill_total_price_display != 'hidden' ) { ?>
<table class="table table-bordered table-bill table-bill-total">
<?php 
global $post;
if ( isset( $post->bill_tax_type ) && $post->bill_tax_type == 'tax_not_auto' ) : ?>
<tr><th colspan="4">合計金額</th><td class="price">¥ <?php echo number_format( $bill_total );?></td></tr>
<?php else : ?>
<tr><th colspan="4">小計</th><td class="price">¥ <?php echo number_format( $bill_total );?></td></tr>
<tr><th colspan="4">消費税</th><td class="price">¥ <?php echo number_format( $tax );?></td></tr>
<tr><th colspan="4">合計金額</th><td class="price">¥ <?php echo number_format( $bill_total_add_tax );?></td></tr>
<?php endif;?>
</table>

<?php } // if ( $post->bill_total_price_display[0] != 'hidden' ) {  ?>