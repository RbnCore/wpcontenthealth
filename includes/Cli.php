<?php
/**
 * WP-CLI: wp contenthealth scan|status|list|fix|undo|export
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Taramayı çalıştırır (bitene dek parti parti).
	 *
	 * ## OPTIONS
	 *
	 * [--rules=<kimlikler>]
	 * : Virgülle ayrılmış kural kimlikleri (varsayılan: açık kuralların tümü).
	 *
	 * [--post-type=<turler>]
	 * : Virgülle ayrılmış yazı türleri.
	 *
	 * [--limit=<n>]
	 * : En çok n yazı tara (kısmi tarama).
	 *
	 * @param array<int,string>   $args  Konumsal.
	 * @param array<string,mixed> $assoc Adlandırılmış.
	 */
	public function scan( array $args, array $assoc ): void {
		$run = Runs::start(
			array(
				'rules'      => isset( $assoc['rules'] ) ? explode( ',', (string) $assoc['rules'] ) : array(),
				'post_types' => isset( $assoc['post-type'] ) ? explode( ',', (string) $assoc['post-type'] ) : array(),
				'limit'      => (int) ( $assoc['limit'] ?? 0 ),
			),
			'cli'
		);
		if ( is_wp_error( $run ) ) {
			\WP_CLI::error( $run->get_error_message() );
		}
		$t0 = microtime( true );
		while ( 'running' === $run->status ) {
			$run = Scanner::step( $run->id, 20.0 );
			if ( is_wp_error( $run ) ) {
				\WP_CLI::error( $run->get_error_message() );
			}
			\WP_CLI::log( sprintf( '%d/%d  %s', $run->done, $run->total, (string) ( $run->state['label'] ?? '' ) ) );
		}
		\WP_CLI::success(
			sprintf(
				'Tarama %s: puan %s, süre %.2f sn, bellek tepe %.1f MB',
				$run->status,
				null === $run->score ? '-' : (string) $run->score,
				microtime( true ) - $t0,
				memory_get_peak_usage( true ) / 1048576
			)
		);
	}

	/**
	 * Puan ve açık bulgu sayılarını yazar.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table | json
	 *
	 * @param array<int,string>   $args  Konumsal.
	 * @param array<string,mixed> $assoc Adlandırılmış.
	 */
	public function status( array $args, array $assoc ): void {
		$s = Rest::summary();
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::print_value( $s, array( 'format' => 'json' ) );
			return;
		}
		\WP_CLI::log( sprintf( 'Puan: %s  (son tarama: %s)', null === $s['score'] ? '-' : (string) $s['score'], (string) ( $s['finished'] ?? '-' ) ) );
		\WP_CLI::log( sprintf( 'Kritik %d · Uyarı %d · Bilgi %d', $s['severity'][ Rule::CRITICAL ], $s['severity'][ Rule::WARNING ], $s['severity'][ Rule::INFO ] ) );
		\WP_CLI\Utils\format_items( 'table', $s['rules'], array( 'id', 'severity', 'open', 'fix' ) );
	}

	/**
	 * Bulguları listeler.
	 *
	 * ## OPTIONS
	 *
	 * [--rule=<kimlik>]
	 * : Tek kural.
	 *
	 * [--status=<durum>]
	 * : open | ignored | fixed (varsayılan open).
	 *
	 * [--format=<format>]
	 * : table | csv | json
	 *
	 * @param array<int,string>   $args  Konumsal.
	 * @param array<string,mixed> $assoc Adlandırılmış.
	 */
	public function list( array $args, array $assoc ): void {
		$rows = self::flat( (string) ( $assoc['rule'] ?? '' ), (string) ( $assoc['status'] ?? 'open' ) );
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'id', 'rule', 'severity', 'type', 'object', 'summary' ) );
	}

	/**
	 * Düzeltme uygular. Varsayılan kuru koşu DEĞİLDİR: --dry-run ile önce bakın.
	 *
	 * ## OPTIONS
	 *
	 * <kural>
	 * : Kural kimliği (ör. ai_chat_residue).
	 *
	 * [--dry-run]
	 * : Yazmadan neyin değişeceğini göster.
	 *
	 * [--ids=<kimlikler>]
	 * : Virgülle ayrılmış nesne kimlikleri (boşsa hepsi).
	 *
	 * [--alt-template=<sablon>]
	 * : Alt metin şablonu.
	 *
	 * @param array<int,string>   $args  Konumsal.
	 * @param array<string,mixed> $assoc Adlandırılmış.
	 */
	public function fix( array $args, array $assoc ): void {
		$ids  = isset( $assoc['ids'] ) ? array_map( 'intval', explode( ',', (string) $assoc['ids'] ) ) : array();
		$opts = isset( $assoc['alt-template'] ) ? array( 'alt_template' => (string) $assoc['alt-template'] ) : array();
		$dry  = ! empty( $assoc['dry-run'] );
		$res  = Fixer::run_rule( (string) ( $args[0] ?? '' ), $ids, $dry, $opts );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		foreach ( array_slice( $res['items'], 0, 20 ) as $it ) {
			\WP_CLI::log( '#' . $it['object_id'] . ' ' . ( isset( $it['error'] ) ? 'HATA: ' . $it['error'] : wp_json_encode( $it['changes'][0]['chars'] ?? $it['changes'][0]['field'] ?? '', JSON_UNESCAPED_UNICODE ) ) );
		}
		\WP_CLI::success( sprintf( '%s: değişen %d, atlanan %d, hata %d%s', $dry ? 'KURU KOŞU' : 'UYGULANDI', $res['changed'], $res['skipped'], $res['errors'], $dry ? '' : ' · geri alma: ' . $res['batch'] ) );
	}

	/**
	 * Düzeltmeyi geri alır.
	 *
	 * ## OPTIONS
	 *
	 * <anahtar>
	 * : Yığın kimliği (fix çıktısındaki "geri alma") ya da tek günlük satırı numarası.
	 *
	 * [--dry-run]
	 * : Yalnız kontrol.
	 *
	 * @param array<int,string>   $args  Konumsal.
	 * @param array<string,mixed> $assoc Adlandırılmış.
	 */
	public function undo( array $args, array $assoc ): void {
		$r = Undo::revert( (string) ( $args[0] ?? '' ), ! empty( $assoc['dry-run'] ) );
		foreach ( $r['messages'] as $m ) {
			\WP_CLI::warning( $m );
		}
		\WP_CLI::success( sprintf( 'Geri alınan %d, atlanan %d', $r['reverted'], $r['skipped'] ) );
	}

	/**
	 * Bulguları CSV dosyasına yazar.
	 *
	 * ## OPTIONS
	 *
	 * [<dosya>]
	 * : Hedef dosya (boşsa ekrana).
	 *
	 * @param array<int,string>   $args  Konumsal.
	 * @param array<string,mixed> $assoc Adlandırılmış.
	 */
	public function export( array $args, array $assoc ): void {
		$rows = self::flat( '', (string) ( $assoc['status'] ?? 'open' ) );
		$fh   = isset( $args[0] ) ? fopen( $args[0], 'w' ) : fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $fh, array( 'id', 'rule', 'severity', 'type', 'object', 'summary' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		foreach ( $rows as $r ) {
			fputcsv( $fh, $r ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( isset( $args[0] ) ) {
			\WP_CLI::success( count( $rows ) . ' bulgu yazıldı.' );
		}
	}

	/** @return array<int,array<string,mixed>> */
	private static function flat( string $rule, string $status ): array {
		$out  = array();
		$page = 1;
		do {
			$res = Issues::query(
				array(
					'rule'     => $rule,
					'status'   => $status,
					'per_page' => 500,
					'page'     => $page,
					'orderby'  => 'id',
					'order'    => 'asc',
				)
			);
			foreach ( $res['rows'] as $r ) {
				$out[] = array(
					'id'       => $r->id,
					'rule'     => $r->rule_id,
					'severity' => $r->severity,
					'type'     => $r->object_type,
					'object'   => $r->object_id,
					'summary'  => Text::snippet( (string) wp_json_encode( $r->details_arr, JSON_UNESCAPED_UNICODE ), 120 ),
				);
			}
			++$page;
		} while ( $res['rows'] && count( $out ) < $res['total'] );
		return $out;
	}
}
