<?php
/*
---------------------------------------------
  管理画面の書類一覧（edit.php）で取引先名（手入力・登録済）による
  検索・並び替えができるようにする（issue #295）
---------------------------------------------
  対象を管理画面のメインクエリ・対象投稿タイプ（post・estimate）に限定する判定
	bill_is_target_admin_document_query()

  検索・並び替えの拡張が実際に必要なクエリかどうかの判定（JOINを無条件に足さないため）
	bill_admin_client_query_needs_client_join()

  取引先名で並び替えるときの orderby 識別子
	bill_get_client_orderby_key()

  取引先（手入力・登録済）を引くための LEFT JOIN
	bill_admin_client_search_join()

  取引先名を検索対象へ追加する（既存の $search は温存し、OR で条件を追加する）
	bill_admin_client_search()

  複数の client 投稿・postmeta 行が偶然重複していても結果行が増殖しないようにする
	bill_admin_client_search_distinct()

  取引先名で並び替える
	bill_admin_client_orderby()

  取引先カラムをソート可能列として登録する
	bill_register_client_sortable_column()
	bill_add_client_sortable_column()
/*-------------------------------------------*/

/**
 * 取引先名の検索・並び替えを適用してよい管理画面のクエリかどうかを判定する
 *
 * 次の3条件をすべて満たす場合のみ対象とする。
 * 1. 管理画面のリクエストであること（is_admin()）
 * 2. メインクエリであること（$query->is_main_query()）。管理画面の投稿一覧
 *    （WP_Posts_List_Table）はグローバルの $wp_query（＝ $wp_the_query）を再利用して
 *    クエリを実行するため、この判定で正しく true になる。クイック編集・投稿検索
 *    ウィジェットなど管理画面内の他のサブクエリはメインクエリではないため対象外になる
 * 3. 対象の投稿タイプ（post・estimate）であること。bill_get_client_column_post_types()
 *    （inc/functions-admin-columns.php）を再利用し、取引先カラムを表示する投稿タイプと
 *    常に同じ範囲になるようにする
 *
 * post_type クエリー変数が配列（複数投稿タイプの横断表示）の場合は対象外とする。
 * 対象を限定できないうえ、想定していない組み合わせのクエリへ拡張を及ぼさないため。
 *
 * フロント側（is_admin() が false）のクエリ、CSVエクスポート（get_posts() は
 * メインクエリではない）、他プラグインのクエリには一切影響しない。
 *
 * @param WP_Query $query 判定対象のクエリ。
 * @return bool 取引先名の検索・並び替えを適用してよい場合は true。
 */
function bill_is_target_admin_document_query( $query ) {
	if ( ! ( $query instanceof WP_Query ) || ! is_admin() || ! $query->is_main_query() ) {
		return false;
	}

	$post_type = $query->get( 'post_type' );
	if ( ! is_scalar( $post_type ) || '' === (string) $post_type ) {
		return false;
	}

	return in_array( (string) $post_type, bill_get_client_column_post_types(), true );
}

/**
 * 取引先名を引くための LEFT JOIN（postmeta・posts への結合）が実際に必要かどうかを判定する
 *
 * 対象クエリ（bill_is_target_admin_document_query()）であっても、検索語（s）が
 * 無く、かつ取引先名での並び替え（orderby=bill_client_name）でもない通常の一覧表示では
 * JOIN が不要。管理画面の一覧を開くたびに常時 JOIN させるのは無駄なコストのため、
 * 実際に検索・並び替えを行うリクエストに限定する。
 *
 * @param WP_Query $query 判定対象のクエリ。
 * @return bool JOINが必要な場合は true。
 */
function bill_admin_client_query_needs_client_join( $query ) {
	if ( ! bill_is_target_admin_document_query( $query ) ) {
		return false;
	}

	$has_search  = is_scalar( $query->get( 's' ) ) && '' !== (string) $query->get( 's' );
	$has_orderby = bill_get_client_orderby_key() === $query->get( 'orderby' );

	return $has_search || $has_orderby;
}

