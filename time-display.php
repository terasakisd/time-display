<?php
/**
 * Plugin Name: 日時表示（Time Display）
 * Description: 「最短◯月◯日(✕)△:△△」を現在時刻から自動計算して表示します。管理画面の「日時表示」で表示パーツを複数作成でき、[time_display id="123"] で好きな場所に配置できます。閲覧者のブラウザ側で再計算されるため、ページキャッシュの影響を受けません。
 * Version: 2.10.0
 * Author: -
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FTD_VERSION', '2.10.0' );

/**
 * デフォルト設定（タイムゾーンは日本時間固定、接頭辞は「最短」固定）
 */
function ftd_default_config() {
	return array(
		'timezone'      => 'Asia/Tokyo',
		'prefix'        => '最短',
		'before'        => '今すぐ申し込むと',
		'after'         => 'までに借り入れできる可能性が高いです',
		'note'          => '',
		'display_mode'  => 'datetime',
		'lead_minutes'  => 60,
		'round_minutes' => 30,
		'show_seconds'  => false,
		'rules'         => array(
			array( 'from' => '00:00', 'to' => '08:59', 'type' => 'fixed', 'day_offset' => 0, 'time' => '10:00' ),
			array( 'from' => '09:00', 'to' => '19:59', 'type' => 'relative' ),
			array( 'from' => '20:00', 'to' => '23:59', 'type' => 'fixed', 'day_offset' => 1, 'time' => '10:00' ),
		),
	);
}

/**
 * 共通設定の読み込み。優先順:
 *  1. テーマ（子テーマ）内の time-display.json
 *  2. プラグインフォルダ内の config.json
 *  3. デフォルト設定
 * 最後に ftd_config フィルターで上書き可能。
 * （投稿ごとの設定は ftd_instance_config() でこの上にさらに重なる）
 */
function ftd_get_config() {
	static $config = null;
	if ( null !== $config ) {
		return $config;
	}

	$config = ftd_default_config();
	$paths  = array(
		get_stylesheet_directory() . '/time-display.json',
		plugin_dir_path( __FILE__ ) . 'config.json',
	);
	foreach ( $paths as $path ) {
		if ( is_readable( $path ) ) {
			$json = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $json ) ) {
				$config = array_merge( $config, $json );
				break;
			}
		}
	}

	$config = apply_filters( 'ftd_config', $config );
	return $config;
}

/**
 * 投稿（日時表示パーツ）の設定を共通設定に重ねて返す
 */
function ftd_instance_config( $post_id ) {
	$config = ftd_get_config();
	$meta   = get_post_meta( (int) $post_id, '_ftd_settings', true );
	if ( is_array( $meta ) ) {
		$config = array_merge( $config, $meta );
	}
	return $config;
}

/**
 * "HH:MM" / "HH:MM:SS" → 0:00からの経過秒
 */
function ftd_hms_to_seconds( $hms ) {
	$p = array_pad( explode( ':', (string) $hms ), 3, 0 );
	return (int) $p[0] * 3600 + (int) $p[1] * 60 + (int) $p[2];
}

/**
 * 加算する時間（秒）。lead_seconds があれば優先。
 */
function ftd_lead_seconds( array $config ) {
	if ( isset( $config['lead_seconds'] ) && '' !== $config['lead_seconds'] ) {
		return (int) $config['lead_seconds'];
	}
	return (int) $config['lead_minutes'] * 60;
}

/**
 * 切り上げ単位（秒）。round_seconds があれば優先。
 */
function ftd_round_seconds( array $config ) {
	if ( isset( $config['round_seconds'] ) && '' !== $config['round_seconds'] ) {
		return max( 1, (int) $config['round_seconds'] );
	}
	return max( 1, (int) $config['round_minutes'] * 60 );
}

/**
 * 設定のルールに従って表示すべき日時を計算する
 */
function ftd_calc( DateTimeImmutable $now, array $config ) {
	$now_sec = (int) $now->format( 'G' ) * 3600 + (int) $now->format( 'i' ) * 60 + (int) $now->format( 's' );

	foreach ( $config['rules'] as $rule ) {
		$to = ftd_hms_to_seconds( $rule['to'] );
		// "08:59" のような分単位の終了時刻は「08:59:59まで」の意図なので59秒を補う
		if ( substr_count( (string) $rule['to'], ':' ) < 2 ) {
			$to += 59;
		}
		if ( $now_sec < ftd_hms_to_seconds( $rule['from'] ) || $now_sec > $to ) {
			continue;
		}

		if ( 'fixed' === $rule['type'] ) {
			$t = $now;
			if ( ! empty( $rule['day_offset'] ) ) {
				$t = $t->modify( '+' . (int) $rule['day_offset'] . ' day' );
			}
			$p = array_pad( explode( ':', (string) $rule['time'] ), 3, 0 );
			return $t->setTime( (int) $p[0], (int) $p[1], (int) $p[2] );
		}

		// relative: +加算時間 → 切り上げ単位で切り上げ（ちょうど割り切れる時刻ならそのまま）
		$t     = $now->modify( '+' . ftd_lead_seconds( $config ) . ' seconds' );
		$round = ftd_round_seconds( $config );
		$mod   = ( (int) $t->format( 'G' ) * 3600 + (int) $t->format( 'i' ) * 60 + (int) $t->format( 's' ) ) % $round;
		if ( 0 !== $mod ) {
			$t = $t->modify( '+' . ( $round - $mod ) . ' seconds' );
		}
		return $t;
	}

	// どのルールにも当てはまらない場合は現在時刻をそのまま返す（設定不備の保険）
	return $now;
}

/**
 * 日時 → 「最短9月17日(木)10:00」形式の文字列（秒表示オン時は「10:00:30」形式）
 */
function ftd_format( DateTimeImmutable $dt, array $config ) {
	$w    = array( '日', '月', '火', '水', '木', '金', '土' );
	$time = sprintf( '%d:%02d', (int) $dt->format( 'G' ), (int) $dt->format( 'i' ) );
	if ( ! empty( $config['show_seconds'] ) ) {
		$time .= sprintf( ':%02d', (int) $dt->format( 's' ) );
	}
	return sprintf(
		'%s%d月%d日(%s)%s',
		$config['prefix'],
		(int) $dt->format( 'n' ),
		(int) $dt->format( 'j' ),
		$w[ (int) $dt->format( 'w' ) ],
		$time
	);
}

