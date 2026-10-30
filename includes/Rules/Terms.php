<?php
/**
 * E grubu: etiket ve kategori (terim) kuralları.
 *
 * Terim nesnesi: stdClass { id, term_id, name, slug, taxonomy, count, type='term' }.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Rules;

use ContentHealth\Ctx;
use ContentHealth\Patterns;
use ContentHealth\Rule;
use ContentHealth\Seo;
use ContentHealth\Text;

defined( 'ABSPATH' ) || exit;

abstract class TermRule extends Rule {
	public function group(): string {
		return 'taxonomy';
	}
	public function scopes(): array {
		return array( 'term' );
	}
}

final class TermEmpty extends TermRule {
	public function id(): string {
		return 'term_empty';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Empty term', 'contenthealth' );
	}
	public function description(): string {
		return __( 'A tag or category not attached to any content.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_term_delete_empty';
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		return 0 === (int) $obj->count ? array( 'count' => 0 ) : null;
	}
}

final class TermTooLong extends TermRule {
	public function id(): string {
		return 'term_too_long';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Very long term', 'contenthealth' );
	}
	public function description(): string {
		return __( 'The tag name exceeds the character or word limit; usually a sentence or paragraph was entered as a tag.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_term_rename';
	}
	public function defaults(): array {
		return array(
			'enabled'   => true,
			'max_chars' => 60,
			'max_words' => 6,
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		$c     = $this->config();
		$len   = Text::len( $obj->name );
		$words = Text::words( $obj->name );
		if ( $len > (int) $c['max_chars'] || $words > (int) $c['max_words'] ) {
			return array(
				'chars' => $len,
				'words' => $words,
				'name'  => Text::snippet( $obj->name, 100 ),
			);
		}
		return null;
	}
}

final class TermSingleUse extends TermRule {
	public function id(): string {
		return 'term_single_use';
	}
	public function severity(): string {
		return Rule::INFO;
	}
	public function label(): string {
		return __( 'Single-use term', 'contenthealth' );
	}
	public function description(): string {
		return __( 'A tag attached to only one post; its archive page creates thin content. Informational only.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_term_noindex';
	}
	public function defaults(): array {
		return array(
			'enabled'    => true,
			'taxonomies' => array( 'post_tag' ),
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		if ( 1 !== (int) $obj->count || ! in_array( $obj->taxonomy, (array) $this->config()['taxonomies'], true ) ) {
			return null;
		}
		// WPSeoKit bu taksonomi arşivini zaten noindex yapıyor: bulgu yok.
		return true === Seo::taxonomy_noindex( $obj->taxonomy ) ? null : array(
			'count'    => 1,
			'taxonomy' => $obj->taxonomy,
		);
	}
}

final class TermAiResidue extends TermRule {
	public function id(): string {
		return 'term_ai_residue';
	}
	public function severity(): string {
		return Rule::CRITICAL;
	}
	public function label(): string {
		return __( 'AI leftover in a term', 'contenthealth' );
	}
	public function description(): string {
		return __( 'The term name contains a chat sentence, a markdown mark or a line break ("Here are the tags:", "**movie**").', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_term_rename';
	}
	public function defaults(): array {
		return array(
			'enabled'  => true,
			'patterns' => array(
				'^(?:İşte|Elbette|Tabii|Tamamdır|Here\s+(?:is|are)|Sure|Certainly)\b',
				'[:：]\s*$',
				'\*\*|__|`|^#{1,6}\s|^\s*[-*•]\s',
				'\b(?:Umarım|I\s+hope|As\s+an\s+AI)\b',
				'[\r\n]',
			),
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		$hit = Patterns::first( (array) $this->config()['patterns'], $obj->name );
		return $hit ? array(
			'name'    => Text::snippet( $obj->name, 100 ),
			'pattern' => $hit[0],
		) : null;
	}
}

final class TermNumericOnly extends TermRule {
	public function id(): string {
		return 'term_numeric_only';
	}
	public function severity(): string {
		return Rule::INFO;
	}
	public function label(): string {
		return __( 'Term made only of digits', 'contenthealth' );
	}
	public function description(): string {
		return __( 'The term name is only a number (e.g. "2019"). Turn the rule off if year tags are intentional.', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'     => true,
			'allow_years' => false,
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		$n = trim( $obj->name );
		if ( ! preg_match( '~^[\d\s.,/-]+$~', $n ) ) {
			return null;
		}
		if ( ! empty( $this->config()['allow_years'] ) && preg_match( '~^(?:19|20)\d{2}$~', $n ) ) {
			return null;
		}
		return array( 'name' => $n );
	}
}

/** Yinelenen / çok benzer terimler (küme kuralı; 4.000 terim < 5 sn). */
final class TermDuplicate extends Rule {
	public function id(): string {
		return 'term_duplicate';
	}
	public function group(): string {
		return 'taxonomy';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Duplicate term', 'contenthealth' );
	}
	public function description(): string {
		return __( 'Terms that are the same apart from case, accents and punctuation, or a single-letter difference (in long names). Merging is recommended.', 'contenthealth' );
	}
	public function scopes(): array {
		return array( 'global' );
	}
	public function fix_action(): ?string {
		return 'fix_term_merge';
	}
	public function defaults(): array {
		return array(
			'enabled'     => true,
			'taxonomies'  => array( 'post_tag' ),
			'fuzzy_min'   => 8,
		);
	}