/**
 * 取引先名で並び替えるときの orderby 識別子を取得する
 *
 * 管理画面の取引先カラム（bill_get_client_column_key()）をソート可能列として
 * 登録する際に URL の orderby パラメーターへ使う識別子。カラムキー（表示・出力用）と
 * 同じ文字列を直書きで使い回さず専用の関数に切り出しているのは、カラムキーと
 * orderby 識別子が将来別の値になっても、変更箇所をこの関数1つに留めるため。
 *
 * @return string orderby 識別子。
 */
function bill_get_client_orderby_key() {
	return 'bill_client_name';
}

/**
 * 取引先（手入力・登録済）の名前を引くための LEFT JOIN を追加する
 *
 * 3本の LEFT JOIN を追加する。
 * 1. bill_client_manual_meta: 取引先（イレギュラー）の手入力名（bill_client_name_manual）
 * 2. bill_client_id_meta: 取引先（登録済）のID（bill_client）
 * 3. bill_client_post: 2 のIDが指す取引先（client）投稿。post_type = 'client' も
 *    結合条件（ON句）に含めることで、bill_client に取引先以外の投稿ID（削除済み・
 *    不正値・別の投稿タイプ）が保存されていても、その投稿のタイトルを拾わない
 *    （PR #341 で修正した敬称の不具合と同種の穴を防ぐ）。bill_get_client_id() の
 *    ように absint() による厳密な数値検証まではSQL側では行わないが、MySQL は
 *    非数値・負数の文字列を数値へ暗黙変換して比較するため、存在しないIDへの
 *    変換結果になり、意図しない投稿へマッチすることはない
 *
 * すべて LEFT JOIN（INNER JOIN ではない）にしているのは、取引先が未設定の書類が
 * 結合によって結果から除外されないようにするため（並び替え時に書類が消えないことの
 * 必須要件を満たすための前提）。
 *
 * 対象クエリ（bill_admin_client_query_needs_client_join()）以外では $join を
 * そのまま返し、既存の値を上書きしない（他のプラグイン・フロント側・
 * 対象外の投稿タイプのクエリに影響しない）。
 *
 * @param string   $join  既存の JOIN 句。
 * @param WP_Query $query 対象のクエリ。
 * @return string JOIN句（対象クエリの場合は追記、それ以外は $join をそのまま）。
 */
function bill_admin_client_search_join( $join, $query ) {
	if ( ! bill_admin_client_query_needs_client_join( $query ) ) {
		return $join;
	}

	global $wpdb;

	$join .= " LEFT JOIN {$wpdb->postmeta} AS bill_client_manual_meta ON ( {$wpdb->posts}.ID = bill_client_manual_meta.post_id AND bill_client_manual_meta.meta_key = 'bill_client_name_manual' )";
	$join .= " LEFT JOIN {$wpdb->postmeta} AS bill_client_id_meta ON ( {$wpdb->posts}.ID = bill_client_id_meta.post_id AND bill_client_id_meta.meta_key = 'bill_client' )";
	$join .= " LEFT JOIN {$wpdb->posts} AS bill_client_post ON ( bill_client_post.ID = bill_client_id_meta.meta_value AND bill_client_post.post_type = 'client' )";

	return $join;
}
add_filter( 'posts_join', 'bill_admin_client_search_join', 10, 2 );

/**
 * WordPress コアが組み立てた $search 文字列から、対応する閉じ括弧の位置を返す
 *
 * WP_Query::parse_search() が組み立てる $search は " AND (INNER)" という形（括弧の
 * ネストを含む）になるため、単純な strrpos( $search, ')' ) では INNER の中にある
 * 別の閉じ括弧を拾ってしまう場合がある。括弧の深さを数えながら、$start にある
 * 開き括弧に対応する閉じ括弧の位置を正しく探す。
 *
 * @param string $haystack 探索対象の文字列。
 * @param int    $start    開き括弧 "(" の位置。
 * @return int|false 対応する閉じ括弧の位置。見つからない場合は false。
 */
function bill_find_matching_paren( $haystack, $start ) {
	$depth  = 0;
	$length = strlen( $haystack );

	for ( $i = $start; $i < $length; $i++ ) {
		if ( '(' === $haystack[ $i ] ) {
			++$depth;
		} elseif ( ')' === $haystack[ $i ] ) {
			--$depth;
			if ( 0 === $depth ) {
				return $i;
			}
		}
	}

	return false;
}