/**
 * 残り秒数 → 「あと1日2時間30分15秒」形式の文字列（0日のときは「日」を省略、接頭辞は prefix 設定）
 */
function ftd_format_timer( $diff_seconds, array $config ) {
	$diff = max( 0, (int) $diff_seconds );
	$d    = (int) floor( $diff / 86400 );
	$h    = (int) floor( ( $diff % 86400 ) / 3600 );
	$m    = (int) floor( ( $diff % 3600 ) / 60 );
	$s    = $diff % 60;
	$text = $config['prefix'];
	if ( $d > 0 ) {
		$text .= $d . '日';
	}
	return $text . $h . '時間' . sprintf( '%02d', $m ) . '分' . sprintf( '%02d', $s ) . '秒';
}

/**
 * 表示テキストを決定（日時形式 / タイマー形式、期限切れ文言の判定込み）
 */
function ftd_display_text( DateTimeImmutable $now, array $config ) {
	$target = ftd_calc( $now, $config );

	if ( isset( $config['display_mode'] ) && 'timer' === $config['display_mode'] ) {
		$diff = $target->getTimestamp() - $now->getTimestamp();
		if ( $diff <= 0 && ! empty( $config['expired_text'] ) ) {
			return $config['expired_text'];
		}
		return ftd_format_timer( $diff, $config );
	}

	if ( ! empty( $config['expired_text'] ) && $target <= $now ) {
		return $config['expired_text'];
	}
	return ftd_format( $target, $config );
}

/**
 * カスタムCSSの無害化（タグ・style閉じの混入を除去）
 */
function ftd_sanitize_css( $css ) {
	$css = wp_strip_all_tags( (string) $css );
	return str_ireplace( array( '</style', '<script' ), '', $css );
}

/**
 * 「見た目」設定（カラーピッカー等）からパーツ専用CSSを生成
 */
function ftd_style_css( array $config, $id ) {
	if ( empty( $config['style'] ) || ! is_array( $config['style'] ) || ! $id ) {
		return '';
	}
	$s   = $config['style'];
	$sel = '.ftd-' . (int) $id;
	$css = '';

	$box = '';
	if ( ! empty( $s['align'] ) ) {
		$box .= 'text-align:' . $s['align'] . ';';
	}
	if ( ! empty( $s['bg_color'] ) ) {
		$box .= 'background:' . $s['bg_color'] . ';';
	}
	if ( ! empty( $s['border_color'] ) ) {
		$box .= 'border-color:' . $s['border_color'] . ';';
	}
	if ( isset( $s['border_width'] ) && '' !== $s['border_width'] ) {
		$box .= 'border-width:' . (int) $s['border_width'] . 'px;';
	}
	if ( isset( $s['border_radius'] ) && '' !== $s['border_radius'] ) {
		$box .= 'border-radius:' . (int) $s['border_radius'] . 'px;';
	}
	if ( $box ) {
		$css .= $sel . '{' . $box . '}';
	}

	$main = '';
	if ( ! empty( $s['time_color'] ) ) {
		$main .= 'color:' . $s['time_color'] . ';';
	}
	if ( ! empty( $s['time_size'] ) ) {
		$main .= 'font-size:' . (int) $s['time_size'] . 'px;';
	}
	if ( $main ) {
		$css .= $sel . ' .ftd-main, span' . $sel . '{' . $main . '}';
	}

	if ( ! empty( $s['text_color'] ) ) {
		$css .= $sel . ' .ftd-before, ' . $sel . ' .ftd-after{color:' . $s['text_color'] . ';}';
	}

	return $css;
}

/**
 * JSに渡す設定（表示計算に必要なキーだけ）
 */
function ftd_js_config( array $config ) {
	$keys = array( 'timezone', 'prefix', 'expired_text', 'display_mode', 'lead_minutes', 'round_minutes', 'rules' );
	return array_intersect_key( $config, array_flip( $keys ) );
}

/**
 * ショートコード [time_display]
 *
 * 属性:
 *  - id      : 「日時表示」投稿のID。指定するとその投稿の設定で表示（管理画面に表示されるショートコードをコピーすればOK）
 *  - display : "box"（デフォルト。前後の文言つきボックス） / "inline"（日時のみ）
 *  - before / after : 文言をその場で上書きしたい場合のみ指定
 */
function ftd_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'      => 0,
			'display' => 'box',
			'before'  => null,
			'after'   => null,
		),
		$atts,
		'time_display'
	);

	$config = $atts['id'] ? ftd_instance_config( $atts['id'] ) : ftd_get_config();

	$before = null !== $atts['before'] ? $atts['before'] : $config['before'];
	$after  = null !== $atts['after'] ? $atts['after'] : $config['after'];

	// PHP側でフォールバック値を出しておき、JSが読み込まれ次第上書きする
	$now      = new DateTimeImmutable( 'now', new DateTimeZone( $config['timezone'] ) );
	$fallback = ftd_display_text( $now, $config );

	wp_enqueue_script( 'time-display' );
	wp_enqueue_style( 'time-display' );

	// パーツ固有クラス（.ftd-123）と、見た目設定＋カスタムCSS。
	// wp_add_inline_style でプラグイン標準CSSの「直後」に出力させることで、
	// 同じ強さのセレクタ（.ftd-123 vs .ftd-box）でも確実に上書きできる。
	$instance_class = $atts['id'] ? ' ftd-' . (int) $atts['id'] : '';
	if ( $atts['id'] ) {
		$css_out = ftd_style_css( $config, (int) $atts['id'] );
		if ( ! empty( $config['custom_css'] ) ) {
			$css_out .= ftd_sanitize_css( $config['custom_css'] );
		}
		if ( '' !== $css_out ) {
			static $printed_css = array();
			if ( empty( $printed_css[ (int) $atts['id'] ] ) ) {
				$printed_css[ (int) $atts['id'] ] = true;
				wp_add_inline_style( 'time-display', $css_out );
			}
		}
	}

	$time_html = sprintf(
		'<span class="ftd-time%s" data-time-display data-ftd-config="%s">%s</span>',
		esc_attr( 'inline' === $atts['display'] ? $instance_class : '' ),
		esc_attr( wp_json_encode( ftd_js_config( $config ) ) ),
		esc_html( $fallback )
	);

	if ( 'inline' === $atts['display'] ) {
		return $time_html;
	}

	$note_html = '';
	if ( ! empty( $config['note'] ) ) {
		$note_html = sprintf( '<p class="ftd-note">%s</p>', esc_html( $config['note'] ) );
	}

	return sprintf(
		'<aside class="ftd-box%s"><p class="ftd-before">%s</p><p class="ftd-main">%s</p><p class="ftd-after">%s</p>%s</aside>',
		esc_attr( $instance_class ),
		esc_html( $before ),
		$time_html,
		esc_html( $after ),
		$note_html
	);
}
add_shortcode( 'time_display', 'ftd_shortcode' );
add_shortcode( 'fastest_time', 'ftd_shortcode' ); // 旧名の互換エイリアス

