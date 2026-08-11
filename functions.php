<?php
/*
-------------------------------------------
  Load Module
  Theme setup
  Load Theme CSS & JS
  Load Admin CSS & JS
  WidgetArea initiate
  Add Post Type Client
  Remove_post_editor_support
  Replace Post Label
  Replace Document Title
  ログイン画面のロゴ
-------------------------------------------
*/


$theme_opt = wp_get_theme( get_template() );
define( 'BILLVEKTOR_THEME_VERSION', $theme_opt->Version );

/**
 * Composer Autoload
 */
$autoload_path = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
// vendor ディレクトリがない状態で誤配信された場合に Fatal Error にならないようにファイルの存在確認.
if ( file_exists( $autoload_path ) ) {
	// Composer のファイルを読み込み ( composer install --no-dev )
	require_once $autoload_path;
}

// Update Checker
if ( class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ){
	$my_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/vektor-inc/bill-vektor',
		__FILE__,
		'bill-vektor'
	);
	$my_update_checker->getVcsApi()->enableReleaseAssets();
}

/**
 * 存在する消費税率の配列
 * 
 * 消費税率の高い順に並べた配列。2番目以降は軽減税率フラグが立つ
 * @return $tax_array : 10%, 8%, 0%
 */
function bill_vektor_tax_array() {
	$tax_array = array( '10%', '8%', '0%' );
	return $tax_array;
}

/*
-------------------------------------------
  Load Module
-------------------------------------------
*/
require_once dirname( __FILE__ ) . '/inc/custom-field-builder-config.php';
require_once dirname( __FILE__ ) . '/inc/custom-field/custom-field-normal-bill.php';
require_once dirname( __FILE__ ) . '/inc/custom-field/custom-field-normal-estimate.php';
require_once dirname( __FILE__ ) . '/inc/custom-field/custom-field-normal-client.php';
require_once dirname( __FILE__ ) . '/inc/custom-field/custom-field-table.php';
require_once dirname( __FILE__ ) . '/inc/custom-field/custom-field-table-bill.php';
require_once dirname( __FILE__ ) . '/inc/setting-page/setting-page.php';
require_once dirname( __FILE__ ) . '/inc/duplicate-doc/duplicate-doc.php';
require_once dirname( __FILE__ ) . '/inc/export/class.csv-export.php';
require_once dirname( __FILE__ ) . '/inc/functions-admin-columns.php';

get_template_part( 'inc/template-tags' );
get_template_part( 'inc/functions-limit-view' );
get_template_part( 'inc/functions-pre-get-posts' );


/*
-------------------------------------------
  Theme setup
-------------------------------------------
*/
function bill_theme_title() {
	// title tag
	add_theme_support( 'title-tag' );
	// custom menu
	register_nav_menus( array( 'Header Navigation' => 'Header Navigation' ) );
}
add_action( 'after_setup_theme', 'bill_theme_title' );

/*
-------------------------------------------
  Load Theme CSS & JS
-------------------------------------------
*/
function bill_theme_scripts() {

	// 静的HTMLで読み込んでいたCSSを読み込む
	wp_enqueue_style( 'bill-css-bootstrap', get_template_directory_uri() . '/assets/css/bootstrap.min.css', array(), '3.3.6' );
	wp_enqueue_style( 'bill-css', get_template_directory_uri() . '/assets/css/style.css', array( 'bill-css-bootstrap' ), BILLVEKTOR_THEME_VERSION );

	// テーマディレクトリ直下にある style.css を出力
	wp_enqueue_style( 'bill-theme-style', get_stylesheet_uri(), array( 'bill-css' ), BILLVEKTOR_THEME_VERSION );

	// テーマ用のjsを読み込む
	wp_enqueue_script( 'bill-js-bootstrap', get_template_directory_uri() . '/assets/js/bootstrap.min.js', array( 'jquery' ), BILLVEKTOR_THEME_VERSION, true );
	wp_register_script( 'datepicker', get_template_directory_uri() . '/inc/custom-field-builder/js/datepicker.js', array( 'jquery', 'jquery-ui-datepicker' ), BILLVEKTOR_THEME_VERSION, true );
	wp_enqueue_script( 'datepicker' );
}
add_action( 'wp_enqueue_scripts', 'bill_theme_scripts' );

/*
-------------------------------------------
  Load Admin CSS & JS
-------------------------------------------
*/
function bill_admin_scripts() {
	// 管理画面用のcss（第3引数は依存関係、第4引数はバージョン）
	wp_enqueue_style( 'bill-admin-css', get_template_directory_uri() . '/assets/css/admin-style.css', array(), BILLVEKTOR_THEME_VERSION );
}
add_action( 'admin_enqueue_scripts', 'bill_admin_scripts' );