/**
 * 取引先名（手入力・登録済）を検索対象へ追加する
 *
 * WordPress コアが組み立てた既存の $search（件名・本文などの一致条件）は一切変更せず
 * 温存したうえで、取引先名の一致条件を OR で追加する。$search を丸ごと自前の条件へ
 * 差し替えると、コアの検索対象カラムの決定ロジック（search_columns フィルター・
 * 投稿タイプによる対象カラムの違い等）や、複数語検索時の「語ごとにAND、各語内は
 * カラムをOR」という標準の挙動を壊してしまうため、既存の $search 文字列を
 * そのまま活かす形にしている。
 *
 * 具体的には、コアの $search（" AND (INNER)" という形）から INNER 部分を括弧の
 * 対応を数えて取り出し、"(INNER) OR (取引先名の一致条件)" という形に組み替える。
 * 取引先名の一致条件は、既存の検索語（$query->get( 'search_terms' )。コアが
 * 同じ語に分割済みのものをそのまま再利用する）ごとに、手入力名・登録済取引先の
 * 投稿タイトルのどちらかに一致すれば良い（OR）という条件を作り、語をまたいでは
 * コアの標準挙動と同じ AND で結合する。
 *
 * 語ごとに「(既存の一致条件) OR (取引先名の一致条件)」という形にせず、
 * 「(既存の一致条件をすべての語でANDしたもの) OR (取引先名の一致条件をすべての
 * 語でANDしたもの)」という2つの塊の OR にしている（司からの指示どおり）。
 * そのため、複数語のうち一方が件名、もう一方が取引先名だけに含まれるような
 * 混在ケースはヒットしない。既存の $search の構造を変更せずに安全に拡張する
 * ための設計上のトレードオフとして、実装時に司と合意済み。
 *
 * @param string   $search WordPress コアが組み立てた検索条件のSQL片。
 * @param WP_Query $query  対象のクエリ。
 * @return string 検索条件のSQL片（対象クエリの場合は取引先名の条件を追加、それ以外は $search をそのまま）。
 */
function bill_admin_client_search( $search, $query ) {
	if ( '' === $search || ! bill_admin_client_query_needs_client_join( $query ) ) {
		return $search;
	}

	$search_terms = $query->get( 'search_terms' );
	if ( empty( $search_terms ) || ! is_array( $search_terms ) ) {
		return $search;
	}

	// コアの $search は " AND (INNER)"（末尾に post_password 条件が続くこともある）という
	// 形を前提にしている。想定と異なる形（コアの実装が変わった等）の場合は、
	// 既存の検索条件を壊さないよう安全側に倒してそのまま返す
	$prefix = ' AND (';
	if ( 0 !== strpos( $search, $prefix ) ) {
		return $search;
	}

	$open_paren_pos  = strlen( ' AND ' );
	$close_paren_pos = bill_find_matching_paren( $search, $open_paren_pos );
	if ( false === $close_paren_pos ) {
		return $search;
	}

	// 開き括弧の直後から閉じ括弧の直前までが INNER（コア標準の一致条件そのもの）
	$core_inner = substr( $search, $open_paren_pos + 1, $close_paren_pos - $open_paren_pos - 1 );
	// 閉じ括弧より後ろ（未ログイン時の post_password 条件等）はそのまま残す
	$suffix = substr( $search, $close_paren_pos + 1 );

	global $wpdb;

	// 語ごとに「手入力名 OR 登録済取引先名」の一致条件を作り、語をまたいでは AND で結合する
	// （コア標準の「語ごとにAND、各語内はカラムをOR」という挙動を取引先名にも合わせる）
	$client_term_groups = array();
	foreach ( $search_terms as $term ) {
		// esc_like() でLIKEのワイルドカード（% _）をエスケープしたうえで、
		// $wpdb->prepare() でSQL文字列として安全にエスケープ・クォートする
		$like                  = '%' . $wpdb->esc_like( $term ) . '%';
		$client_term_groups[] = $wpdb->prepare(
			'(bill_client_manual_meta.meta_value LIKE %s OR bill_client_post.post_title LIKE %s)',
			$like,
			$like
		);
	}
	$client_match = implode( ' AND ', $client_term_groups );

	return $prefix . '(' . $core_inner . ') OR (' . $client_match . ')' . ')' . $suffix;
}
add_filter( 'posts_search', 'bill_admin_client_search', 10, 2 );