/**
 * スクリプト・スタイルの登録（enqueue はショートコード使用時のみ）
 */
function ftd_register_assets() {
	wp_register_script( 'time-display', plugins_url( 'time-display.js', __FILE__ ), array(), FTD_VERSION, true );
	wp_register_style( 'time-display', plugins_url( 'time-display.css', __FILE__ ), array(), FTD_VERSION );
}
add_action( 'wp_enqueue_scripts', 'ftd_register_assets' );

/* -------------------------------------------------------------------------
 * 管理画面: 「日時表示」投稿タイプ
 * ---------------------------------------------------------------------- */

function ftd_register_post_type() {
	register_post_type(
		'time_display',
		array(
			'labels'       => array(
				'name'          => '日時表示',
				'singular_name' => '日時表示',
				'add_new'       => '新規追加',
				'add_new_item'  => '日時表示パーツを新規追加',
				'edit_item'     => '日時表示パーツを編集',
				'search_items'  => '日時表示パーツを検索',
				'not_found'     => '日時表示パーツがありません',
			),
			'public'       => false,
			'show_ui'      => true,
			'menu_icon'    => 'dashicons-clock',
			'menu_position'=> 25,
			'supports'     => array( 'title' ),
		)
	);
}
add_action( 'init', 'ftd_register_post_type' );

function ftd_add_meta_boxes() {
	add_meta_box( 'ftd-settings', '表示設定', 'ftd_meta_box', 'time_display', 'normal', 'high' );
}
add_action( 'add_meta_boxes_time_display', 'ftd_add_meta_boxes' );

/**
 * 保存値のサニタイズ
 */
function ftd_sanitize_instance( $input ) {
	$out = array();
	if ( ! is_array( $input ) ) {
		return $out;
	}

	$defaults = ftd_default_config();

	$out['before'] = isset( $input['before'] ) ? sanitize_text_field( $input['before'] ) : $defaults['before'];
	$out['after']  = isset( $input['after'] ) ? sanitize_text_field( $input['after'] ) : $defaults['after'];
	$out['note']   = isset( $input['note'] ) ? sanitize_text_field( $input['note'] ) : '';
	$out['prefix'] = isset( $input['prefix'] ) ? sanitize_text_field( $input['prefix'] ) : $defaults['prefix'];

	$out['display_mode'] = ( isset( $input['display_mode'] ) && 'timer' === $input['display_mode'] ) ? 'timer' : 'datetime';

	if ( isset( $input['expired_text'] ) && '' !== trim( (string) $input['expired_text'] ) ) {
		$out['expired_text'] = sanitize_text_field( $input['expired_text'] );
	}

	$out['lead_minutes']  = isset( $input['lead_minutes'] ) ? absint( $input['lead_minutes'] ) : $defaults['lead_minutes'];
	$out['round_minutes'] = isset( $input['round_minutes'] ) ? max( 1, absint( $input['round_minutes'] ) ) : $defaults['round_minutes'];


	if ( isset( $input['custom_css'] ) && '' !== trim( (string) $input['custom_css'] ) ) {
		$out['custom_css'] = ftd_sanitize_css( $input['custom_css'] );
	}

	// 見た目（ノーコード設定）
	if ( isset( $input['style'] ) && is_array( $input['style'] ) ) {
		$s     = $input['style'];
		$style = array();
		if ( ! empty( $s['align'] ) && in_array( $s['align'], array( 'left', 'center', 'right' ), true ) ) {
			$style['align'] = $s['align'];
		}
		foreach ( array( 'bg_color', 'border_color', 'time_color', 'text_color' ) as $key ) {
			// 「透明にする」チェック（背景・枠線のみ）はカラー選択より優先
			if ( ! empty( $s[ $key . '_transparent' ] ) && in_array( $key, array( 'bg_color', 'border_color' ), true ) ) {
				$style[ $key ] = 'transparent';
				continue;
			}
			if ( ! empty( $s[ $key ] ) ) {
				if ( 'transparent' === trim( (string) $s[ $key ] ) ) { // JSON経由の指定
					$style[ $key ] = 'transparent';
					continue;
				}
				$color = sanitize_hex_color( $s[ $key ] );
				if ( $color ) {
					$style[ $key ] = $color;
				}
			}
		}
		foreach ( array( 'border_width', 'border_radius', 'time_size' ) as $key ) {
			if ( isset( $s[ $key ] ) && '' !== trim( (string) $s[ $key ] ) ) {
				$style[ $key ] = absint( $s[ $key ] );
			}
		}
		if ( ! empty( $style ) ) {
			$out['style'] = $style;
		}
	}

	$time_pattern = '/^\d{1,2}:\d{2}(:\d{2})?$/';
	$rules        = array();
	if ( isset( $input['rules'] ) && is_array( $input['rules'] ) ) {
		foreach ( $input['rules'] as $row ) {
			$from = isset( $row['from'] ) ? trim( (string) $row['from'] ) : '';
			$to   = isset( $row['to'] ) ? trim( (string) $row['to'] ) : '';
			if ( ! preg_match( $time_pattern, $from ) || ! preg_match( $time_pattern, $to ) ) {
				continue; // 空行や不正な行はスキップ
			}
			$type = ( isset( $row['type'] ) && 'fixed' === $row['type'] ) ? 'fixed' : 'relative';
			$rule = array(
				'from' => $from,
				'to'   => $to,
				'type' => $type,
			);
			if ( 'fixed' === $type ) {
				$time = isset( $row['time'] ) ? trim( (string) $row['time'] ) : '';
				if ( ! preg_match( $time_pattern, $time ) ) {
					continue;
				}
				$off = isset( $row['day_offset'] ) ? $row['day_offset'] : 0;
				if ( 'custom' === $off ) {
					$off = isset( $row['day_offset_custom'] ) ? $row['day_offset_custom'] : 0;
				}
				$rule['day_offset'] = absint( $off );
				$rule['time']       = $time;
			}
			$rules[] = $rule;
		}
	}
	if ( ! empty( $rules ) ) {
		$out['rules'] = $rules;
	}

	return $out;
}

