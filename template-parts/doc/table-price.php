<table class="table table-bordered table-striped table-bill">
<thead>
<tr>
<th class="text-center">品目</th>
<th class="text-center">数量</th>
<th class="text-center">単位</th>
<th class="text-center">単価</th>
<th class="text-center">税抜金額</th>
<th class="text-center">消費税率</th>
<th class="text-center">消費税額</th>
<th class="text-center">税込金額</th>
</tr>
</thead>
<tbody>
<?php
$bill_items           = get_post_meta( $post->ID, 'bill_items', true );
$bill_item_sub_fields = array( 'name', 'count', 'unit', 'price' );
$bill_total           = 0;
// 消費税率の配列
$tax_array = bill_vektor_tax_array();
// 軽減税率対象があるか
$lite_tax_flag = false;
// 金額の小数点以下の桁数
$digits = apply_filters( 'item_price_print_digits', 0 );
if ( is_array( $bill_items ) ) {
	$tax_total = array();
	// 行のループ
	foreach ( $bill_items as $key => $value ) {
		?>
		<tr>
		<?php
		if ( 
			! empty( $bill_items[ $key ]['name'] ) &&
			! empty( $bill_items[ $key ]['count'] ) &&
			! empty( $bill_items[ $key ]['unit'] ) &&
			! empty( $bill_items[ $key ]['price'] ) &&
			! empty( $bill_items[ $key ]['tax-rate'] ) &&
			! empty( $bill_items[ $key ]['tax-rate'] )
		) :
			// 品目
			if ( $bill_items[ $key ]['tax-rate'] !== $tax_array[0] ) {
				$bill_item_name = $bill_items[ $key ]['name'] . '＊';
			} else {
				$bill_item_name = $bill_items[ $key ]['name'];
			}

			// $item_count
			$item_count = bill_item_number( $bill_items[ $key ]['count'] );

			// $item_price
			$item_price = bill_item_number( $bill_items[ $key ]['price'] );
			$item_price_print = '¥ ' . number_format( $item_price, $digits );

			// $item_total
			if ( is_numeric( $item_count ) && is_numeric( $item_price ) ) {
				$item_price_total       = $item_count * $item_price;
				$item_price_total_print = '¥ ' . number_format( $item_price_total, $digits );
			} else {
				$item_price_total       = '';
				$item_price_total_print = '';
			}

			// 消費税率
			$item_tax_rate       = ! empty( $bill_items[ $key ]['tax-rate'] ) ? $bill_items[ $key ]['tax-rate'] : '';
			$item_tax_rate_value = ! empty( $item_tax_rate ) ? 0.01 * intval( str_replace( '%', '', $item_tax_rate ) ) : '';
			if ( ! empty( $bill_items[ $key ]['name'] ) && $item_tax_rate !== $tax_array[0] ) {
				$lite_tax_flag = true;
			}			

			// 消費税額
			$item_tax_value       = ( is_numeric( $item_price_total ) && is_numeric( $item_tax_rate_value ) ) ? $item_price_total * $item_tax_rate_value : '';
			$item_tax_value_print = ( is_numeric( $item_price_total ) && is_numeric( $item_tax_rate_value ) ) ? '¥ ' . number_format( $item_tax_value, $digits ) : '';

			// 税込金額
			$item_total = ( is_numeric( $item_price_total ) && is_numeric( $item_tax_value ) ) ? $item_price_total + $item_tax_value : '';
			$item_total_print = ( is_numeric( $item_price_total ) && is_numeric( $item_tax_value ) ) ? '¥ ' . number_format( $item_total, $digits ) : '';

			?>
			<td><?php echo esc_html( $bill_item_name ); ?></td>
			<td class="text-center" id="bill-item-count-<?php echo $key; ?>"><?php echo esc_html( $item_count ); ?></td>
			<td class="text-center"><?php echo esc_html( $bill_items[ $key ]['unit'] ); ?></td>
			<td class="price"><?php echo esc_html( $item_price_print ); ?></td>
			<td class="price"><?php echo esc_html( $item_price_total_print ); ?></td>
			<td class="price"><?php echo esc_html( $item_tax_rate ); ?></td>
			<td class="price"><?php echo esc_html( $item_tax_value_print ); ?></td>
			<td class="price"><?php echo esc_html( $item_total_print ); ?></td>
		<?php else : ?>
			<td></td>
			<td class="text-center" id="bill-item-count-<?php echo $key; ?>">　</td>
			<td class="text-center">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
		<?php endif; ?>
		</tr>
		<?php
		// 小計
		$tax_array = bill_vektor_tax_array();
		
		foreach( $tax_array as $tax_rate ) {
			if ( $item_tax_rate === $tax_rate ) {
				$tax_total[$tax_rate]['rate']  = $item_tax_rate . '％対象';
				$tax_total[$tax_rate]['price'] = ! empty( $tax_total[$tax_rate]['price'] ) ? $tax_total[$tax_rate]['price'] + $item_price_total : $item_price_total;
				$tax_total[$tax_rate]['tax']   = ! empty( $tax_total[$tax_rate]['tax'] )   ? $tax_total[$tax_rate]['tax'] + $item_tax_value : $item_tax_value;
				$tax_total[$tax_rate]['total'] = ! empty( $tax_total[$tax_rate]['total'] ) ? $tax_total[$tax_rate]['total'] + $item_total : $item_total;
			}
		}
			
	} // foreach ($bill_items as $key => $value) {

} // if ( is_array( $bill_items ) ) {
?>

</tbody>
<?php if ( true === $lite_tax_flag ) : ?>
<tfoot>
	<tr><td>＊：軽減税率対象</td></tr>
</tfoot>
<?php endif; ?>
</table>

<?php

global $post;
$bill_total_price_display = ( isset( $post->bill_total_price_display[0] ) ) ? $post->bill_total_price_display[0] : '';
if ( $bill_total_price_display != 'hidden' ) {
	$bill_total = array();
?>
<table class="table table-bordered table-bill table-bill-total">
<tr><th>税率</th><th>税抜金額</th><th>消費税額</th><th>税込金額</th></tr>
<?php foreach ( $tax_total as $total ) : ?>
	<?php
	$bill_total['price'] = ! empty( $bill_total['price'] ) ? $bill_total['price'] + $total['price'] : $total['price'];
	$bill_total['tax']   = ! empty( $bill_total['tax'] )   ? $bill_total['tax'] + $total['tax'] : $total['tax'];
	$bill_total['total'] = ! empty( $bill_total['total'] ) ? $bill_total['total'] + $total['total'] : $total['total'];
	?>
	<?php if( ! empty( $total['price'] ) && ! empty( $total['tax'] ) && ! empty( $total['total'] ) ) : ?>
		<tr>
			<th><?php echo esc_html( $total['rate'] ) ?></th>
			<td class="price">¥ <?php echo number_format( $total['price'], $digits ) ?></td>
			<td class="price">¥ <?php echo number_format( $total['tax'], $digits ) ?></td>
			<td class="price">¥ <?php echo number_format( $total['total'], $digits ) ?></td>
		</tr>
	<?php endif; ?>
<?php endforeach; ?>

<tr>
	<th>合計金額</th>
	<td class="price">¥ <?php echo number_format( $bill_total['price'], $digits ); ?></td>
	<td class="price">¥ <?php echo number_format( $bill_total['tax'], $digits ); ?></td>
	<td class="price">¥ <?php echo number_format( $bill_total['total'], $digits ); ?></td>
</tr>
</table>

<?php } // if ( $post->bill_total_price_display[0] != 'hidden' ) { ?>