	public function scan_batch( array $cursor, int $limit, Ctx $ctx ): array {
		global $wpdb;
		$c    = $this->config();
		$tax  = array_values( array_filter( (array) $c['taxonomies'], 'taxonomy_exists' ) );
		$out  = array();
		foreach ( $tax as $tx ) {
			$rows = (array) \AdminKit\Helpers\Db::get_results( "SELECT t.term_id, t.name, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s", array( $tx ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$by   = array();
			foreach ( $rows as $r ) {
				$f = Text::fold( (string) $r->name );
				if ( '' !== $f ) {
					$by[ $f ][] = $r;
				}
			}
			$groups = array();
			foreach ( $by as $f => $list ) {
				if ( count( $list ) > 1 ) {
					$groups[ $f ] = $list;
				}
			}
			// Bulanık eşleşme: aynı uzunluk bandı + aynı ilk 3 karakter kovası, Levenshtein ≤ 1.
			$min     = (int) $c['fuzzy_min'];
			$buckets = array();
			foreach ( array_keys( $by ) as $f ) {
				$f = (string) $f; // Sayısal adlar dizi anahtarında int'e döner.
				if ( mb_strlen( $f ) >= $min ) {
					$buckets[ mb_substr( $f, 0, 3 ) ][] = $f;
				}
			}
			$merged = array();
			foreach ( $buckets as $keys ) {
				$n = count( $keys );
				if ( $n < 2 || $n > 400 ) {
					continue;
				}
				for ( $i = 0; $i < $n; $i++ ) {
					for ( $j = $i + 1; $j < $n; $j++ ) {
						if ( abs( strlen( $keys[ $i ] ) - strlen( $keys[ $j ] ) ) <= 1 && levenshtein( $keys[ $i ], $keys[ $j ] ) <= 1 && preg_replace( '~\D+~', '', $keys[ $i ] ) === preg_replace( '~\D+~', '', $keys[ $j ] ) ) { // Rakamla ayrılan adlar (IMDB 7.1 / 7.3) yineleme değildir.
							$merged[ $keys[ $i ] ][ $keys[ $j ] ] = true;
						}
					}
				}
			}
			foreach ( $merged as $base => $others ) {
				$list = $by[ $base ];
				foreach ( array_keys( $others ) as $o ) {
					$list = array_merge( $list, $by[ $o ] );
				}
				$groups[ 'z:' . $base ] = $list;
			}
			foreach ( $groups as $list ) {
				usort( $list, static fn( $a, $b ) => ( (int) $b->count <=> (int) $a->count ) ?: ( (int) $a->term_id <=> (int) $b->term_id ) );
				$canon = (int) $list[0]->term_id;
				$ids   = array_map( static fn( $r ) => (int) $r->term_id, $list );
				foreach ( $list as $r ) {
					$out[ (int) $r->term_id ] = array(
						'term',
						(int) $r->term_id,
						array(
							'name'      => Text::snippet( (string) $r->name, 80 ),
							'taxonomy'  => $tx,
							'canonical' => $canon,
							'group'     => $ids,
						),
					);
				}
			}
		}
		return array(
			'findings' => array_values( $out ),
			'cursor'   => $cursor,
			'done'     => true,
			'units'    => 0,
		);
	}
}

/** Bir yazıya bağlı terim sayısı eşiği aşıyor (küme kuralı). */
final class TermsPerPostHigh extends Rule {
	public function id(): string {
		return 'terms_per_post_high';
	}
	public function group(): string {
		return 'taxonomy';
	}
	public function severity(): string {
		return Rule::INFO;
	}
	public function label(): string {
		return __( 'Too many terms per post', 'contenthealth' );
	}
	public function description(): string {
		return __( 'More tags than the threshold are attached to one post (default 15).', 'contenthealth' );
	}
	public function scopes(): array {
		return array( 'global' );
	}
	public function defaults(): array {
		return array(
			'enabled'    => true,
			'max'        => 15,
			'taxonomies' => array( 'post_tag' ),
		);
	}
	public function scan_batch( array $cursor, int $limit, Ctx $ctx ): array {
		global $wpdb;
		$c   = $this->config();
		$tax = array_values( array_filter( (array) $c['taxonomies'], 'taxonomy_exists' ) );
		$out = array();
		if ( $tax ) {
			$ph   = implode( ',', array_fill( 0, count( $tax ), '%s' ) );
			$sql  = "SELECT tr.object_id, COUNT(*) AS c FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy IN ({$ph}) AND p.post_status = 'publish' GROUP BY tr.object_id HAVING c > %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) \AdminKit\Helpers\Db::get_results( $sql, array( array_merge( $tax, array( (int) $c['max'] ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $r ) {
				$out[] = array(
					'post',
					(int) $r->object_id,
					array(
						'terms' => (int) $r->c,
						'max'   => (int) $c['max'],
					),
				);
			}
		}
		return array(
			'findings' => $out,
			'cursor'   => $cursor,
			'done'     => true,
			'units'    => 0,
		);
	}
}