function ftd_save_post( $post_id ) {
	if ( ! isset( $_POST['ftd_nonce'] ) || ! wp_verify_nonce( $_POST['ftd_nonce'], 'ftd_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$input = isset( $_POST['ftd'] ) ? wp_unslash( $_POST['ftd'] ) : array();

	// JSONタブで「反映する」にチェックがある場合はJSONを優先
	if ( ! empty( $_POST['ftd_json_apply'] ) && isset( $_POST['ftd_json'] ) ) {
		$decoded = json_decode( trim( (string) wp_unslash( $_POST['ftd_json'] ) ), true );
		if ( is_array( $decoded ) ) {
			$input = $decoded;
		} else {
			set_transient( 'ftd_json_error_' . get_current_user_id(), 1, 60 );
		}
	}

	update_post_meta( $post_id, '_ftd_settings', ftd_sanitize_instance( $input ) );
}
add_action( 'save_post_time_display', 'ftd_save_post' );

/**
 * JSONが壊れていて反映できなかったときの通知
 */
function ftd_json_error_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'time_display' !== $screen->post_type ) {
		return;
	}
	if ( get_transient( 'ftd_json_error_' . get_current_user_id() ) ) {
		delete_transient( 'ftd_json_error_' . get_current_user_id() );
		echo '<div class="notice notice-error"><p>JSONの形式が正しくないため反映できませんでした。フォームの入力内容で保存しています。</p></div>';
	}
}
add_action( 'admin_notices', 'ftd_json_error_notice' );

/**
 * パーツ設定をJSON表示用に整形（設定可能なキーだけ抜き出し）
 */
function ftd_export_instance( array $config ) {
	$keys = array( 'prefix', 'before', 'after', 'note', 'expired_text', 'display_mode', 'lead_minutes', 'round_minutes', 'rules', 'style', 'custom_css' );
	return array_intersect_key( $config, array_flip( $keys ) );
}

/**
 * 一覧にショートコード列を追加（コピペ用）
 */
function ftd_admin_columns( $columns ) {
	$columns['ftd_shortcode'] = 'ショートコード';
	return $columns;
}
add_filter( 'manage_time_display_posts_columns', 'ftd_admin_columns' );

function ftd_admin_column_content( $column, $post_id ) {
	if ( 'ftd_shortcode' === $column ) {
		echo '<code>[time_display id="' . (int) $post_id . '"]</code>';
	}
}
add_action( 'manage_time_display_posts_custom_column', 'ftd_admin_column_content', 10, 2 );

/**
 * 編集画面でWP標準カラーピッカーを読み込む
 */
function ftd_admin_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && 'time_display' === $screen->post_type ) {
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		// プレビュー用にフロントの標準CSSも読み込む
		wp_enqueue_style( 'time-display-admin-preview', plugins_url( 'time-display.css', __FILE__ ), array(), FTD_VERSION );
	}
}
add_action( 'admin_enqueue_scripts', 'ftd_admin_assets' );

/**
 * 編集画面のメタボックス（「設定」「見た目」タブ）
 */