/**
 * 取引先名の結合による行の重複を防ぐ
 *
 * bill_client・bill_client_name_manual の postmeta 行が何らかの理由（過去の不具合等）で
 * 同じ書類に複数保存されていた場合、LEFT JOIN によって同じ書類が複数行として
 * 返ってきてしまう可能性がある。DISTINCT を指定して防御する。
 *
 * @param string   $distinct 既存の DISTINCT 句。
 * @param WP_Query $query    対象のクエリ。
 * @return string 'DISTINCT'（対象クエリでJOINが必要な場合）または既存の $distinct。
 */
function bill_admin_client_search_distinct( $distinct, $query ) {
	if ( ! bill_admin_client_query_needs_client_join( $query ) ) {
		return $distinct;
	}

	return 'DISTINCT';
}
add_filter( 'posts_distinct', 'bill_admin_client_search_distinct', 10, 2 );

/**
 * 取引先名で並び替える
 *
 * 表示（bill_get_client_name()）と同じ優先順位で、手入力名（bill_client_name_manual）を
 * 優先し、無ければ登録済取引先（bill_client が指す投稿）のタイトルを使う。
 * NULLIF() で空文字をNULL扱いにしてから COALESCE() でフォールバックする。
 *
 * 手入力・登録済のどちらも無い書類は、この式全体がNULLになる。LEFT JOIN のため
 * 結果から除外されることはなく（一覧から消えない）、MySQL の既定仕様により
 * ASC では先頭、DESC では末尾にまとまる（植草さんからの必須要件）。
 *
 * 第2並び替えキーとして発行日（post_date）を同じ並び順方向で追加している。
 * 取引先名が同じ・またはどちらも未設定の書類が複数ある場合に、並び順が
 * 実行のたびに不定にならないようにするため。
 *
 * @param string   $orderby 既存の ORDER BY 句。
 * @param WP_Query $query   対象のクエリ。
 * @return string ORDER BY句（対象クエリの場合は取引先名基準、それ以外は $orderby をそのまま）。
 */
function bill_admin_client_orderby( $orderby, $query ) {
	if ( bill_get_client_orderby_key() !== $query->get( 'orderby' ) || ! bill_is_target_admin_document_query( $query ) ) {
		return $orderby;
	}

	global $wpdb;

	$order = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';

	return "COALESCE( NULLIF( bill_client_manual_meta.meta_value, '' ), bill_client_post.post_title ) {$order}, {$wpdb->posts}.post_date {$order}";
}
add_filter( 'posts_orderby', 'bill_admin_client_orderby', 10, 2 );

/**
 * 取引先カラムをソート可能列として登録するフックを登録する
 *
 * bill_get_client_column_post_types()（inc/functions-admin-columns.php）と同じ
 * 投稿タイプ（post・estimate）を対象にする。
 *
 * @return void
 */
function bill_register_client_sortable_column() {
	foreach ( bill_get_client_column_post_types() as $post_type ) {
		add_filter( "manage_edit-{$post_type}_sortable_columns", 'bill_add_client_sortable_column' );
	}
}
add_action( 'admin_init', 'bill_register_client_sortable_column' );

/**
 * 取引先カラムをソート可能列の一覧へ追加する
 *
 * 見出しのリンク生成・現在の並び替え状態を示す aria-sort 属性・フォーカス表示は
 * WordPress コア（WP_List_Table）が担うため、ここでは orderby 識別子の登録のみ行う
 * （自前でリンクを組み立てると、これらのアクセシビリティ対応が欠落するため。
 * 植草さんからの指示）。
 *
 * @param array $columns 既存のソート可能列（カラムキー => orderby識別子の連想配列）。
 * @return array 取引先カラムを追加したソート可能列の配列。
 */
function bill_add_client_sortable_column( $columns ) {
	$columns[ bill_get_client_column_key() ] = bill_get_client_orderby_key();
	return $columns;
}
