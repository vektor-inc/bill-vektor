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

  取引先名の並び替えキーを SELECT 句へ追加する（DISTINCT と ORDER BY の併用エラー対策）
	bill_admin_client_search_fields()

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
 * 1. 管理画面のリクエストであること
 * 2. メインクエリであること（$query->is_main_query()）。管理画面の投稿一覧
 *    （WP_Posts_List_Table）はグローバルの $wp_query（＝ $wp_the_query）を再利用して
 *    クエリを実行するため、この判定で正しく true になる。クイック編集・投稿検索
 *    ウィジェットなど管理画面内の他のサブクエリはメインクエリではないため対象外になる
 * 3. 対象の投稿タイプ（post・estimate）であること。bill_get_client_column_post_types()
 *    （inc/functions-admin-columns.php）を再利用し、取引先カラムを表示する投稿タイプと
 *    常に同じ範囲になるようにする
 *
 * 管理画面のリクエストかどうかの判定に is_admin() を使わない。is_admin() は
 * $GLOBALS['current_screen'] があればそちらを優先するため、フロント側で
 * set_current_screen() を呼ぶ他のプラグインが同居すると、フロント側のリクエストでも
 * is_admin() が true を返してしまう（このテーマ自身の inc/functions-limit-view.php
 * （bill_no_login_redirect()）のコメントに明記されている前例）。その状態では
 * フロントのメインクエリにも JOIN と OR 条件が及んでしまい、後述の「フロント側には
 * 一切影響しない」という前提が崩れる。WP_ADMIN は wp-admin/admin.php が読み込まれた
 * 時点で定義される定数で、プラグインから後付けで立てられる current_screen と違って
 * 乗っ取られないため、functions-limit-view.php と同じ守り方に揃える。
 *
 * post_type クエリー変数が配列（複数投稿タイプの横断表示）の場合は対象外とする。
 * 対象を限定できないうえ、想定していない組み合わせのクエリへ拡張を及ぼさないため。
 *
 * フロント側のクエリ、CSVエクスポート（get_posts() はメインクエリではない）、
 * 他プラグインのクエリには一切影響しない。
 *
 * @param WP_Query $query 判定対象のクエリ。
 * @return bool 取引先名の検索・並び替えを適用してよい場合は true。
 */