function ftd_meta_box( $post ) {
	$config = ftd_instance_config( $post->ID );

	$style = isset( $config['style'] ) && is_array( $config['style'] ) ? $config['style'] : array();
	$sv            = function ( $key ) use ( $style ) {
		return isset( $style[ $key ] ) ? $style[ $key ] : '';
	};

	$now     = new DateTimeImmutable( 'now', new DateTimeZone( $config['timezone'] ) );
	$preview = ftd_display_text( $now, $config );

	wp_nonce_field( 'ftd_save', 'ftd_nonce' );
	?>
	<?php if ( 'auto-draft' !== $post->post_status ) : ?>
	<p style="background:#f0f6fc;border:1px solid #c5d9ed;padding:10px 14px;border-radius:4px;">
		このパーツを表示したい場所に貼り付け → <code style="font-size:1.1em;">[time_display id="<?php echo (int) $post->ID; ?>"]</code>
	</p>
	<?php endif; ?>

	<div style="display:flex;gap:20px;align-items:stretch;">
	<div style="flex:7;min-width:0;">

	<h2 class="nav-tab-wrapper" style="margin-bottom:1em;">
		<a href="#" class="nav-tab nav-tab-active" data-ftd-tab="settings">設定</a>
		<a href="#" class="nav-tab" data-ftd-tab="style">見た目</a>
		<a href="#" class="nav-tab" data-ftd-tab="json">JSON</a>
	</h2>

	<div class="ftd-tab-panel" id="ftd-tab-settings">

		<h3>表示形式</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">形式</th>
				<td>
					<label style="margin-right:16px;">
						<input type="radio" name="ftd[display_mode]" value="datetime" <?php checked( ! isset( $config['display_mode'] ) || 'timer' !== $config['display_mode'] ); ?>>
						日時（例: 最短9月18日(金)15:30）
					</label>
					<label>
						<input type="radio" name="ftd[display_mode]" value="timer" <?php checked( isset( $config['display_mode'] ) && 'timer' === $config['display_mode'] ); ?>>
						タイマー（例: あと1時間23分45秒）
					</label>
					<p class="description">タイマーは表示時刻までの残り時間を毎秒カウントダウンします。頭の文字は「日時の直前につく文字」欄で変更できます（例:「あと」「残り」）。</p>
				</td>
			</tr>
		</table>

		<h3>文言</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ftd-before">日時の上に出す文言</label></th>
				<td><input type="text" id="ftd-before" name="ftd[before]" value="<?php echo esc_attr( $config['before'] ); ?>" class="large-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ftd-after">日時の下に出す文言</label></th>
				<td><input type="text" id="ftd-after" name="ftd[after]" value="<?php echo esc_attr( $config['after'] ); ?>" class="large-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ftd-note">注釈</label></th>
				<td>
					<input type="text" id="ftd-note" name="ftd[note]" value="<?php echo esc_attr( isset( $config['note'] ) ? $config['note'] : '' ); ?>" class="large-text" placeholder="例: ※申込内容や審査状況によって前後する場合があります">
					<p class="description">ボックスの一番下に小さいグレーの文字で表示されます。空欄なら非表示。</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ftd-prefix">日時の直前につく文字</label></th>
				<td>
					<input type="text" id="ftd-prefix" name="ftd[prefix]" value="<?php echo esc_attr( $config['prefix'] ); ?>" class="regular-text">
					<p class="description">「<strong>最短</strong>9月17日(木)10:00」の「最短」の部分。不要なら空欄でOK。</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ftd-expired">表示時刻を過ぎた場合の文言</label></th>
				<td>
					<input type="text" id="ftd-expired" name="ftd[expired_text]" value="<?php echo esc_attr( isset( $config['expired_text'] ) ? $config['expired_text'] : '' ); ?>" class="large-text" placeholder="例: 本日の受付は終了しました">
					<p class="description">「決まった時刻を表示」の時刻をすでに過ぎているなど、計算結果が現在より前になった場合に、日時の代わりに表示する文言。空欄なら日時をそのまま表示します。</p>
				</td>
			</tr>
		</table>

		<h3>表示する時刻の計算</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ftd-lead">今から何分後を表示するか</label></th>
				<td>
					<input type="number" id="ftd-lead" name="ftd[lead_minutes]" value="<?php echo esc_attr( $config['lead_minutes'] ); ?>" min="0" class="small-text"> 分後
					<p class="description">例: 60 と入れると、15:00に見た人には約1時間後（16:00ごろ）の時刻が表示されます。</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ftd-round">時刻のキリのよさ</label></th>
				<td>
					<input type="number" id="ftd-round" name="ftd[round_minutes]" value="<?php echo esc_attr( $config['round_minutes'] ); ?>" min="1" class="small-text"> 分単位に切り上げ
					<p class="description">例: 30 と入れると 16:02 → 16:30、16:31 → 17:00 のようにキリのよい時刻に切り上げます（16:00ちょうどはそのまま）。</p>
				</td>
			</tr>
		</table>

		<h3>時間帯ごとの表示ルール</h3>
		<p class="description">
			<strong>「計算して表示」</strong>= 上で設定した「◯分後・切り上げ」で計算した時刻 ／
			<strong>「決まった時刻を表示」</strong>= 右の「いつ・何時」をそのまま表示（例: 深夜に見た人には「翌日10:00」）。
		</p>
		<table class="widefat striped" id="ftd-rules" style="max-width:820px;">
			<thead>
				<tr>
					<th>開始</th>
					<th>終了</th>
					<th>表示方法</th>
					<th colspan="2">決まった時刻の場合: いつ・何時</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $config['rules'] as $i => $rule ) : ?>
				<tr>
					<td><input type="text" name="ftd[rules][<?php echo (int) $i; ?>][from]" value="<?php echo esc_attr( $rule['from'] ); ?>" size="8" placeholder="09:00"></td>
					<td><input type="text" name="ftd[rules][<?php echo (int) $i; ?>][to]" value="<?php echo esc_attr( $rule['to'] ); ?>" size="8" placeholder="19:59"></td>
					<td>
						<select name="ftd[rules][<?php echo (int) $i; ?>][type]">
							<option value="relative" <?php selected( $rule['type'], 'relative' ); ?>>計算して表示</option>
							<option value="fixed" <?php selected( $rule['type'], 'fixed' ); ?>>決まった時刻を表示</option>
						</select>
					</td>
					<?php $off = (int) ( isset( $rule['day_offset'] ) ? $rule['day_offset'] : 0 ); ?>
					<td style="white-space:nowrap;">
						<select name="ftd[rules][<?php echo (int) $i; ?>][day_offset]" class="ftd-day-select">
							<option value="0" <?php selected( $off, 0 ); ?>>当日</option>
							<option value="1" <?php selected( $off, 1 ); ?>>翌日</option>
							<option value="2" <?php selected( $off, 2 ); ?>>翌々日</option>
							<option value="custom" <?php selected( $off > 2 ); ?>>カスタム…</option>
						</select>
						<span class="ftd-day-custom" style="<?php echo $off > 2 ? '' : 'display:none;'; ?>">
							<input type="number" name="ftd[rules][<?php echo (int) $i; ?>][day_offset_custom]" value="<?php echo $off > 2 ? (int) $off : ''; ?>" min="0" class="small-text"> 日後
						</span>
					</td>
					<td><input type="text" name="ftd[rules][<?php echo (int) $i; ?>][time]" value="<?php echo esc_attr( isset( $rule['time'] ) ? $rule['time'] : '' ); ?>" size="8" placeholder="10:00"></td>
					<td><button type="button" class="button ftd-remove-rule">削除</button></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="ftd-add-rule">＋ 時間帯を追加</button></p>

	</div><!-- /ftd-tab-settings -->

	<div class="ftd-tab-panel" id="ftd-tab-style" style="display:none;">

		<h3>かんたん設定</h3>
		<p class="description">CSSを書かなくても、ここで基本的な見た目を変えられます。空欄・未選択の項目はプラグイン標準の見た目のままです。</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">文字の位置</th>
				<td>
					<span class="ftd-align-group">
						<label class="button"><input type="radio" name="ftd[style][align]" value="left" <?php checked( $sv( 'align' ), 'left' ); ?> style="margin:0 4px 0 0;"><span class="dashicons dashicons-editor-alignleft" style="vertical-align:text-bottom;"></span> 左寄せ</label>
						<label class="button"><input type="radio" name="ftd[style][align]" value="center" <?php checked( $sv( 'align' ), 'center' ); ?> style="margin:0 4px 0 0;"><span class="dashicons dashicons-editor-aligncenter" style="vertical-align:text-bottom;"></span> 中央</label>
						<label class="button"><input type="radio" name="ftd[style][align]" value="right" <?php checked( $sv( 'align' ), 'right' ); ?> style="margin:0 4px 0 0;"><span class="dashicons dashicons-editor-alignright" style="vertical-align:text-bottom;"></span> 右寄せ</label>
					</span>
					<p class="description">未選択なら標準（中央）。</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label>日時の色</label></th>
				<td><input type="text" class="ftd-color" name="ftd[style][time_color]" value="<?php echo esc_attr( $sv( 'time_color' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ftd-time-size">日時の文字サイズ</label></th>
				<td><input type="number" id="ftd-time-size" name="ftd[style][time_size]" value="<?php echo esc_attr( $sv( 'time_size' ) ); ?>" min="10" class="small-text" placeholder="標準"> px</td>
			</tr>
			<tr>
				<th scope="row"><label>文言の色</label></th>
				<td><input type="text" class="ftd-color" name="ftd[style][text_color]" value="<?php echo esc_attr( $sv( 'text_color' ) ); ?>"><p class="description">上下の文言の文字色。</p></td>
			</tr>
			<tr>
				<th scope="row"><label>背景色</label></th>
				<td>
					<input type="text" class="ftd-color" name="ftd[style][bg_color]" value="<?php echo esc_attr( 'transparent' === $sv( 'bg_color' ) ? '' : $sv( 'bg_color' ) ); ?>">
					<label style="margin-left:8px;"><input type="checkbox" name="ftd[style][bg_color_transparent]" value="1" <?php checked( 'transparent' === $sv( 'bg_color' ) ); ?>> 透明にする（チェック時はカラー選択より優先）</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label>枠線の色</label></th>
				<td>
					<input type="text" class="ftd-color" name="ftd[style][border_color]" value="<?php echo esc_attr( 'transparent' === $sv( 'border_color' ) ? '' : $sv( 'border_color' ) ); ?>">
					<label style="margin-left:8px;"><input type="checkbox" name="ftd[style][border_color_transparent]" value="1" <?php checked( 'transparent' === $sv( 'border_color' ) ); ?>> 透明にする（チェック時はカラー選択より優先）</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ftd-border-width">枠線の太さ</label></th>
				<td><input type="number" id="ftd-border-width" name="ftd[style][border_width]" value="<?php echo esc_attr( $sv( 'border_width' ) ); ?>" min="0" class="small-text" placeholder="標準"> px（0で枠線なし）</td>
			</tr>
			<tr>
				<th scope="row"><label for="ftd-border-radius">角の丸み</label></th>
				<td><input type="number" id="ftd-border-radius" name="ftd[style][border_radius]" value="<?php echo esc_attr( $sv( 'border_radius' ) ); ?>" min="0" class="small-text" placeholder="標準"> px</td>
			</tr>
		</table>

		<h3>こだわり設定（CSS）</h3>
		<p class="description">
			かんたん設定で足りない場合はCSSを直接書けます。ここに書いたCSSがこのパーツと一緒に出力されます。使えるクラス:
			<code>.ftd-box</code>（枠全体）／ <code>.ftd-before</code>（上の文言）／ <code>.ftd-main</code>（日時の行）／
			<code>.ftd-time</code>（日時の文字）／ <code>.ftd-after</code>（下の文言）／ <code>.ftd-note</code>（注釈）<br>
			<strong>このパーツだけ</strong>に効かせたい場合は <code>.ftd-<?php echo (int) $post->ID; ?></code> を頭につけてください
			（例: <code>.ftd-<?php echo (int) $post->ID; ?> .ftd-main { color: red; }</code>）。かんたん設定より優先されます。
		</p>
		<textarea name="ftd[custom_css]" rows="8" class="large-text code" placeholder=".ftd-<?php echo (int) $post->ID; ?> { border-style: dashed; }
