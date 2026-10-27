<?php
/**
 * D grubu: bağlantı kuralları.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Rules;

use ContentHealth\Ctx;
use ContentHealth\Rule;
use ContentHealth\Text;
use ContentHealth\Url;

defined( 'ABSPATH' ) || exit;

abstract class LinkRule extends Rule {
	public function group(): string {
		return 'links';
	}

	/**
	 * Yazı türü süzgeci (boş: hepsi).
	 *
	 * @param array<int,string> $types Tür listesi.
	 */
	protected function in_types( object $obj, array $types ): bool {
		return empty( $types ) || in_array( $obj->post_type, $types, true );
	}
}

final class BadHostUrl extends LinkRule {
	public function id(): string {
		return 'bad_host_url';
	}
	public function severity(): string {
		return Rule::CRITICAL;
	}
	public function label(): string {
		return __( 'Hatalı sunucu adresi', 'contenthealth' );
	}
	public function description(): string {
		return __( 'İçerikte geliştirme/staging adresi (.test, .local, localhost), tekrarlı uzantı (.test.test) ya da bozuk biçim (https.alanadi) sızıntısı. Sitenin kendi adresi hariçtir.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_bad_host_url';
	}
	public function defaults(): array {
		return array(
			'enabled'   => true,
			'dev_hosts' => array(),
		);
	}

	/**
	 * @return array<string,array<int,string>> neden → adresler
	 */
	public static function scan_html( string $html, Ctx $ctx, array $dev_hosts = array() ): array {
		$by = array();
		foreach ( Url::candidates( $html ) as $u ) {
			$r = Url::classify( $u, $ctx->home_host, array_map( 'strtolower', $dev_hosts ) );
			if ( $r ) {
				$by[ $r[0] ][] = $u;
			}
		}
		return $by;
	}

	public function check( object $obj, Ctx $ctx ): ?array {
		$by = self::scan_html( $obj->html, $ctx, (array) $this->config()['dev_hosts'] );
		if ( ! $by ) {
			return null;
		}
		$reasons = array();
		foreach ( $by as $reason => $urls ) {
			$reasons[ $reason ] = array(
				'count'    => count( $urls ),
				'examples' => array_slice( $urls, 0, 3 ),
			);
		}
		return array( 'reasons' => $reasons );
	}
}

final class MixedContent extends LinkRule {
	public function id(): string {
		return 'mixed_content';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Karışık içerik (http)', 'contenthealth' );
	}
	public function description(): string {
		return __( 'Site https iken içerikte http:// görsel, iframe, betik ya da kendi sitene http bağlantı.', 'contenthealth' );
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		if ( ! $ctx->https ) {
			return null;
		}
		$items = array();
		if ( preg_match_all( '~<(?:img|iframe|script|link|source|video|audio|embed)\b[^>]*\s(?:src|href|data-src)\s*=\s*["\']?(http://[^\s"\'>]+)~i', $obj->html, $m ) ) {
			foreach ( array_unique( $m[1] ) as $u ) {
				$items[] = array( 'url' => Text::snippet( $u, 120 ) );
			}
		}
		foreach ( $obj->hrefs() as $h ) {
			if ( 0 === stripos( $h, 'http://' ) && Url::is_internal( $h, $ctx->home_host ) ) {
				$items[] = array( 'url' => Text::snippet( $h, 120 ) );
			}
		}
		return $items ? $this->found( $items ) : null;
	}
}

final class NoInternalLinks extends LinkRule {
	public function id(): string {
		return 'no_internal_links';
	}
	public function severity(): string {
		return Rule::INFO;
	}
	public function label(): string {
		return __( 'İç bağlantı yok', 'contenthealth' );
	}
	public function description(): string {
		return __( 'Yeterince uzun içerikte siteye ait hiçbir bağlantı yok.', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'    => true,
			'min_words'  => 150,
			'post_types' => array( 'post' ),
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		$c = $this->config();
		if ( ! $this->in_types( $obj, (array) $c['post_types'] ) || $obj->words() < (int) $c['min_words'] ) {
			return null;
		}
		foreach ( $obj->hrefs() as $h ) {
			if ( Url::is_internal( $h, $ctx->home_host ) ) {
				return null;
			}
		}
		return array( 'words' => $obj->words() );
	}
}

final class OrphanContent extends LinkRule {
	public function id(): string {
		return 'orphan_content';
	}
	public function severity(): string {
		return Rule::INFO;
	}
	public function label(): string {
		return __( 'Yetim içerik', 'contenthealth' );
	}
	public function description(): string {
		return __( 'Başka hiçbir yazının içeriğinden bağlantı almayan yazı. Menü, widget ve ilgili yazı kutuları sayılmaz; yalnız içerik içi bağlantılar.', 'contenthealth' );
	}
	public function scopes(): array {
		return array( 'global' );
	}
	public function defaults(): array {
		return array(
			'enabled'    => true,
			'post_types' => array( 'post' ),
		);
	}