/*
-------------------------------------------
  WidgetArea initiate
-------------------------------------------
*/
function bill_widgets_init() {
	register_sidebar(
		array(
			'name'          => 'Sidebar',
			'id'            => 'sidebar-widget-area',
			'before_widget' => '<aside class="sub-section section %2$s" id="%1$s">',
			'after_widget'  => '</aside>',
			'before_title'  => '<h4 class="sub-section-title">',
			'after_title'   => '</h4>',
		)
	);
}
add_action( 'widgets_init', 'bill_widgets_init' );


/*
-------------------------------------------
  Add Post Type Client
-------------------------------------------
*/
add_action( 'init', 'bill_add_post_type_client', 0 );
function bill_add_post_type_client() {
	register_post_type(
		'client', /* カスタム投稿タイプのスラッグ */
		array(
			'labels'             => array(
				'name'      => '取引先・送付状',
				'view_item' => '送付状を表示',
				'edit_item' => '送付状を編集',
			),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'has_archive'        => false,
			'supports'           => array( 'title' ),
			'menu_icon'          => 'dashicons-building',
			'menu_position'      => 3,
		)
	);
}
/*
-------------------------------------------
  Add Post Type Estimate
-------------------------------------------
*/
add_action( 'init', 'bill_add_post_type_estimate', 0 );
function bill_add_post_type_estimate() {
	register_post_type(
		'estimate',
		array(
			'labels'             => array(
				'name'         => '見積書',
				'edit_item'    => '見積書の編集',
				'add_new_item' => '見積書の作成',
			),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'has_archive'        => true,
			'supports'           => array( 'title' ),
			'menu_icon'          => 'dashicons-media-spreadsheet',
			'menu_position'      => 5,
		)
	);
	register_taxonomy(
		'estimate-cat',
		'estimate',
		array(
			'hierarchical'          => true,
			'update_count_callback' => '_update_post_term_count',
			'label'                 => '見積書カテゴリー',
			'singular_label'        => '見積書カテゴリー',
			'public'                => true,
			'show_ui'               => true,
		)
	);
}

/*
-------------------------------------------
  Flush Rewrite Rules On Version Change
-------------------------------------------
*/
/**
 * リライトルール（URLとページの対応表）をテーマのバージョン変更時のみ作り直す
 *
 * bill_add_post_type_client() / bill_add_post_type_estimate() でカスタム投稿タイプ
 * （取引先・見積書）とタクソノミー（見積書カテゴリー）を登録しているが、登録するだけでは
 * リライトルール（DBの rewrite_rules オプションにキャッシュされる対応表）は自動で
 * 作り直されない。そのため対応表が古いままだと、見積書の個別ページなどが404になる
 * 不具合が発生する（issue #35）。
 *
 * データベースに記録したバージョン（billvektor_rewrite_rules_version オプション）と
 * 現在のテーマバージョン（BILLVEKTOR_THEME_VERSION）が食い違う場合にのみ
 * flush_rewrite_rules() を実行することで、次の両方を自動的にカバーする。
 *
 * - テーマを新しく有効化したとき（オプション未記録）
 * - すでに不具合が出ているサイトが修正版のテーマへ更新したとき（記録値が古い）
 *
 * flush_rewrite_rules() は全投稿タイプ・全タクソノミーの対応表を再構築する重い処理のため、
 * バージョンが一致している間（毎リクエスト）は実行しない。
 *
 * この関数の PHPUnit テストには、検証できていない前提が1つある。テスト環境では
 * did_action( 'wp_loaded' ) が既に真のため flush_rewrite_rules() は同期的に実行されるが、
 * 本番の通常リクエストでは wp_loaded フックの実行タイミングで初めて真になるため、
 * 「記録は済んだが作り直しは wp_loaded 到達まで未実行」という遅延経路を通る
 * （wp_loaded に登録する理由は下の add_action() のコメントを参照）。PHPUnit のテストは
 * この本番の遅延実行パスまでは再現・検証できていない。
 *
 * @return void
 */
function bill_maybe_flush_rewrite_rules() {
	// オプションはサイト全体で1つの名前空間なので、関数プレフィックス（bill_）より
	// 衝突しにくい billvektor_ を使う
	$option_name      = 'billvektor_rewrite_rules_version';
	$recorded_version = get_option( $option_name );

	if ( BILLVEKTOR_THEME_VERSION === $recorded_version ) {
		return;
	}

	// 作り直しより先にバージョンを記録し、記録できたことを読み戻して確認する。
	// 作り直しを先に行うと、DBへの書き込みが失敗し続ける環境で、重い作り直しが
	// 未ログイン訪問者のリクエストも含めて毎回走り続けてしまう。
	// また更新直後に同時アクセスが集中した場合も、先に記録することで
	// 重複して作り直しが走る窓を最小にできる。
	update_option( $option_name, BILLVEKTOR_THEME_VERSION );
	if ( BILLVEKTOR_THEME_VERSION !== get_option( $option_name ) ) {
		return;
	}

	// $hard = false（ソフトフラッシュ）。このテーマは add_rewrite_rule() を使っておらず
	// .htaccess に書き足す内容が無いため、ファイル書き込みは行わない
	flush_rewrite_rules( false );
}
// wp_loaded で実行する。init の時点では、WP_Rewrite::flush_rules() が
// ! did_action( 'wp_loaded' ) のとき自分自身を wp_loaded に登録し直して即 return するため、
// 「バージョンの記録だけ済んで作り直しは wp_loaded まで持ち越し」の状態になる。
// この間に exit するプラグイン（メンテナンスモード系など）が挟まると、作り直されないまま
// 記録だけが残り、次のバージョンアップまで直らない。wp_loaded に登録すれば同期的に実行される。
// bill_add_post_type_client() / bill_add_post_type_estimate()（init・優先度0）による
// 投稿タイプ・タクソノミーの登録は wp_loaded の時点で確実に完了しているため、
// 判定・作り直しのタイミングとしても問題ない。
add_action( 'wp_loaded', 'bill_maybe_flush_rewrite_rules' );

