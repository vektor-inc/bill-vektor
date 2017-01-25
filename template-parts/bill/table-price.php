<table class="table table-bordered table-bill">
<thead>
<tr>
<th class="text-center">品目</th>
<th class="text-center">数量</th>
<th class="text-center">単位</th>
<th class="text-center">商品単価</th>
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
		$item_count = intval( $bill_items[$key]['count'] );
	}

	// $item_price
	if ( $bill_items[$key]['price'] === '' ) {
		$item_price = '';
		$item_price_print = '';
	} else {
		$item_price = intval( $bill_items[$key]['price'] );
		$item_price_print = '¥ '.number_format( $item_price );
	}

	// $item_total
	if ( $item_count && $item_price ) {
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

<table class="table table-bordered table-bill table-bill-total">
<tr><th colspan="4">小計</th><td class="price">¥ <?php echo number_format( $bill_total );?></td></tr>
<tr><th colspan="4">消費税</th><td class="price">¥ <?php echo number_format( $tax );?></td></tr>
<tr><th colspan="4">合計金額</th><td class="price">¥ <?php echo number_format( $bill_total_add_tax );?></td></tr>
</table>