.ftd-<?php echo (int) $post->ID; ?> .ftd-main { letter-spacing: 0.05em; }"><?php echo esc_textarea( isset( $config['custom_css'] ) ? $config['custom_css'] : '' ); ?></textarea>

	</div><!-- /ftd-tab-style -->

	<div class="ftd-tab-panel" id="ftd-tab-json" style="display:none;">
		<h3>JSONで設定</h3>
		<p class="description">
			このパーツの現在の設定がJSONで表示されています。バックアップや別パーツへのコピーに使えます。<br>
			編集・貼り付けしたJSONを反映するには、下のチェックを入れてから「更新」してください（チェックがない場合、この欄は無視されます）。
		</p>
		<textarea name="ftd_json" rows="18" class="large-text code"><?php echo esc_textarea( wp_json_encode( ftd_export_instance( $config ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
		<p>
			<label>
				<input type="checkbox" name="ftd_json_apply" value="1">
				<strong>保存時にこのJSONを設定へ反映する</strong>（「設定」「見た目」タブのフォーム入力より優先されます）
			</label>
		</p>
		<p class="description">
			サイト全体の共通デフォルトは、テーマフォルダの <code>time-display.json</code> またはプラグインフォルダの <code>config.json</code> でも設定できます（書式は同じ）。
		</p>
	</div><!-- /ftd-tab-json -->

	</div><!-- /左カラム -->

	<div style="flex:3;min-width:0;">
		<style id="ftd-preview-style"></style>
		<div style="position:sticky;top:50px;border:1px solid #dcdcde;border-radius:4px;height:calc(100vh - 140px);min-height:320px;display:flex;flex-direction:column;justify-content:center;padding:16px;box-sizing:border-box;">
			<aside class="ftd-box ftd-<?php echo (int) $post->ID; ?>" id="ftd-preview-box" style="width:100%;box-sizing:border-box;">
				<p class="ftd-before" id="ftd-preview-before"><?php echo esc_html( $config['before'] ); ?></p>
				<p class="ftd-main"><span class="ftd-time" id="ftd-preview-time"><?php echo esc_html( $preview ); ?></span></p>
				<p class="ftd-after" id="ftd-preview-after"><?php echo esc_html( $config['after'] ); ?></p>
				<p class="ftd-note" id="ftd-preview-note"><?php echo esc_html( isset( $config['note'] ) ? $config['note'] : '' ); ?></p>
			</aside>
		</div>
	</div>

	</div><!-- /flex -->

	<script>
	(function () {
		// タブ切り替え
		document.querySelectorAll('.nav-tab[data-ftd-tab]').forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				document.querySelectorAll('.nav-tab[data-ftd-tab]').forEach(function (t) {
					t.classList.toggle('nav-tab-active', t === tab);
				});
				document.querySelectorAll('.ftd-tab-panel').forEach(function (panel) {
					panel.style.display = panel.id === 'ftd-tab-' + tab.getAttribute('data-ftd-tab') ? '' : 'none';
				});
			});
		});

		// 文字位置ボタンの見た目（選択中を強調）
		function refreshAlignButtons() {
			document.querySelectorAll('.ftd-align-group label').forEach(function (label) {
				var input = label.querySelector('input');
				label.classList.toggle('button-primary', input.checked);
			});
		}
		document.querySelectorAll('.ftd-align-group input').forEach(function (input) {
			input.addEventListener('change', refreshAlignButtons);
		});
		refreshAlignButtons();

		// カラーピッカー
		// このスクリプトはページ途中で実行されるが、wp-color-picker本体はページ下部で
		// 読み込まれるため、読み込み完了（DOMContentLoaded）を待ってから初期化する
		function initColorPickers() {
			if (window.jQuery && jQuery.fn.wpColorPicker) {
				jQuery('.ftd-color').wpColorPicker();
			}
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initColorPickers);
		} else {
			initColorPickers();
		}

		// ルール行の追加・削除
		var table = document.getElementById('ftd-rules');
		var addBtn = document.getElementById('ftd-add-rule');

		function nextIndex() {
			var max = -1;
			table.querySelectorAll('tbody tr input[name*="[from]"]').forEach(function (input) {
				var m = input.name.match(/\[rules\]\[(\d+)\]/);
				if (m) { max = Math.max(max, parseInt(m[1], 10)); }
			});
			return max + 1;
		}

		addBtn.addEventListener('click', function () {
			var i = nextIndex();
			var tr = document.createElement('tr');
			tr.innerHTML =
				'<td><input type="text" name="ftd[rules][' + i + '][from]" size="8" placeholder="09:00"></td>' +
				'<td><input type="text" name="ftd[rules][' + i + '][to]" size="8" placeholder="19:59"></td>' +
				'<td><select name="ftd[rules][' + i + '][type]">' +
					'<option value="relative">計算して表示</option>' +
					'<option value="fixed">決まった時刻を表示</option>' +
				'</select></td>' +
				'<td style="white-space:nowrap;"><select name="ftd[rules][' + i + '][day_offset]" class="ftd-day-select">' +
					'<option value="0">当日</option><option value="1">翌日</option><option value="2">翌々日</option><option value="custom">カスタム…</option>' +
				'</select> <span class="ftd-day-custom" style="display:none;"><input type="number" name="ftd[rules][' + i + '][day_offset_custom]" min="0" class="small-text"> 日後</span></td>' +
				'<td><input type="text" name="ftd[rules][' + i + '][time]" size="8" placeholder="10:00"></td>' +
				'<td><button type="button" class="button ftd-remove-rule">削除</button></td>';
			table.querySelector('tbody').appendChild(tr);
			refreshRuleRow(tr);
		});

		table.addEventListener('click', function (e) {
			if (e.target.classList.contains('ftd-remove-rule')) {
				e.target.closest('tr').remove();
			}
		});

		// 「いつ・何時」の欄は「決まった時刻を表示」のときだけ見せる
		// （さらに日数入力は「カスタム…」選択時のみ）
		function refreshRuleRow(tr) {
			var typeSel = tr.querySelector('select[name*="[type]"]');
			var daySel = tr.querySelector('.ftd-day-select');
			var customSpan = tr.querySelector('.ftd-day-custom');
			var timeInput = tr.querySelector('input[name*="[time]"]');
			if (!typeSel) { return; }
			var fixed = typeSel.value === 'fixed';
			if (daySel) { daySel.style.display = fixed ? '' : 'none'; }
			if (timeInput) { timeInput.style.display = fixed ? '' : 'none'; }
			if (customSpan) { customSpan.style.display = (fixed && daySel && daySel.value === 'custom') ? '' : 'none'; }
		}
		table.addEventListener('change', function (e) {
			if (e.target.matches('select[name*="[type]"]') || e.target.classList.contains('ftd-day-select')) {
				refreshRuleRow(e.target.closest('tr'));
			}
		});
		table.querySelectorAll('tbody tr').forEach(refreshRuleRow);

		// ---- ライブプレビュー ----
		var PREVIEW_SEL = '.ftd-<?php echo (int) $post->ID; ?>';
		var WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];
		var TIME_RE = /^\d{1,2}:\d{2}(:\d{2})?$/;

		function val(selector) {
			var el = document.querySelector(selector);
			return el ? el.value : '';
		}
		function checkedOf(selector) {
			var el = document.querySelector(selector);
			return !!(el && el.checked);
		}
		function hmsToSeconds(hms) {
			var p = String(hms).split(':');
			return (parseInt(p[0], 10) || 0) * 3600 + (parseInt(p[1], 10) || 0) * 60 + (parseInt(p[2], 10) || 0);
		}
		function nowJst() {
			var parts = new Intl.DateTimeFormat('en-US', {
				timeZone: 'Asia/Tokyo', year: 'numeric', month: 'numeric', day: 'numeric',
				hour: 'numeric', minute: 'numeric', second: 'numeric', hour12: false
			}).formatToParts(new Date());
			var v = {};
			parts.forEach(function (p) { v[p.type] = parseInt(p.value, 10); });
			if (v.hour === 24) { v.hour = 0; }
			return new Date(Date.UTC(v.year, v.month - 1, v.day, v.hour, v.minute, v.second));
		}
		function collectConfig() {
			var cfg = {
				prefix: val('[name="ftd[prefix]"]'),
				before: val('[name="ftd[before]"]'),
				after: val('[name="ftd[after]"]'),
				note: val('[name="ftd[note]"]'),
				expired_text: val('[name="ftd[expired_text]"]'),
				display_mode: (document.querySelector('input[name="ftd[display_mode]"]:checked') || {}).value || 'datetime',
				lead_minutes: parseInt(val('[name="ftd[lead_minutes]"]'), 10) || 0,
				round_minutes: parseInt(val('[name="ftd[round_minutes]"]'), 10) || 1,
				lead_seconds: val('[name="ftd[lead_seconds]"]'),
				round_seconds: val('[name="ftd[round_seconds]"]'),
				show_seconds: checkedOf('[name="ftd[show_seconds]"]'),
				rules: []
			};
			table.querySelectorAll('tbody tr').forEach(function (tr) {
				var from = ((tr.querySelector('input[name*="[from]"]') || {}).value || '').trim();
				var to = ((tr.querySelector('input[name*="[to]"]') || {}).value || '').trim();
				if (!TIME_RE.test(from) || !TIME_RE.test(to)) { return; }
				var type = (tr.querySelector('select[name*="[type]"]') || {}).value || 'relative';
				var rule = { from: from, to: to, type: type };
				if (type === 'fixed') {
					var sel = tr.querySelector('.ftd-day-select');
					var off = sel ? sel.value : '0';
					if (off === 'custom') {
						off = (tr.querySelector('input[name*="[day_offset_custom]"]') || {}).value || '0';
					}
					rule.day_offset = parseInt(off, 10) || 0;
					var time = ((tr.querySelector('input[name*="[time]"]') || {}).value || '').trim();
					if (!TIME_RE.test(time)) { return; }
					rule.time = time;
				}
				cfg.rules.push(rule);
			});
			return cfg;
		}
		function pCalc(now, cfg) {
			var nowSec = now.getUTCHours() * 3600 + now.getUTCMinutes() * 60 + now.getUTCSeconds();
			for (var i = 0; i < cfg.rules.length; i++) {
				var rule = cfg.rules[i];
				var to = hmsToSeconds(rule.to);
				if (String(rule.to).split(':').length < 3) { to += 59; }
				if (nowSec < hmsToSeconds(rule.from) || nowSec > to) { continue; }
				var t;
				if (rule.type === 'fixed') {
					t = new Date(now.getTime() + (rule.day_offset || 0) * 86400000);
					var p = String(rule.time).split(':');
					t.setUTCHours(parseInt(p[0], 10) || 0, parseInt(p[1], 10) || 0, parseInt(p[2], 10) || 0, 0);
					return t;
				}
				var lead = cfg.lead_seconds !== '' ? parseInt(cfg.lead_seconds, 10) || 0 : cfg.lead_minutes * 60;
				var round = Math.max(1, cfg.round_seconds !== '' ? parseInt(cfg.round_seconds, 10) || 1 : cfg.round_minutes * 60);
				t = new Date(now.getTime() + lead * 1000);
				var mod = (t.getUTCHours() * 3600 + t.getUTCMinutes() * 60 + t.getUTCSeconds()) % round;
				if (mod !== 0) { t = new Date(t.getTime() + (round - mod) * 1000); }
				t.setUTCMilliseconds(0);
				return t;
			}
			return now;
		}
		function pad2(n) { return n < 10 ? '0' + n : String(n); }
		function pFormat(t, cfg) {
			var time = t.getUTCHours() + ':' + pad2(t.getUTCMinutes());
			if (cfg.show_seconds) { time += ':' + pad2(t.getUTCSeconds()); }
			return cfg.prefix + (t.getUTCMonth() + 1) + '月' + t.getUTCDate() + '日(' + WEEKDAYS[t.getUTCDay()] + ')' + time;
		}
		function pFormatTimer(diff, cfg) {
			diff = Math.max(0, diff);
			var d = Math.floor(diff / 86400), h = Math.floor((diff % 86400) / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
			return cfg.prefix + (d > 0 ? d + '日' : '') + h + '時間' + pad2(m) + '分' + pad2(s) + '秒';
		}
		function buildStyleCss() {
			var css = '', box = '';
			var alignEl = document.querySelector('input[name="ftd[style][align]"]:checked');
			if (alignEl) { box += 'text-align:' + alignEl.value + ';'; }
			var bg = checkedOf('[name="ftd[style][bg_color_transparent]"]') ? 'transparent' : val('[name="ftd[style][bg_color]"]');
			if (bg) { box += 'background:' + bg + ';'; }
			var bc = checkedOf('[name="ftd[style][border_color_transparent]"]') ? 'transparent' : val('[name="ftd[style][border_color]"]');
			if (bc) { box += 'border-color:' + bc + ';'; }
			var bw = val('[name="ftd[style][border_width]"]');
			if (bw !== '') { box += 'border-width:' + (parseInt(bw, 10) || 0) + 'px;'; }
			var br = val('[name="ftd[style][border_radius]"]');
			if (br !== '') { box += 'border-radius:' + (parseInt(br, 10) || 0) + 'px;'; }
			if (box) { css += PREVIEW_SEL + '{' + box + '}'; }
			var main = '';
			var tc = val('[name="ftd[style][time_color]"]');
			if (tc) { main += 'color:' + tc + ';'; }
			var ts = val('[name="ftd[style][time_size]"]');
			if (ts !== '') { main += 'font-size:' + (parseInt(ts, 10) || 0) + 'px;'; }
			if (main) { css += PREVIEW_SEL + ' .ftd-main, span' + PREVIEW_SEL + '{' + main + '}'; }
			var xc = val('[name="ftd[style][text_color]"]');
			if (xc) { css += PREVIEW_SEL + ' .ftd-before, ' + PREVIEW_SEL + ' .ftd-after{color:' + xc + ';}'; }
			css += val('[name="ftd[custom_css]"]');
			return css;
		}
		function updatePreview() {
			var boxEl = document.getElementById('ftd-preview-box');
			if (!boxEl) { return; }
			var cfg = collectConfig();
			var now = nowJst();
			var target = pCalc(now, cfg);
			var text;
			if (cfg.display_mode === 'timer') {
				var diff = Math.floor((target.getTime() - now.getTime()) / 1000);
				text = (diff <= 0 && cfg.expired_text) ? cfg.expired_text : pFormatTimer(diff, cfg);
			} else {
				text = (cfg.expired_text && target.getTime() <= now.getTime()) ? cfg.expired_text : pFormat(target, cfg);
			}
			document.getElementById('ftd-preview-time').textContent = text;
			var be = document.getElementById('ftd-preview-before');
			be.textContent = cfg.before;
			be.style.display = cfg.before ? '' : 'none';
			var af = document.getElementById('ftd-preview-after');
			af.textContent = cfg.after;
			af.style.display = cfg.after ? '' : 'none';
			var nt = document.getElementById('ftd-preview-note');
			nt.textContent = cfg.note;
			nt.style.display = cfg.note ? '' : 'none';
			document.getElementById('ftd-preview-style').textContent = buildStyleCss();
		}
		document.addEventListener('input', updatePreview, true);
		document.addEventListener('change', updatePreview, true);
		setInterval(updatePreview, 1000); // 時刻の更新と、カラーピッカー等イベントを拾えない変更の反映
		updatePreview();
	})();
	</script>
	<?php
}
