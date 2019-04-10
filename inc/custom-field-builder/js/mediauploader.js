/*-------------------------------------------*/
/* メディアアップローダー
/*-------------------------------------------*/
jQuery(document).ready(function($){
    var custom_uploader;
    // var media_id = new Array(2);　//配列の宣言
    // media_id[0] = "head_logo";
    // var media_btn = '#media_' + media_id[i];
    // var media_target = '#' + media_id[i];
    jQuery('.cfb_media_btn').click(function(e) {

        // 画像URLで値を返す場合はフォームの input タグのIDにsrcを含める
        // クリックされたボタンのidを取得 → ボタンの id名 から media_src_ を削除して かわりに # をつける
        media_target_src    = jQuery(this).attr('id').replace(/media_src_/g,'#');

        // id で値を返す場合
        media_target    = jQuery(this).attr('id').replace(/media_/g,'#');
        thumb_src       = jQuery(this).attr('id').replace(/media_/g,'#thumb_');

        e.preventDefault();
        if (custom_uploader) {
            custom_uploader.open();
            return;
        }
        custom_uploader = wp.media({
            title: '画像を選択',
            // library: {
            //     type: 'image'
            // },
            button: {
                text: '画像を選択'
            },
            multiple: false, // falseにすると画像を1つしか選択できなくなる
        });
        custom_uploader.on('select', function() {
            var images = custom_uploader.state().get('selection');
            images.each(function(file){
                // urlを返す場合
                jQuery(media_target_src).attr('value', file.toJSON().url );
                // idを返す場合
                jQuery(media_target).attr('value', file.toJSON().id );
                jQuery(thumb_src).attr('src', file.toJSON().url );
            });
        });
        custom_uploader.open();
    });

		/*	リセット処理
		/*-------------------------------------------*/
		jQuery('.media_reset_btn').click(function(e) {
        media_target_id			= jQuery(this).attr('id').replace(/media_reset_/g,'#');
        thumb_id						= jQuery(this).attr('id').replace(/media_reset_/g,'#thumb_');
        jQuery(media_target_id).attr('value', '' );
        jQuery(thumb_id).attr('src', '' );
				jQuery(thumb_id).attr('srcset', '' );
    });
});
