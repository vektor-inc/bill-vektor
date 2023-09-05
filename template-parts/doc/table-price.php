<table class="table table-bordered table-striped table-bill">
<thead>
<tr>
<th class="text-center bill-cell-name">品目</th>
<th class="text-center bill-cell-count">数量</th>
<th class="text-center bill-cell-unit">単位</th>
<th class="text-center bill-cell-single-price">単価</th>
<th class="text-center bill-cell-excluding-tax">税抜金額</th>
<th class="text-center bill-cell-tax-rate">消費税率</th>
<th class="text-center bill-cell-tax-price">消費税額</th>
<th class="text-center bill-cell-total-price">税込金額</th>
</tr>
</thead>
<tbody>
<?php
global $post;
$bill_items           = get_post_meta( $post->ID, 'bill_items', true );
$bill_item_sub_fields = array( 'name', 'count', 'unit', 'price' );
$bill_total           = 0;
$old_tax_rate = get_post_meta( $post->ID, 'bill_tax_rate', true );
$old_tax_type = get_post_meta( $post->ID, 'bill_tax_type', true );
// 消費税率の配列
$tax_array = bill_vektor_tax_array();
// 軽減税率対象があるか
$lite_tax_flag = false;
// 金額の小数点以下の桁数
$digits = apply_filters( 'item_price_print_digits', 0 );
if ( is_array( $bill_items ) ) {
	$tax_total = array();
	// 行のループ
	foreach ( $bill_items as $key => $bill_item ) {
		?>
		<tr>
		<?php
		// 品目毎の税率指定がない場合
		if ( empty( $bill_item['tax-rate'] ) ) {
			// 税率情報を取得
			$bill_item['tax-rate'] = bill_vektor_fix_tax_rate( $old_tax_rate, $post->post_date );
		}
		// 品目毎に税別・税込の指定がない場合
		if ( empty( $bill_item['tax-type'] ) ) {
			// 税込・税抜きを取得
			$bill_item['tax-type'] = bill_vektor_fix_tax_type( $old_tax_type );
		}

		if ( 
			! empty( $bill_item['name'] ) &&
			! empty( $bill_item['count'] ) &&
			! empty( $bill_item['unit'] ) &&
			! empty( $bill_item['price'] ) &&
			! empty( $bill_item['tax-rate'] ) &&
			! empty( $bill_item['tax-type'] )
		) :
			// 品目
			// 軽減税率対象なら＊を付加
			if ( $bill_item['tax-rate'] !== $tax_array[0] && $bill_item['tax-rate'] !== '0%' ) {
				$bill_item_name = $bill_item['name'] . '＊';
			// そうでなければ通常通り
			} else {
				$bill_item_name = $bill_item['name'];
			}

			// 対象品目の消費税率
			$item_tax_rate       = $bill_item['tax-rate'];							
			$item_tax_rate_value = 0.01 * intval( str_replace( '%', '', $item_tax_rate ) );

			// 個数を数値にフォーマット
			$item_count = bill_item_number( $bill_item['count'] );

			// 単価を数値にフォーマット
			$item_price = bill_vektor_invoice_unit_plice( bill_item_number( $bill_item['price'] ), $item_tax_rate_value, $bill_item['tax-type'] );
	
			// 単価と個数と税率が数値なら続行 ///////////////////////////////////////////////////////
			if ( is_numeric( $item_count ) && is_numeric( $item_price ) && is_numeric( $item_tax_rate_value ) ) :
				// 単価の表示
				$item_price_print = '¥ ' . number_format( $item_price, $digits );

				// 対象品目の税抜き合計金額
				$item_price_total       =  bill_vektor_invoice_total_plice( $item_price, $item_count );
				$item_price_total_print = '¥ ' . number_format( $item_price_total, $digits );	

				if ( $item_tax_rate !== $tax_array[0] && $item_tax_rate !== '0%' ) {
					$lite_tax_flag = true;
				}			
	
				// 対象品目の合計消費税額
				$item_tax_value       = bill_vektor_invoice_tax_plice(  $item_price_total, $item_tax_rate_value );
				$item_tax_value_print = '¥ ' . number_format( $item_tax_value, $digits );
				$form_item_tax_rate   = $item_tax_rate !== '0%' ? $item_tax_rate : __( '非課税', 'bill-vektor' );

				// 対象品目の合計税込金額
				$item_total = bill_vektor_invoice_full_plice( $item_price_total, $item_tax_value );
				$item_total_print = '¥ ' . number_format( $item_total, $digits );
	
				?>
				<td><?php echo esc_html( $bill_item_name ); ?></td>
				<td class="text-center" id="bill-item-count-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $item_count ); ?></td>
				<td class="text-center"><?php echo esc_html( $bill_item['unit'] ); ?></td>
				<td class="price"><?php echo esc_html( $item_price_print ); ?></td>
				<td class="price"><?php echo esc_html( $item_price_total_print ); ?></td>
				<td class="price"><?php echo esc_html( $form_item_tax_rate ); ?></td>
				<td class="price">-</td>
				<td class="price">-</td>
			<!-- // 数値でなければ計算しようがないので空欄に -->
			<?php else : ?>
					<td><?php echo esc_html( $bill_item_name ); ?></td>
					<td class="text-center" id="bill-item-count-<?php echo esc_attr( $key ); ?>">　</td>
					<td class="text-center">　</td>
					<td class="price">　</td>
					<td class="price">　</td>
					<td class="price">　</td>
					<td class="price">　</td>
					<td class="price">　</td>
			<?php endif; ?>

		<!-- // 品目のみ入力されている場合 -->
		<?php elseif ( isset( $bill_item['name'] ) ) : ?>
			<td><?php echo esc_html( $bill_item['name'] ); ?></td>
			<td class="text-center" id="bill-item-count-<?php echo esc_attr( $key ); ?>">　</td>
			<td class="text-center">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
		<!-- // 値が埋まっていなければ表示のしようががないので空欄に -->
		<?php else : ?>
			<td>　</td>
			<td class="text-center" id="bill-item-count-<?php echo esc_attr( $key ); ?>">　</td>
			<td class="text-center">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
			<td class="price">　</td>
		<?php endif; ?>
		</tr>
		<?php			
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


$bill_total_price_display = ( isset( $post->bill_total_price_display[0] ) ) ? $post->bill_total_price_display[0] : '';
if ( $bill_total_price_display != 'hidden' ) {
	// 税率の金額を格納する配列
	$bill_total = array();
	// 税率ごとの合計金額
	$tax_total  = bill_vektor_invoice_each_tax( $post );
?>
<table class="table table-bordered table-bill table-bill-total">
<tr><th>税率</th><th>税抜金額</th><th>消費税額</th><th>税込金額</th></tr>
<!-- // 税率ごとに各種金額を算出 -->
<?php if ( ! empty( $tax_total ) && is_array( $tax_total ) ) : ?>
	<?php foreach ( $tax_total as $total ) : ?>
		<?php
		// 税抜金額
		$bill_total['price'] = ! empty( $bill_total['price'] ) ? $bill_total['price'] + $total['price'] : $total['price'];
		// 消費税額
		$bill_total['tax']   = ! empty( $bill_total['tax'] )   ? $bill_total['tax'] + $total['tax'] : $total['tax'];
		// 税込金額
		$bill_total['total'] = ! empty( $bill_total['total'] ) ? $bill_total['total'] + $total['total'] : $total['total'];
		// 表示用消費税率
		$form_tax_rate = $total['rate'] !== '0%対象' ? $total['rate'] : __( '非課税', 'bill-vektor' );
		// 表示用消費税額
		$form_tax      = $total['rate'] !== '0%対象' ? '¥ '. number_format( $total['tax'], $digits ) : '-';
		?>
		<?php
		if( ! empty( $total['price'] ) && ! empty( $form_tax ) && ! empty( $total['total'] ) ) : 
			$form_tax_rate = $total['rate'] !== '0%対象' ? $total['rate'] : __( '非課税', 'bill-vektor' );
		?>
			<tr>
				<th><?php echo esc_html( $form_tax_rate ) ?></th>
				<td class="price">¥ <?php echo number_format( $total['price'], $digits ) ?></td>
				<td class="price"><?php echo $form_tax ?></td>
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

<?php endif; ?>
</table>

<?php } // if ( $post->bill_total_price_display[0] != 'hidden' ) { ?>