	public function scan_batch( array $cursor, int $limit, Ctx $ctx ): array {
		global $wpdb;
		$types    = $ctx->post_types();
		$statuses = $ctx->statuses();
		if ( ! $types || ! $statuses ) {
			return parent::scan_batch( $cursor, $limit, $ctx );
		}
		$cursor += array(
			'phase'  => 'collect',
			'last'   => 0,
			'linked' => array(),
		);
		$ph_t = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ph_s = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		if ( 'collect' === $cursor['phase'] ) {
			$sql  = "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$ph_t}) AND post_status IN ({$ph_s}) ORDER BY ID ASC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( (int) $cursor['last'] ), $types, $statuses, array( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$set  = array_fill_keys( array_map( 'intval', $cursor['linked'] ), true );
			foreach ( $rows as $r ) {
				foreach ( Url::hrefs( (string) $r->post_content ) as $h ) {
					if ( Url::is_internal( $h, $ctx->home_host ) ) {
						$target = $ctx->resolve_url( $h );
						if ( $target && $target !== (int) $r->ID ) {
							$set[ $target ] = true;
						}
					}
				}
				$cursor['last'] = (int) $r->ID;
			}
			$cursor['linked'] = array_keys( $set );
			if ( count( $rows ) >= $limit ) {
				return array(
					'findings' => array(),
					'cursor'   => $cursor,
					'done'     => false,
					'units'    => count( $rows ),
				);
			}
			$cursor['phase'] = 'emit';
		}
		$own  = (array) ( $this->config()['post_types'] ?: array( 'post' ) );
		$ph_o = implode( ',', array_fill( 0, count( $own ), '%s' ) );
		$sql  = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$ph_o}) AND post_status IN ({$ph_s})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids  = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $own, $statuses ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$set  = array_fill_keys( array_map( 'intval', $cursor['linked'] ), true );
		$out  = array();
		foreach ( (array) $ids as $id ) {
			if ( ! isset( $set[ (int) $id ] ) ) {
				$out[] = array( 'post', (int) $id, array( 'incoming' => 0 ) );
			}
		}
		return array(
			'findings' => $out,
			'cursor'   => array( 'phase' => 'done' ),
			'done'     => true,
			'units'    => 0,
		);
	}
}

/** Yavaş mod: yalnız site içi bağlantılar, hız sınırlı (≤2 istek/sn). Varsayılan KAPALI. */
final class BrokenInternalLinks extends LinkRule {
	public function id(): string {
		return 'broken_internal_links';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Kırık iç bağlantı (yavaş mod)', 'contenthealth' );
	}
	public function description(): string {
		return __( 'Site içi bağlantılara hız sınırlı (saniyede en çok 2) HEAD isteği atar; 404/410 ve bağlantı hatalarını bulur. Sunucuya yük bindirmemek için varsayılan kapalıdır.', 'contenthealth' );
	}
	public function scopes(): array {
		return array( 'global' );
	}
	public function defaults(): array {
		return array(
			'enabled'      => false,
			'max_requests' => 500,
			'per_second'   => 2,
		);
	}

	public function scan_batch( array $cursor, int $limit, Ctx $ctx ): array {
		global $wpdb;
		$c       = $this->config();
		$cursor += array(
			'last'  => 0,
			'seen'  => array(),
			'sent'  => 0,
			'bad'   => array(),
		);
		$budget = min( 4, max( 1, (int) $c['per_second'] * 2 ) );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ('" . implode( "','", array_map( 'esc_sql', $ctx->post_types() ) ) . "') ORDER BY ID ASC LIMIT 20", (int) $cursor['last'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$sent   = 0;
		$out    = array();
		foreach ( $rows as $r ) {
			foreach ( array_unique( Url::hrefs( (string) $r->post_content ) ) as $h ) {
				if ( ! Url::is_internal( $h, $ctx->home_host ) ) {
					continue;
				}
				$abs = 0 === strpos( $h, '/' ) && 0 !== strpos( $h, '//' ) ? home_url( $h ) : $h;
				$abs = strtok( $abs, '#' ) ?: $abs;
				$k   = substr( md5( $abs ), 0, 10 );
				if ( isset( $cursor['seen'][ $k ] ) ) {
					if ( $cursor['seen'][ $k ] > 0 ) {
						$cursor['bad'][ $r->ID ][] = array(
							'url'  => $abs,
							'code' => $cursor['seen'][ $k ],
						);
					}
					continue;
				}
				if ( $cursor['sent'] >= (int) $c['max_requests'] || $sent >= $budget ) {
					// Bütçe bitti: bu yazıyı yeniden işlemek üzere imleci geri bırak.
					$cursor['last'] = (int) $r->ID - 1;
					return array(
						'findings' => $this->drain( $cursor ),
						'cursor'   => $cursor,
						'done'     => $cursor['sent'] >= (int) $c['max_requests'],
						'units'    => 0,
					);
				}
				usleep( (int) ( 1000000 / max( 1, (int) $c['per_second'] ) ) );
				$res = wp_remote_head( $abs, array( 'timeout' => 8, 'redirection' => 3, 'sslverify' => false ) );
				++$sent;
				++$cursor['sent'];
				$code = is_wp_error( $res ) ? 599 : (int) wp_remote_retrieve_response_code( $res );
				$cursor['seen'][ $k ] = in_array( $code, array( 404, 410, 599 ), true ) ? $code : -1;
				if ( $cursor['seen'][ $k ] > 0 ) {
					$cursor['bad'][ $r->ID ][] = array(
						'url'  => $abs,
						'code' => $code,
					);
				}
			}
			$cursor['last'] = (int) $r->ID;
		}
		$done = count( $rows ) < 20;
		return array(
			'findings' => $done ? $this->drain( $cursor ) : array(),
			'cursor'   => $cursor,
			'done'     => $done,
			'units'    => count( $rows ),
		);
	}

	/**
	 * @param array<string,mixed> $cursor İmleç.
	 * @return array<int,array{0:string,1:int,2:array<string,mixed>}>
	 */
	private function drain( array $cursor ): array {
		$out = array();
		foreach ( (array) $cursor['bad'] as $id => $list ) {
			$out[] = array( 'post', (int) $id, $this->found( $list ) );
		}
		return $out;
	}
}
