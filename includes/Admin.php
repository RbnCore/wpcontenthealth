<?php
/**
 * Yönetim ekranı: Araçlar → ContentHealth. CSS/JS yalnız bu sayfada yüklenir.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const SLUG = 'contenthealth';
	private const CAP = 'manage_options';

	private static string $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_contenthealth_save', array( self::class, 'save' ) );
		add_action( 'admin_post_contenthealth_export', array( self::class, 'export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CONTENTHEALTH_FILE ), array( self::class, 'links' ) );
	}

	/** @param array<int,string> $links */
	public static function links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Aç', 'contenthealth' ) . '</a>' );
		return $links;
	}

	public static function menu(): void {
		self::$hook = (string) add_management_page(
			__( 'ContentHealth — İçerik Sağlığı', 'contenthealth' ),
			__( 'ContentHealth', 'contenthealth' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
		add_action( 'load-' . self::$hook, array( self::class, 'bulk' ) );
	}

	public static function assets( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'contenthealth-admin', CONTENTHEALTH_URL . 'assets/admin.css', array(), CONTENTHEALTH_VERSION );
		wp_enqueue_script( 'contenthealth-admin', CONTENTHEALTH_URL . 'assets/admin.js', array(), CONTENTHEALTH_VERSION, true );
		wp_localize_script(
			'contenthealth-admin',
			'ContentHealthCfg',
			array(
				'root'  => esc_url_raw( rest_url( Rest::NS . '/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'scanning'   => __( 'Taranıyor…', 'contenthealth' ),
					'done'       => __( 'Tarama bitti', 'contenthealth' ),
					'start'      => __( 'Şimdi tara', 'contenthealth' ),
					'cancel'     => __( 'İptal', 'contenthealth' ),
					'dry'        => __( 'Kuru koşu', 'contenthealth' ),
					'apply'      => __( 'Uygula', 'contenthealth' ),
					'confirm'    => __( 'Değişiklikler uygulanacak ve geri alma günlüğüne yazılacak. Devam edilsin mi?', 'contenthealth' ),
					'nothing'    => __( 'Değişecek bir şey yok.', 'contenthealth' ),
					'changed'    => __( 'değişecek', 'contenthealth' ),
					'applied'    => __( 'uygulandı', 'contenthealth' ),
					'skipped'    => __( 'atlandı', 'contenthealth' ),
					'errors'     => __( 'hata', 'contenthealth' ),
					'undo'       => __( 'Geri al', 'contenthealth' ),
					'undone'     => __( 'Geri alındı', 'contenthealth' ),
					'error'      => __( 'İstek başarısız', 'contenthealth' ),
					'removed'    => __( 'Silinecek', 'contenthealth' ),
					'added'      => __( 'Eklenecek', 'contenthealth' ),
					'open'       => __( 'açık', 'contenthealth' ),
				),
			)
		);
	}

	/** @return array<string,array{label:string,render:callable}> */
	private static function tabs(): array {
		$tabs = array(
			'overview' => array(
				'label'  => __( 'Özet', 'contenthealth' ),
				'render' => array( self::class, 'tab_overview' ),
			),
			'issues'   => array(
				'label'  => __( 'Bulgular', 'contenthealth' ),
				'render' => array( self::class, 'tab_issues' ),
			),
		);
		foreach ( Plugin::modules() as $m ) {
			foreach ( $m->admin_pages() as $slug => $page ) {
				$tabs[ $slug ] = $page;
			}
		}
		$tabs['settings'] = array(
			'label'  => __( 'Ayarlar', 'contenthealth' ),
			'render' => array( self::class, 'tab_settings' ),
		);
		$tabs['undo']     = array(
			'label'  => __( 'Geri alma günlüğü', 'contenthealth' ),
			'render' => array( self::class, 'tab_undo' ),
		);
		return $tabs;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'contenthealth' ), 403 );
		}
		$tabs = self::tabs();
		$cur  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cur  = isset( $tabs[ $cur ] ) ? $cur : 'overview';
		echo '<div class="wrap ch-wrap"><h1>' . esc_html__( 'ContentHealth — İçerik Sağlığı', 'contenthealth' ) . '</h1>';
		self::notices();
		echo '<nav class="nav-tab-wrapper ch-tabs" aria-label="' . esc_attr__( 'ContentHealth sekmeleri', 'contenthealth' ) . '">';
		foreach ( $tabs as $slug => $tab ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$slug === $cur ? ' nav-tab-active' : '',
				esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $slug ), admin_url( 'tools.php' ) ) ),
				esc_html( $tab['label'] )
			);
		}
		echo '</nav><div class="ch-body">';
		call_user_func( $tabs[ $cur ]['render'] );
		echo '</div></div>';
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- yalnız ileti göstergesi.
		if ( isset( $_GET['ch_msg'] ) ) {
			$msg = sanitize_text_field( wp_unslash( $_GET['ch_msg'] ) );
			$cls = isset( $_GET['ch_err'] ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		// phpcs:enable
	}

	public static function tab_overview(): void {
		$s      = Rest::summary();
		$score  = $s['score'];
		$class  = null === $score ? 'none' : ( $score >= 85 ? 'good' : ( $score >= 60 ? 'mid' : 'bad' ) );
		$labels = Registry::severities();
		echo '<div class="ch-cards">';
		echo '<section class="ch-card ch-score ch-score-' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Sağlık puanı', 'contenthealth' ) . '"><span class="ch-score-num" id="ch-score">' . ( null === $score ? '—' : (int) $score ) . '</span><span class="ch-score-cap">/ 100</span>';
		echo '<p class="ch-muted">' . ( $s['finished'] ? esc_html( sprintf( /* translators: %s: date */ __( 'Son tarama: %s', 'contenthealth' ), get_date_from_gmt( (string) $s['finished'], 'Y-m-d H:i' ) ) ) : esc_html__( 'Henüz tarama yapılmadı.', 'contenthealth' ) ) . '</p></section>';
		foreach ( array( Rule::CRITICAL, Rule::WARNING, Rule::INFO ) as $k ) {
			echo '<section class="ch-card"><span class="ch-count ch-sev-' . esc_attr( $k ) . '">' . (int) $s['severity'][ $k ] . '</span><span class="ch-muted">' . esc_html( $labels[ $k ] ) . '</span></section>';
		}
		echo '</div>';
		echo '<div class="ch-scan" id="ch-scan"><button type="button" class="button button-primary" id="ch-scan-btn">' . esc_html__( 'Şimdi tara', 'contenthealth' ) . '</button> ';
		echo '<button type="button" class="button" id="ch-cancel-btn" hidden>' . esc_html__( 'İptal', 'contenthealth' ) . '</button>';
		echo '<div class="ch-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><span id="ch-bar"></span></div>';
		echo '<p id="ch-scan-msg" class="ch-muted" role="status" aria-live="polite"></p></div>';

		echo '<h2>' . esc_html__( 'En çok bulgu olan kurallar', 'contenthealth' ) . '</h2>';
		if ( ! $s['rules'] ) {
			echo '<p class="ch-muted">' . esc_html__( 'Açık bulgu yok.', 'contenthealth' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped ch-table"><thead><tr><th>' . esc_html__( 'Kural', 'contenthealth' ) . '</th><th>' . esc_html__( 'Ciddiyet', 'contenthealth' ) . '</th><th>' . esc_html__( 'Açık', 'contenthealth' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $s['rules'], 0, 12 ) as $r ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'issues', 'rule' => $r['id'] ), admin_url( 'tools.php' ) );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( $r['label'] ) . '</a></td><td><span class="ch-badge ch-sev-' . esc_attr( $r['severity'] ) . '">' . esc_html( $labels[ $r['severity'] ] ) . '</span></td><td>' . (int) $r['open'] . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function tab_issues(): void {
		$view = isset( $_GET['view'] ) ? (int) $_GET['view'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $view ) {
			self::issue_detail( $view );
			return;
		}
		$table = new IssuesTable();
		$table->prepare_items();
		echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="issues" />';
		$table->search_box( __( 'Bulgularda ara', 'contenthealth' ), 'ch-search' );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'issues' ), admin_url( 'tools.php' ) ) ) . '">';
		wp_nonce_field( 'contenthealth_bulk', 'ch_bulk_nonce' );
		$table->display();
		echo '</form>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=contenthealth_export' ), 'contenthealth_export' ) ) . '">' . esc_html__( 'CSV indir', 'contenthealth' ) . '</a></p>';
	}

	private static function issue_detail( int $id ): void {
		$issue = Issues::get( $id );
		$back  = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'issues' ), admin_url( 'tools.php' ) );
		if ( ! $issue ) {
			echo '<p>' . esc_html__( 'Bulgu bulunamadı.', 'contenthealth' ) . '</p><p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Bulgulara dön', 'contenthealth' ) . '</a></p>';
			return;
		}
		$rule = Registry::get( $issue->rule_id );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Bulgulara dön', 'contenthealth' ) . '</a></p>';
		echo '<h2>' . esc_html( $rule ? $rule->label() : $issue->rule_id ) . '</h2>';
		if ( $rule ) {
			echo '<p>' . esc_html( $rule->description() ) . '</p>';
		}
		echo '<h3>' . esc_html__( 'Ayrıntı', 'contenthealth' ) . '</h3><pre class="ch-pre">' . esc_html( (string) wp_json_encode( $issue->details_arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
		if ( $rule && $rule->fix_action() && 'open' === $issue->status ) {
			$res = Fixer::run_rule( $rule->id(), array( $issue->object_id ), true, array() );
			if ( ! is_wp_error( $res ) && ! empty( $res['items'][0]['changes'] ) ) {
				echo '<h3>' . esc_html__( 'Önerilen düzeltme (kuru koşu)', 'contenthealth' ) . '</h3>';
				foreach ( $res['items'][0]['changes'] as $ch ) {
					echo '<div class="ch-diff"><strong>' . esc_html( (string) $ch['field'] ) . '</strong>';
					foreach ( (array) ( $ch['removed'] ?? array() ) as $l ) {
						echo '<div class="ch-del">− ' . esc_html( (string) $l ) . '</div>';
					}
					foreach ( (array) ( $ch['added'] ?? array() ) as $l ) {
						echo '<div class="ch-add">+ ' . esc_html( (string) $l ) . '</div>';
					}
					echo '</div>';
				}
			}
		}
	}

	/**
	 * Kuru koşu / uygula paneli (JS bağlar). Düzeltmesi olmayan kurallar için yalnız sayı gösterir.
	 *
	 * @param array<string,mixed> $opts İstek gövdesine eklenir (ör. alt_template).
	 */
	public static function fix_panel( string $rule_id, string $title, bool $fixable = true, array $opts = array() ): void {
		$rule = Registry::get( $rule_id );
		if ( ! $rule ) {
			return;
		}
		$open = Issues::open_counts()[ $rule_id ] ?? array();
		$n    = array_sum( $open );
		$view = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'issues', 'rule' => $rule_id ), admin_url( 'tools.php' ) );
		echo '<section class="ch-panel" data-rule="' . esc_attr( $rule_id ) . '" data-opts="' . esc_attr( (string) wp_json_encode( $opts ) ) . '">';
		echo '<header><h3>' . esc_html( $title ) . '</h3><span class="ch-badge ch-sev-' . esc_attr( $rule->severity() ) . '">' . (int) $n . ' ' . esc_html__( 'açık', 'contenthealth' ) . '</span></header>';
		echo '<p class="ch-muted">' . esc_html( $rule->description() ) . '</p>';
		echo '<p><a href="' . esc_url( $view ) . '">' . esc_html__( 'Bulguları gör', 'contenthealth' ) . '</a>';
		if ( $fixable && $rule->fix_action() && $n > 0 ) {
			echo ' <button type="button" class="button ch-dry">' . esc_html__( 'Kuru koşu', 'contenthealth' ) . '</button> <button type="button" class="button button-primary ch-apply" disabled>' . esc_html__( 'Uygula', 'contenthealth' ) . '</button>';
		}
		echo '</p><div class="ch-result" role="status" aria-live="polite"></div></section>';
	}

	public static function tab_settings(): void {
		$all = Settings::all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ch-form">';
		wp_nonce_field( 'contenthealth_save' );
		echo '<input type="hidden" name="action" value="contenthealth_save" />';
		echo '<h2>' . esc_html__( 'Genel', 'contenthealth' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="ch-freq">' . esc_html__( 'Otomatik tarama', 'contenthealth' ) . '</label></th><td><select id="ch-freq" name="s[frequency]">';
		foreach ( array(
			'off'    => __( 'Kapalı', 'contenthealth' ),
			'hourly' => __( 'Saatlik', 'contenthealth' ),
			'daily'  => __( 'Günlük', 'contenthealth' ),
			'weekly' => __( 'Haftalık', 'contenthealth' ),
		) as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $all['frequency'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></td></tr>';
		self::num_row( 'time_budget', __( 'Parti süresi (sn)', 'contenthealth' ), (int) $all['time_budget'], 1, 20 );
		self::num_row( 'batch_size', __( 'Parti büyüklüğü (nesne)', 'contenthealth' ), (int) $all['batch_size'], 10, 500 );
		self::text_row( 'statuses', __( 'Taranan yazı durumları (virgülle)', 'contenthealth' ), implode( ', ', (array) $all['statuses'] ) );
		self::text_row( 'exclude_post_types', __( 'Dışlanan yazı türleri (virgülle)', 'contenthealth' ), implode( ', ', (array) $all['exclude_post_types'] ) );
		self::text_row( 'term_taxonomies', __( 'Taranan sınıflandırmalar (virgülle)', 'contenthealth' ), implode( ', ', (array) $all['term_taxonomies'] ) );
		self::text_row( 'live_host', __( 'Canlı sunucu adı (hatalı adres düzeltmesi için)', 'contenthealth' ), (string) $all['live_host'], 'example.com' );
		self::text_row( 'alt_template', __( 'Alt metin şablonu', 'contenthealth' ), (string) $all['alt_template'], '{başlık}' );
		echo '<tr><th scope="row">' . esc_html__( 'Değiştirilme tarihi', 'contenthealth' ) . '</th><td><label><input type="checkbox" name="s[keep_modified]" value="1"' . checked( ! empty( $all['keep_modified'] ), true, false ) . ' /> ' . esc_html__( 'Düzeltmeler yazının "son değiştirilme" tarihini DEĞİŞTİRMESİN', 'contenthealth' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Kaldırırken', 'contenthealth' ) . '</th><td><label><input type="checkbox" name="s[delete_on_uninstall]" value="1"' . checked( ! empty( $all['delete_on_uninstall'] ), true, false ) . ' /> ' . esc_html__( 'Eklenti silinirken tüm tabloları ve ayarları da sil', 'contenthealth' ) . '</label></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Kurallar', 'contenthealth' ) . '</h2>';
		foreach ( Registry::groups() as $gid => $glabel ) {
			echo '<h3>' . esc_html( $glabel ) . '</h3>';
			foreach ( Registry::rules() as $rule ) {
				if ( $rule->group() !== $gid ) {
					continue;
				}
				$cfg = $all['rules'][ $rule->id() ];
				$n   = 'r-' . $rule->id();
				echo '<div class="ch-rule"><p class="ch-rule-head"><label><input type="checkbox" name="s[rules][' . esc_attr( $rule->id() ) . '][enabled]" value="1"' . checked( ! empty( $cfg['enabled'] ), true, false ) . ' /> <strong>' . esc_html( $rule->label() ) . '</strong></label> <span class="ch-badge ch-sev-' . esc_attr( $rule->severity() ) . '">' . esc_html( Registry::severities()[ $rule->severity() ] ) . '</span></p><details><summary>' . esc_html__( 'Ayrıntı ve ince ayar', 'contenthealth' ) . '</summary>';
				echo '<p class="ch-muted">' . esc_html( $rule->description() ) . '</p>';
				foreach ( $rule->defaults() as $key => $dv ) {
					if ( 'enabled' === $key ) {
						continue;
					}
					$fid = $n . '-' . $key;
					echo '<p><label for="' . esc_attr( $fid ) . '">' . esc_html( $key ) . '</label><br />';
					$name = 's[rules][' . $rule->id() . '][' . $key . ']';
					if ( is_array( $dv ) ) {
						echo '<textarea id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text code">' . esc_textarea( implode( "\n", array_map( 'strval', (array) ( $cfg[ $key ] ?? array() ) ) ) ) . '</textarea>';
					} elseif ( is_bool( $dv ) ) {
						echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" /><input id="' . esc_attr( $fid ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $cfg[ $key ] ), true, false ) . ' />';
					} else {
						echo '<input id="' . esc_attr( $fid ) . '" type="' . ( is_int( $dv ) ? 'number' : 'text' ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $cfg[ $key ] ?? '' ) ) . '" class="small-text" />';
					}
					echo '</p>';
				}
				echo '</details></div>';
			}
		}
		submit_button( __( 'Ayarları kaydet', 'contenthealth' ) );
		echo '</form>';
	}

	private static function num_row( string $key, string $label, int $val, int $min, int $max ): void {
		echo '<tr><th scope="row"><label for="ch-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input id="ch-' . esc_attr( $key ) . '" type="number" min="' . (int) $min . '" max="' . (int) $max . '" name="s[' . esc_attr( $key ) . ']" value="' . (int) $val . '" class="small-text" /></td></tr>';
	}

	private static function text_row( string $key, string $label, string $val, string $ph = '' ): void {
		echo '<tr><th scope="row"><label for="ch-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input id="ch-' . esc_attr( $key ) . '" type="text" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $ph ) . '" class="regular-text" /></td></tr>';
	}

	public static function tab_undo(): void {
		$rows = Undo::batches( 30 );
		if ( ! $rows ) {
			echo '<p class="ch-muted">' . esc_html__( 'Henüz uygulanmış bir düzeltme yok.', 'contenthealth' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped ch-table"><thead><tr><th>' . esc_html__( 'Yığın', 'contenthealth' ) . '</th><th>' . esc_html__( 'Eylem', 'contenthealth' ) . '</th><th>' . esc_html__( 'Kayıt', 'contenthealth' ) . '</th><th>' . esc_html__( 'Geri alınabilir', 'contenthealth' ) . '</th><th>' . esc_html__( 'Tarih', 'contenthealth' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><code>' . esc_html( $r->batch_id ) . '</code></td><td>' . esc_html( $r->action ) . '</td><td>' . (int) $r->items . '</td><td>' . (int) $r->pending . '</td><td>' . esc_html( get_date_from_gmt( (string) $r->created_at, 'Y-m-d H:i' ) ) . '</td><td>';
			if ( (int) $r->pending > 0 ) {
				echo '<button type="button" class="button ch-undo" data-key="' . esc_attr( $r->batch_id ) . '">' . esc_html__( 'Geri al', 'contenthealth' ) . '</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table><div class="ch-result" id="ch-undo-result" role="status" aria-live="polite"></div>';
	}

	/* ------------------------- admin-post işleyicileri ------------------------- */

	private static function guard( string $nonce, string $field = '_wpnonce' ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'contenthealth' ), 403 );
		}
		check_admin_referer( $nonce, $field );
	}

	private static function back( string $tab, string $msg, bool $err = false ): void {
		$args = array(
			'page'   => self::SLUG,
			'tab'    => $tab,
			'ch_msg' => rawurlencode( $msg ),
		);
		if ( $err ) {
			$args['ch_err'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function save(): void {
		self::guard( 'contenthealth_save' );
		$in = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::save her alanı temizler.
		foreach ( array( 'keep_modified', 'delete_on_uninstall' ) as $b ) {
			$in[ $b ] = ! empty( $in[ $b ] );
		}
		$res = Settings::save( $in );
		if ( $res['errors'] ) {
			self::back( 'settings', __( 'Kaydedildi; bazı desenler geçersiz olduğu için atlandı: ', 'contenthealth' ) . implode( ' | ', array_slice( $res['errors'], 0, 3 ) ), true );
		}
		self::back( 'settings', __( 'Ayarlar kaydedildi.', 'contenthealth' ) );
	}

	public static function bulk(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['ids'] ) ) {
			return;
		}
		self::guard( 'contenthealth_bulk', 'ch_bulk_nonce' );
		$act = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( ! in_array( $act, array( 'ignore', 'reopen' ), true ) && isset( $_POST['action2'] ) ) {
			$act = sanitize_key( wp_unslash( $_POST['action2'] ) );
		}
		$ids = array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) );
		$map = array(
			'ignore' => 'ignored',
			'reopen' => 'open',
		);
		if ( ! isset( $map[ $act ] ) || ! $ids ) {
			self::back( 'issues', __( 'Eylem ya da seçim yok.', 'contenthealth' ), true );
		}
		$n = Issues::set_status( $ids, $map[ $act ] );
		/* translators: %d: number of rows */
		self::back( 'issues', sprintf( __( '%d bulgu güncellendi.', 'contenthealth' ), $n ) );
	}

	public static function export(): void {
		self::guard( 'contenthealth_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="contenthealth-bulgular.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputs( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputs
		fputcsv( $out, array( 'id', 'kural', 'ciddiyet', 'tür', 'nesne', 'durum', 'özet' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		$page = 1;
		do {
			$res = Issues::query( array( 'status' => '', 'per_page' => 500, 'page' => $page, 'order' => 'asc' ) );
			foreach ( $res['rows'] as $r ) {
				$sum = (string) wp_json_encode( $r->details_arr, JSON_UNESCAPED_UNICODE );
				// CSV enjeksiyonuna karşı: formül başlatan karakterle başlayan hücreleri etkisizleştir.
				if ( preg_match( '~^[=+\-@\t\r]~', $sum ) ) {
					$sum = "'" . $sum;
				}
				fputcsv( $out, array( $r->id, $r->rule_id, $r->severity, $r->object_type, $r->object_id, $r->status, Text::snippet( $sum, 300 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			}
			++$page;
		} while ( $res['rows'] && ( $page - 1 ) * 500 < $res['total'] );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