function bill_is_target_admin_document_query( $query ) {
	if ( ! ( $query instanceof WP_Query ) || ! ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || ! $query->is_main_query() ) {
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
 * 3. bill_client_post: 2 のIDが指す取引先（client）投稿。結合条件（ON句）に
 *    次の2つを含めることで、bill_client に取引先以外の投稿ID・不正な値が
 *    保存されていても、無関係な投稿のタイトルを拾わない
 *    （PR #341 で修正した敬称の不具合と同種の穴を防ぐ）。
 *    - post_type = 'client'
 *    - meta_value REGEXP '^[1-9][0-9]*$'（先頭が1-9で始まる数字のみの文字列であること）。
 *      MySQL は文字列と数値の比較時、先頭から解釈できる数値部分だけを読み取って暗黙に
 *      数値へ変換するため、REGEXP を入れないと '123abc'・' 123'（前後空白）・'123.0'・
 *      '+123'・'1e2' のような値が実在する取引先IDに一致してしまう
 *      （bill_get_client_id() が absint() 変換後の文字列と元の値を厳密に
 *      文字列比較して弾いている値と同じ集合。安藤さんのレビュー指摘。
 *      以前のコメントは「意図しない投稿へマッチすることはない」としていたが、
 *      実際には上記の値がすり抜けてマッチしてしまっていたため訂正した）。
 *      先頭0つき（"0123"等）も bill_get_client_id() と同じく無効値として扱う。
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
	$join .= " LEFT JOIN {$wpdb->posts} AS bill_client_post ON ( bill_client_post.ID = bill_client_id_meta.meta_value AND bill_client_post.post_type = 'client' AND bill_client_id_meta.meta_value REGEXP '^[1-9][0-9]*$' )";

	return $join;
}
add_filter( 'posts_join', 'bill_admin_client_search_join', 10, 2 );

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
 * $search の中身は解析・分解しない。以前は " AND (INNER)" という形を前提に、
 * 括弧の対応を数えて INNER 部分を取り出す実装だったが、WordPress コアが
 * 組み立てる $search には検索語自体が `LIKE '%検索語%'` の形でそのまま埋め込まれるため、
 * 検索語に半角括弧（"(株" "8)" 等、日本語の業務データでは社名として普通に起こりうる）が
 * 含まれると、その括弧まで一緒に数えてしまい、対応する閉じ括弧の位置を取り違えていた。
 * 壊れ方は2通りで、SQL構文エラーになって一覧が0件表示になるケースと、取引先条件が
 * 本来と違うORグループへ紛れ込んで検索結果の意味が変わってしまうケースがあった
 * （安藤さんのレビューで、括弧を含む語2,571パターン中503パターンで再現）。
 *
 * 代わりに、$search を "( 1=1 <$search> )" という形でそのまま入れ子にする。
 * $search は常に " AND (...)" で始まる（WordPress コアの形式）ため、"1=1" の後ろに
 * そのまま連結すれば "1=1 AND (...)" という有効な真偽式になり、$search の中身が
 * 何であっても（括弧を含んでいても）解析せずに1つの塊として扱える。
 * 副作用として、未ログイン時にコアが付ける post_password の条件（$search の末尾に
 * " AND {$wpdb->posts}.post_password = ''" として続く）も同じ塊（OR の片側）に入るが、
 * この関数は管理画面のメインクエリに限定しており（bill_is_target_admin_document_query()）、
 * 管理画面へ到達している時点で必ずログイン済みのため、この副作用が実際に起こることはない。
 *
 * 取引先名の一致条件は、既存の検索語（$query->get( 'search_terms' )。コアが
 * 同じ語に分割済みのものをそのまま再利用する）ごとに、手入力名・登録済取引先の
 * 投稿タイトルのどちらかに一致すれば良い（OR）という条件を作り、語をまたいでは
 * コアの標準挙動と同じ AND で結合する。
 *
 * 除外検索（"-語"）にも対応する。コアは検索語の先頭が "-"（wp_query_search_exclusion_prefix
 * フィルターの既定値。このテーマではフィルターのカスタマイズを想定しないため既定値のみ
 * 対応する）の場合、その語を「含まないこと」（NOT LIKE を AND で結合）として扱う。
 * 取引先名側でも同じ扱いにしないと、除外語を1つ含めるだけで取引先名検索が
 * 黙って無効になったり（肯定条件のままAND結合すると常に不一致になり得る）、
 * 逆に取引先名に文字どおり "-語" が含まれる書類が除外から復活してしまう
 * （肯定のOR条件のままだと除外を無視して一致してしまうため）。
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

	global $wpdb;

	// 語ごとに「手入力名 OR 登録済取引先名」の一致条件（除外語の場合は両方とも
	// 含まないことを AND で要求する条件）を作り、語をまたいでは AND で結合する
	// （コア標準の「語ごとにAND、各語内はカラムをOR」という挙動を取引先名にも合わせる）。
	$client_term_groups = array();
	foreach ( $search_terms as $term ) {
		$term = (string) $term;

		// コアと同じ判定（parse_search()）。除外語は必ず先頭が "-" になる
		// （parse_search_terms() が "-" のみ・短すぎる除外語は事前に取り除いている）。
		$is_exclusion = '' !== $term && '-' === $term[0] && strlen( $term ) > 1;
		if ( $is_exclusion ) {
			$term = substr( $term, 1 );
		}

		// esc_like() でLIKEのワイルドカード（% _）をエスケープしたうえで、
		// $wpdb->prepare() でSQL文字列として安全にエスケープ・クォートする
		$like = '%' . $wpdb->esc_like( $term ) . '%';

		if ( $is_exclusion ) {
			// 手入力名・登録済取引先名のどちらにもその語を含まないことを要求する。
			// JOIN が結合しない場合（取引先未設定・不正値等）は各カラムが NULL になり、
			// "NULL NOT LIKE '%語%'" は SQL の三値論理で NULL（＝行から除外される）に
			// なってしまう。COALESCE() で先に空文字へ変換してから NOT LIKE を評価することで、
			// 「取引先名が無い＝その語を含まない」を正しく true として扱う
			$client_term_groups[] = $wpdb->prepare(
				'(COALESCE(bill_client_manual_meta.meta_value, \'\') NOT LIKE %s AND COALESCE(bill_client_post.post_title, \'\') NOT LIKE %s)',
				$like,
				$like
			);
		} else {
			$client_term_groups[] = $wpdb->prepare(
				'(bill_client_manual_meta.meta_value LIKE %s OR bill_client_post.post_title LIKE %s)',
				$like,
				$like
			);
		}
	}
	$client_match = implode( ' AND ', $client_term_groups );

	// $search の中身を解析せず、そのまま入れ子にする（理由は関数DocBlock参照）
	return ' AND ( ( 1=1 ' . $search . ' ) OR ( ' . $client_match . ' ) )';
}
add_filter( 'posts_search', 'bill_admin_client_search', 10, 2 );