/**
 * テーマを切り替えたときにバージョンの記録を削除する
 *
 * 他テーマ有効中にパーマリンクを保存されると、対応表（rewrite_rules オプション）から
 * 見積書・取引先のルールが消える。その状態でこのテーマへ戻した場合、バージョンの記録は
 * 変わっていないため bill_maybe_flush_rewrite_rules() の判定が「一致」のままとなり、
 * 作り直しが走らず同じ404が再発してしまう。テーマ切り替え時に記録を削除しておくことで、
 * 次の wp_loaded で必ず作り直しが走るようにする。
 *
 * @return void
 */
function bill_reset_rewrite_rules_version() {
	delete_option( 'billvektor_rewrite_rules_version' );
}
add_action( 'after_switch_theme', 'bill_reset_rewrite_rules_version' );

/*
-------------------------------------------
  Remove_post_editor_support
-------------------------------------------
*/
function bill_remove_post_editor_support() {
	remove_post_type_support( 'post', 'editor' );
}
add_action( 'init', 'bill_remove_post_editor_support' );


/*
-------------------------------------------
  Replace Post Label
-------------------------------------------
*/
function bill_change_post_type_args_post( $args ) {
	if ( isset( $args['rest_base'] ) && $args['rest_base'] == 'posts' ) {
		$args['labels']['name_admin_bar'] = '請求書';
		$args['labels']['name']           = '請求書';
		$args['labels']['edit_item']      = '請求書の編集';
		$args['labels']['add_new_item']   = '請求書の作成';
	}
	return $args;
}
add_filter( 'register_post_type_args', 'bill_change_post_type_args_post' );

/*
-------------------------------------------
  Replace Document Title
-------------------------------------------
*/
function bill_title_custom( $title ) {
	$target_post_types = array( 'post', 'estimate', 'receipt' );

	if ( is_single() ) {
		global $post;
		setup_postdata( $post );
		$post_type = bill_get_post_type();
		if ( in_array( $post_type['slug'], $target_post_types ) ) {
			// 書類種別
			$title = $post_type['name'] . '_';
			// 取引先名
			$title .= bill_get_client_name( $post );
			// 敬称
			$client_honorific = esc_html( get_post_meta( $post->bill_client, 'client_honorific', true ) );
			if ( $client_honorific ) {
				$title .= $client_honorific . '_';
			} else {
				$title .= '御中_';
			}
			// 件名
			$title     .= get_the_title() . '_';
				$title .= get_the_date( 'Ymd' );
		}
	}
	return strip_tags( $title );
}
add_filter( 'wp_title', 'bill_title_custom', 11 );
add_filter( 'pre_get_document_title', 'bill_title_custom', 11 );

/*
-------------------------------------------
  未来の投稿の公開
-------------------------------------------
*/
// 予約投稿機能を無効化
add_action( 'save_post', 'bill_future_publish', 99 );
add_action( 'edit_post', 'bill_future_publish', 99 );
function bill_future_publish() {
	global $wpdb;
	$sql  = 'UPDATE `' . $wpdb->prefix . 'posts` ';
	$sql .= 'SET post_status = "publish" ';
	$sql .= 'WHERE post_status = "future"';
	$wpdb->get_results( $sql );
}

function bill_immediately_publish( $id ) {
	global $wpdb;
	$q = 'UPDATE ' . $wpdb->posts . " SET post_status = 'publish' WHERE ID = " . (int) $id;
	$wpdb->get_results( $q );
}
add_action( 'future_event', 'bill_immediately_publish' );

/*
-------------------------------------------
  ログイン画面のロゴ
-------------------------------------------
*/

function custom_login_logo() { ?>
  <style>
	.login #login h1 a {
	  width: 300px;
	  height: 65px;
	  background: url(<?php echo get_template_directory_uri(); ?>/assets/images/head_logo.png) no-repeat 0 0;
	}
  </style>
	<?php
}
add_action( 'login_enqueue_scripts', 'custom_login_logo' );