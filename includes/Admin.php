<?php
/**
 * Yönetim ekranı: RBN Kit → İçerik Sağlığı. Kabuk (başlık, sekmeler, canlı bölge) ve admin-post işleyicileri.
 * CSS/JS yalnız bu sayfada yüklenir; ekranların kendisi Screens\ altındadır.
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
		// Başlıklar çeviri kullanır; `init` öncesi okunursa WP 6.7+ "çok erken yüklendi" uyarısı verir. Yardımcı menüyü öncelik 9'da kurar, kayıt ondan önce.
		add_action( 'admin_menu', array( self::class, 'menu' ), 5 );
		add_action( 'admin_menu', array( self::class, 'late' ), 99 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_contenthealth_save', array( self::class, 'save' ) );
		add_action( 'admin_post_contenthealth_export', array( self::class, 'export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CONTENTHEALTH_FILE ), array( self::class, 'links' ) );
	}

	/** @param array<int,string> $links */
	public static function links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( Ui::url() ) . '">' . esc_html__( 'Aç', 'contenthealth' ) . '</a>' );
		return $links;
	}

	/** Yan menüde RBN Kit altına kaydolur; `RbnKitMenu` kendi `admin_menu` (öncelik 9) kancasıyla menüyü kurar. */
	public static function menu(): void {
		\RbnKitMenu::add(
			array(
				'slug'       => self::SLUG,
				'title'      => __( 'İçerik Sağlığı', 'contenthealth' ),
				'menu_title' => __( 'İçerik Sağlığı', 'contenthealth' ),
				'cap'        => self::CAP,
				'callback'   => array( self::class, 'render' ),
				'version'    => CONTENTHEALTH_VERSION,
			)
		);
	}

	/** `admin_menu` bittikten sonra: ekran kimliği (rbn-kit_page_contenthealth) ve toplu işlem kancası. */
	public static function late(): void {
		self::legacy_redirect();
		self::$hook = \RbnKitMenu::hook( self::SLUG );
		if ( '' !== self::$hook ) {
			add_action( 'load-' . self::$hook, array( self::class, 'bulk' ) );
		}
	}

	/** Eski adres (Araçlar → ContentHealth) yer imi kolaylığı için yeni adrese yönlenir; `admin_init` sayfa yetki denetiminden (403) sonra çalıştığından `admin_menu` içinden çağrılır. */
	private static function legacy_redirect(): void {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- yalnız yönlendirme.
		if ( 'tools.php' === $pagenow && isset( $_GET['page'] ) && self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			wp_safe_redirect( Ui::url( array_diff_key( map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ), array( 'page' => 1 ) ) ), 301 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			exit;
		}
	}

	/** Sürüm sabit kalsa da değişen dosya yeniden insin diye sürüm parametresi dosya içerik özetidir. */
	private static function asset_ver( string $rel ): string {
		$hash = is_readable( CONTENTHEALTH_DIR . $rel ) ? md5_file( CONTENTHEALTH_DIR . $rel ) : false;
		return $hash ? CONTENTHEALTH_VERSION . '-' . substr( $hash, 0, 8 ) : CONTENTHEALTH_VERSION;
	}

	public static function assets( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'contenthealth-admin', CONTENTHEALTH_URL . 'assets/admin/rbn-admin-ui.css', array(), self::asset_ver( 'assets/admin/rbn-admin-ui.css' ) );
		wp_enqueue_script( 'contenthealth-admin', CONTENTHEALTH_URL . 'assets/admin/contenthealth-admin.js', array(), self::asset_ver( 'assets/admin/contenthealth-admin.js' ), true );
		wp_localize_script(
			'contenthealth-admin',
			'ContentHealthCfg',
			array(
				'root'  => esc_url_raw( rest_url( Rest::NS . '/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'home'  => esc_url_raw( Ui::url() ),
				'i18n'  => self::strings(),
			)
		);
	}

	/** @return array<string,string> JS'in kullandığı metinler (çeviri .po üzerinden gelir). */
	private static function strings(): array {
		return array(
			'starting'     => __( 'Tarama başlıyor…', 'contenthealth' ),
			'scanning'     => __( 'Taranıyor…', 'contenthealth' ),
			'paused'       => __( 'Tarama duraklatıldı', 'contenthealth' ),
			'pause'        => __( 'Duraklat', 'contenthealth' ),
			'resume'       => __( 'Sürdür', 'contenthealth' ),
			'cancelled'    => __( 'Tarama iptal edildi.', 'contenthealth' ),
			'scanDone'     => __( 'Tarama bitti.', 'contenthealth' ),
			'scanFail'     => __( 'Tarama durdu.', 'contenthealth' ),
			'retry'        => __( 'Yeniden dene', 'contenthealth' ),
			'resumeAt'     => __( 'Kaldığı yerden devam et', 'contenthealth' ),
			'netError'     => __( 'Sunucuya ulaşılamadı. Bağlantınızı kontrol edip yeniden deneyin.', 'contenthealth' ),
			'loadFail'     => __( 'Ayrıntı yüklenemedi.', 'contenthealth' ),
			'selected'     => __( 'bulgu seçili', 'contenthealth' ),
			'images'       => __( 'görsel seçili', 'contenthealth' ),
			'step1'        => __( 'Kuru koşuyu çalıştır', 'contenthealth' ),
			'step2'        => __( 'Devam', 'contenthealth' ),
			'step3'        => __( 'Düzeltmeleri uygula', 'contenthealth' ),
			'close'        => __( 'Kapat', 'contenthealth' ),
			'applying'     => __( 'Uygulanıyor…', 'contenthealth' ),
			'dryRunning'   => __( 'Kuru koşu yapılıyor…', 'contenthealth' ),
			'fixed'        => __( 'düzeltildi', 'contenthealth' ),
			'skipped'      => __( 'atlandı', 'contenthealth' ),
			'posts'        => __( 'yazı', 'contenthealth' ),
			'sample'       => __( 'Örnek', 'contenthealth' ),
			'dirty'        => __( 'Kaydedilmemiş değişiklik var.', 'contenthealth' ),
			'clean'        => __( 'Kaydedilmemiş değişiklik yok.', 'contenthealth' ),
			'patNone'      => __( 'Örnek metinde eşleşme yok.', 'contenthealth' ),
			'patMatches'   => __( 'eşleşme', 'contenthealth' ),
			'patBad'       => __( 'Desen geçersiz ya da çok yavaş.', 'contenthealth' ),
			'patDel'       => __( 'Deseni sil', 'contenthealth' ),
			'undone'       => __( 'geri alındı', 'contenthealth' ),
			'undoFail'     => __( 'Geri alınamadı', 'contenthealth' ),
			'merged'       => __( 'Birleştirildi', 'contenthealth' ),
			'deleted'      => __( 'silindi', 'contenthealth' ),
			'filled'       => __( 'Alt metin dolduruldu', 'contenthealth' ),
			'undoAction'   => __( 'Geri al', 'contenthealth' ),
			'undoRecord'   => __( 'Geri alma kaydı', 'contenthealth' ),
			'noChange'     => __( 'Değişecek bir şey yok.', 'contenthealth' ),
			'errors'       => __( 'hata', 'contenthealth' ),
			'before'       => __( 'Önce', 'contenthealth' ),
			'after'        => __( 'Sonra', 'contenthealth' ),
			'wizOk'        => __( 'Devam edebilmek için onay kutusunu işaretleyin.', 'contenthealth' ),
			'etaLeft'      => __( 'yaklaşık', 'contenthealth' ),
			'batch'        => __( 'Parti', 'contenthealth' ),
			'items'        => __( 'içerik', 'contenthealth' ),
			'sec'          => __( 'sn', 'contenthealth' ),
			'willFix'      => __( 'Değişecek', 'contenthealth' ),
			'willSkip'     => __( 'Atlanacak', 'contenthealth' ),
			'errorsK'      => __( 'Hata', 'contenthealth' ),
			'next'         => __( 'Devam', 'contenthealth' ),
			'startScan'    => __( 'İlk taramayı başlat', 'contenthealth' ),
			'openIssues'   => __( 'açık bulgu bulundu.', 'contenthealth' ),
		);
	}

	/**
	 * Sekmeler: Pano, Bulgular, modül sayfaları, Geri alma günlüğü, Ayarlar.
	 *
	 * @return array<string,array{label:string,render:callable,count?:callable,dialogs?:callable}>
	 */
	private static function tabs(): array {
		$tabs = array(
			'overview' => array(
				'label'   => __( 'Pano', 'contenthealth' ),
				'render'  => array( Screens\Overview::class, 'render' ),
				'dialogs' => array( Screens\Overview::class, 'dialogs' ),
			),
			'issues'   => array(
				'label'   => __( 'Bulgular', 'contenthealth' ),
				'render'  => array( Screens\Issues::class, 'render' ),
				'count'   => array( Screens\Issues::class, 'count' ),
				'dialogs' => array( Screens\Issues::class, 'dialogs' ),
			),
		);
		foreach ( Plugin::modules() as $m ) {
			foreach ( $m->admin_pages() as $slug => $page ) {
				$tabs[ $slug ] = $page;
			}
		}
		$tabs['undo']     = array(
			'label'   => __( 'Geri alma günlüğü', 'contenthealth' ),
			'render'  => array( Screens\UndoLog::class, 'render' ),
			'dialogs' => array( Screens\UndoLog::class, 'dialogs' ),
		);
		$tabs['settings'] = array(
			'label'  => __( 'Ayarlar', 'contenthealth' ),
			'render' => array( Screens\Prefs::class, 'render' ),
		);
		return $tabs;
	}

	public static function render(): void {
		Ui::sprite();
		echo '<div class="wrap"><hr class="wp-header-end" />';
		if ( ! current_user_can( self::CAP ) ) {
			echo '<main class="rbn-wrap" id="ch" data-screen="yetki"' . Ui::tone_attr() . '><section class="rbn-card"><div class="rbn-card__b rbn-card__b--pad">' . Ui::empty_state( 'lock', __( 'Bu sayfaya erişim yetkiniz yok', 'contenthealth' ), __( 'İçerik Sağlığı’nı yalnız yönetici rolü kullanabilir. Erişim için site yöneticisine başvurun.', 'contenthealth' ), '<a class="rbn-btn" href="' . esc_url( admin_url() ) . '">' . esc_html__( 'Panoya dön', 'contenthealth' ) . '</a>' ) . '</div></section></main></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui kaçışlar.
			return;
		}
		$tabs = self::tabs();
		$cur  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cur  = isset( $tabs[ $cur ] ) ? $cur : 'overview';
		$onb  = 'overview' === $cur && Screens\Onboarding::needed();

		printf(
			'<main class="rbn-wrap" id="ch" data-screen="%s"%s>',
			esc_attr( $onb ? 'ilk' : $cur ),
			Ui::tone_attr() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sabit öznitelik.
		);
		echo '<p class="rbn-sr" id="live" role="status" aria-live="polite"></p>';
		self::head( 'overview' === $cur && ! $onb, 'overview' === $cur );
		if ( ! $onb ) {
			self::nav( $tabs, $cur );
		}
		self::notices();
		if ( $onb ) {
			Screens\Onboarding::render();
		} else {
			call_user_func( $tabs[ $cur ]['render'] );
		}
		echo '</main>';
		if ( ! $onb && isset( $tabs[ $cur ]['dialogs'] ) ) {
			call_user_func( $tabs[ $cur ]['dialogs'] );
		}
		echo '</div>';
	}

	private static function head( bool $scan_button, bool $on_overview ): void {
		echo '<header class="rbn-head"><div class="rbn-head__id"><h1>' . esc_html__( 'İçerik Sağlığı', 'contenthealth' ) . '</h1><span class="rbn-chip">v' . esc_html( CONTENTHEALTH_VERSION ) . '</span><span class="rbn-chip rbn-chip--ok"><span class="rbn-dot"></span>' . esc_html__( 'Etkin', 'contenthealth' ) . '</span></div><div class="rbn-head__act">';
		if ( $scan_button ) {
			echo '<button type="button" class="rbn-btn rbn-btn--pri" data-scan>' . Ui::icon( 'scan' ) . esc_html__( 'Şimdi tara', 'contenthealth' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( ! $on_overview ) {
			echo '<a class="rbn-btn rbn-btn--pri" href="' . esc_url( Ui::url( array( 'tara' => 1 ) ) ) . '">' . Ui::icon( 'scan' ) . esc_html__( 'Şimdi tara', 'contenthealth' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></header>';
	}

	/** @param array<string,array<string,mixed>> $tabs */
	private static function nav( array $tabs, string $cur ): void {
		echo '<nav aria-label="' . esc_attr__( 'İçerik Sağlığı bölümleri', 'contenthealth' ) . '"><ul class="rbn-tabs">';
		foreach ( $tabs as $slug => $tab ) {
			$count = isset( $tab['count'] ) ? (int) call_user_func( $tab['count'] ) : 0;
			printf(
				'<li><a href="%s"%s>%s%s</a></li>',
				esc_url( Ui::url( 'overview' === $slug ? array() : array( 'tab' => $slug ) ) ),
				$slug === $cur ? ' aria-current="page"' : '',
				esc_html( (string) $tab['label'] ),
				$count > 0 ? ' <span class="rbn-count">' . (int) $count . '</span>' : ''
			);
		}
		echo '</ul></nav>';
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- yalnız ileti göstergesi.
		if ( isset( $_GET['ch_msg'] ) ) {
			$msg = sanitize_text_field( wp_unslash( $_GET['ch_msg'] ) );
			echo Ui::note( isset( $_GET['ch_err'] ) ? 'warn' : 'ok', '<strong>' . esc_html( $msg ) . '</strong>', '', array( 'role' => 'status', 'id' => 'flash-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		// phpcs:enable
	}

	/* ------------------------- admin-post işleyicileri ------------------------- */

	private static function guard( string $nonce, string $field = '_wpnonce' ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'contenthealth' ), 403 );
		}
		check_admin_referer( $nonce, $field );
	}

	/** @param array<string,mixed> $extra */
	private static function back( string $tab, string $msg, bool $err = false, array $extra = array() ): void {
		$args = array_merge(
			array(
				'tab'    => $tab,
				'ch_msg' => rawurlencode( $msg ),
			),
			$extra
		);
		if ( $err ) {
			$args['ch_err'] = 1;
		}
		wp_safe_redirect( Ui::url( $args ) );
		exit;
	}

	public static function save(): void {
		self::guard( 'contenthealth_save' );
		$in = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::save her alanı temizler.
		foreach ( array( 'keep_modified', 'audit_on_save', 'delete_on_uninstall' ) as $b ) {
			$in[ $b ] = ! empty( $in[ $b ] );
		}
		$in = Screens\Prefs::normalize( $in );
		$res = Settings::save( $in );
		if ( $res['errors'] ) {
			self::back( 'settings', __( 'Kaydedildi; bazı desenler geçersiz olduğu için atlandı: ', 'contenthealth' ) . implode( ' | ', array_slice( $res['errors'], 0, 3 ) ), true );
		}
		self::back( 'settings', __( 'Ayarlar kaydedildi.', 'contenthealth' ) );
	}

	/** Bulgu listesi formu (JS yokken de çalışır): seçilenleri / tek bulguyu yok say ya da yeniden aç. */
	public static function bulk(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['ch_bulk_nonce'] ) ) {
			return;
		}
		self::guard( 'contenthealth_bulk', 'ch_bulk_nonce' );
		$act = isset( $_POST['ch_act'] ) ? sanitize_key( wp_unslash( $_POST['ch_act'] ) ) : '';
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		if ( isset( $_POST['one'] ) ) {
			$ids = array( (int) $_POST['one'] );
			$act = 'ignore';
		} elseif ( isset( $_POST['reopen'] ) ) {
			$ids = array( (int) $_POST['reopen'] );
			$act = 'reopen';
		}
		$map = array(
			'ignore' => 'ignored',
			'reopen' => 'open',
		);
		if ( ! isset( $map[ $act ] ) || ! $ids ) {
			self::back( 'issues', __( 'Eylem ya da seçim yok.', 'contenthealth' ), true );
		}
		$n = Issues::set_status( $ids, $map[ $act ] );
		/* translators: %d: number of rows */
		self::back( 'issues', sprintf( 'ignored' === $map[ $act ] ? __( '%d bulgu yok sayıldı.', 'contenthealth' ) : __( '%d bulgu yeniden açıldı.', 'contenthealth' ), $n ) );
	}

	public static function export(): void {
		self::guard( 'contenthealth_export' );
		$f = Screens\Issues::filters_from( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() nonce'u doğruladı.
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="contenthealth-bulgular.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputs( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputs
		fputcsv( $out, array( 'id', 'kural', 'ciddiyet', 'tür', 'nesne', 'durum', 'özet' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		$page = 1;
		do {
			$res = Issues::query( array_merge( $f, array( 'per_page' => 500, 'page' => $page, 'order' => 'asc' ) ) );
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