/**
 * 取引先名の結合による行の重複を防ぐ
 *
 * bill_client・bill_client_name_manual の postmeta 行が何らかの理由（過去の不具合等）で
 * 同じ書類に複数保存されていた場合、LEFT JOIN によって同じ書類が複数行として
 * 返ってきてしまう可能性がある。DISTINCT を指定して防御する。
 *
 * 取引先名で並び替える場合（bill_admin_client_search_fields() が並び替えキーを
 * SELECT句へ追加する場合）も含め、検索・並び替えのどちらでも DISTINCT を維持する。
 * SELECT DISTINCT と、SELECT句に無い式でのORDER BYを併用すると MySQL の
 * sql_mode に ONLY_FULL_GROUP_BY が含まれる環境でエラー3065になり得るが、
 * 並び替えキーを SELECT句（posts_fields）にも追加しているため、DISTINCT と
 * ORDER BY の両方が同じSELECT済みの式を参照する形になり、この問題は起きない。
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
 * 取引先名の並び替えキーを SELECT 句へ追加する
 *
 * bill_admin_client_search_distinct() が付与する DISTINCT と、
 * bill_admin_client_orderby() が組み立てる（JOIN先の列を参照する）ORDER BY を
 * 併用すると、MySQL の sql_mode に ONLY_FULL_GROUP_BY が含まれる環境で
 * エラー3065（Expression of ORDER BY clause is not in SELECT list ...
 * this is incompatible with DISTINCT）になり、一覧そのものが表示できなくなる。
 * WordPress は通常この sql_mode を外す（wpdb::set_sql_mode()）が、
 * ホスティング側の設定で戻されている環境もあるため、この不具合の再発を防ぐ。
 *
 * 対策として、並び替えキーの式をエイリアス付きで SELECT 句へ明示的に追加し、
 * ORDER BY 側はそのエイリアスを参照する形にする（bill_admin_client_orderby() 側も
 * 合わせて変更している）。これにより DISTINCT と ORDER BY が同じ SELECT 済みの
 * 式を参照することになり、エラー3065を回避しつつ、DISTINCT による重複防止
 * （postmeta が重複していても行が増えない）も維持できる。
 *
 * 取引先名での並び替え（orderby=bill_client_name）のときだけ列を追加する。
 * 検索だけを行うリクエストではこの列は不要なため、SELECT句を無駄に太らせない。
 *
 * @param string   $fields 既存の SELECT 句。
 * @param WP_Query $query  対象のクエリ。
 * @return string SELECT句（並び替え対象クエリの場合は並び替えキーを追加、それ以外は $fields をそのまま）。
 */
function bill_admin_client_search_fields( $fields, $query ) {
	if ( bill_get_client_orderby_key() !== $query->get( 'orderby' ) || ! bill_is_target_admin_document_query( $query ) ) {
		return $fields;
	}

	return $fields . ", COALESCE( NULLIF( bill_client_manual_meta.meta_value, '' ), bill_client_post.post_title ) AS bill_client_sort_key";
}
add_filter( 'posts_fields', 'bill_admin_client_search_fields', 10, 2 );

/**
 * 取引先名で並び替える
 *
 * 表示（bill_get_client_name()）と同じ優先順位で、手入力名（bill_client_name_manual）を
 * 優先し、無ければ登録済取引先（bill_client が指す投稿）のタイトルを使う。
 * 実際の式（NULLIF・COALESCE）は bill_admin_client_search_fields() が SELECT 句へ
 * 追加する bill_client_sort_key エイリアスに集約されており、ここではそのエイリアスを
 * 参照するだけにしている（DISTINCT との併用でエラー3065を避けるための対応。
 * bill_admin_client_search_distinct() のDocBlock参照）。
 *
 * 手入力・登録済のどちらも無い書類は、bill_client_sort_key が NULL になる。
 * LEFT JOIN のため結果から除外されることはなく（一覧から消えない）、MySQL の
 * 既定仕様により ASC では先頭、DESC では末尾にまとまる（植草さんからの必須要件）。
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

	return "bill_client_sort_key {$order}, {$wpdb->posts}.post_date {$order}";
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
