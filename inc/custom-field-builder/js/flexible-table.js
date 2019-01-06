;(function($){

	// 行を追加
	jQuery('.row-control .add-row').click(function(){
		var $master_html = '';
		jQuery(this).closest('tr').clone(true).insertAfter(jQuery(this).closest('tr'));

		// 複製した行の値を消す
		jQuery(this).closest('tr').next().find('td').each(function(){
			jQuery(this).find('input[type=text]').attr({'value':''});
			jQuery(this).find('option').removeAttr('selected');
			jQuery(this).find('textarea').text('');
		});

		row_count_reset();
	});

	// 行を削除
	jQuery('.row-control .del-row').click(function(){
		jQuery(this).closest('tr').remove();
		row_count_reset();
	});

	function row_count_reset(){
		jQuery('.row-control').each(function(){
		jQuery(this).find('tbody tr').each(function(i){

			jQuery(this).find( 'input.flexible-field-item' ).each(function(){

				// 置換対象の文字列
				var input_name = jQuery(this).attr("name");

				// []内が数字にマッチしている部分のみ置換する。
				// 数字の前部分が $1 、数字の部分が今のループ回数 、後部分が $3
				var result = input_name.replace(/(.*\[)(\d+)(\].*)/, "$1" + i + "$3" );
				jQuery(this).attr({"name":result}).attr({"id":result});

			});

			// 行番号をふり直す
			jQuery(this).find( '.cell-number' ).text(i + 1);
		});
		});
	}

	jQuery('.sortable').sortable();
	jQuery('.sortable').disableSelection();

	jQuery('.sortable').bind('sortstop', function (e, ui) {
	    // ソートが完了したら実行される。

	    row_count_reset();
	    jQuery('.sortable').sortable();

	})


})(jQuery);
