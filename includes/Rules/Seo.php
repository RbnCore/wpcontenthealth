<?php
/**
 * B grubu: meta ve SEO kuralları.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Rules;

use ContentHealth\Ctx;
use ContentHealth\Rule;
use ContentHealth\Seo;
use ContentHealth\Text;

defined( 'ABSPATH' ) || exit;

abstract class SeoRule extends Rule {
	public function group(): string {
		return 'seo';
	}

	/** Kuralın uygulanacağı yazı türleri (boş: taranan tümü). */
	protected function applies( object $obj ): bool {
		$types = (array) ( $this->config()['post_types'] ?? array() );
		return empty( $types ) || in_array( $obj->post_type, $types, true );
	}
}

final class MissingMetaDescription extends SeoRule {
	public function id(): string {
		return 'missing_meta_description';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'No meta description', 'contenthealth' );
	}
	public function description(): string {
		return __( 'The description field of the SEO plugin (WPSeoKit, Yoast, Rank Math, AIOSEO) and the excerpt are both empty. If there is no plugin, the excerpt is read.', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'          => true,
			'fallback_excerpt' => true,
			'post_types'       => array(),
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		if ( ! $this->applies( $obj ) ) {
			return null;
		}
		$d = Seo::description( $obj->id, $obj->excerpt, ! empty( $this->config()['fallback_excerpt'] ) );
		return '' === $d[0] ? array( 'seo_plugin' => Seo::active_plugin() ) : null;
	}
}

final class MetaDescriptionLength extends SeoRule {
	public function id(): string {
		return 'meta_description_length';
	}
	public function severity(): string {
		return Rule::INFO;
	}
	public function label(): string {
		return __( 'Meta description length', 'contenthealth' );
	}
	public function description(): string {
		return __( 'A filled description is outside the lower or upper limit (default 70–160 characters).', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'          => true,
			'min'              => 70,
			'max'              => 160,
			'fallback_excerpt' => false,
			'post_types'       => array(),
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		if ( ! $this->applies( $obj ) ) {
			return null;
		}
		$c = $this->config();
		$d = Seo::description( $obj->id, $obj->excerpt, ! empty( $c['fallback_excerpt'] ) );
		if ( '' === $d[0] ) {
			return null;
		}
		$len = Text::len( $d[0] );
		if ( $len < (int) $c['min'] || $len > (int) $c['max'] ) {
			return array(
				'length' => $len,
				'min'    => (int) $c['min'],
				'max'    => (int) $c['max'],
				'source' => $d[1],
			);
		}
		return null;
	}
}

final class DuplicateTitle extends SeoRule {
	public function id(): string {
		return 'duplicate_title';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Duplicate title', 'contenthealth' );
	}
	public function description(): string {
		return __( 'An identical title within the same post type, ignoring case differences.', 'contenthealth' );
	}
	public function scopes(): array {
		return array( 'global' );
	}

	public function scan_batch( array $cursor, int $limit, Ctx $ctx ): array {
		global $wpdb;
		$types    = $ctx->post_types();
		$statuses = $ctx->statuses();
		if ( ! $types || ! $statuses ) {
			return parent::scan_batch( $cursor, $limit, $ctx );
		}
		$ph_t = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ph_s = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- yer tutucu listesi sabit üretildi, değerler prepare ile bağlanır.
		$sql  = "SELECT post_type, LOWER(post_title) AS t, GROUP_CONCAT(ID ORDER BY ID) AS ids, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type IN ({$ph_t}) AND post_status IN ({$ph_s}) AND post_title <> '' GROUP BY post_type, LOWER(post_title) HAVING c > 1";
		$rows = \AdminKit\Helpers\Db::get_results( $sql, array( array_merge( $types, $statuses ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$ids = array_map( 'intval', explode( ',', (string) $r->ids ) );
			foreach ( $ids as $id ) {
				$out[] = array(
					'post',
					$id,
					array(
						'title'  => Text::snippet( (string) $r->t, 100 ),
						'others' => array_values( array_diff( $ids, array( $id ) ) ),
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

final class TitleTooLong extends SeoRule {
	public function id(): string {
		return 'title_too_long';
	}
	public function severity(): string {
		return Rule::INFO;
	}
	public function label(): string {
		return __( 'Title too long', 'contenthealth' );
	}
	public function description(): string {
		return __( 'The post title exceeds the set character limit (default 70).', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'    => true,
			'max'        => 70,
			'post_types' => array(),
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		$len = Text::len( trim( $obj->title ) );
		return ( $this->applies( $obj ) && $len > (int) $this->config()['max'] ) ? array(
			'length' => $len,
			'max'    => (int) $this->config()['max'],
		) : null;
	}
}

final class MissingFeaturedImage extends SeoRule {
	public function id(): string {
		return 'missing_featured_image';
	}
	public function severity(): string {
		return Rule::INFO;
	}
	public function label(): string {
		return __( 'No featured image', 'contenthealth' );
	}
	public function description(): string {
		return __( 'No featured image is assigned to a post of a supported type (default: post).', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'    => true,
			'post_types' => array( 'post' ),
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		if ( ! $this->applies( $obj ) || ! post_type_supports( $obj->post_type, 'thumbnail' ) ) {
			return null;
		}
		return (int) get_post_meta( $obj->id, '_thumbnail_id', true ) > 0 ? null : array( 'thumbnail' => 0 );
	}
}

final class ThinContent extends SeoRule {
	public function id(): string {
		return 'thin_content';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Thin content', 'contenthealth' );
	}
	public function description(): string {
		return __( 'The word count of the text is below the threshold (default 200).', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'    => true,
			'min_words'  => 200,
			'post_types' => array( 'post' ),
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		if ( ! $this->applies( $obj ) ) {
			return null;
		}
		$w = $obj->words();
		return $w < (int) $this->config()['min_words'] ? array(
			'words' => $w,
			'min'   => (int) $this->config()['min_words'],
		) : null;
	}
}

final class MultipleH1 extends SeoRule {
	public function id(): string {
		return 'multiple_h1';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Multiple H1', 'contenthealth' );
	}
	public function description(): string {
		return __( 'The content has more than one <h1> (the theme title is not counted separately).', 'contenthealth' );
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		$n = 0;
		foreach ( $obj->headings() as $h ) {
			if ( 1 === $h[0] ) {
				++$n;
			}
		}
		return $n > 1 ? array( 'h1_count' => $n ) : null;
	}
}

final class HeadingHierarchy extends SeoRule {
	public function id(): string {
		return 'heading_hierarchy';
	}
	public function severity(): string {
		return Rule::INFO;
	}
	public function label(): string {
		return __( 'Heading hierarchy', 'contenthealth' );
	}
	public function description(): string {
		return __( 'A heading level is skipped (e.g. H4 directly after H2).', 'contenthealth' );
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		$prev  = 0;
		$jumps = array();
		foreach ( $obj->headings() as $h ) {
			if ( $prev && $h[0] > $prev + 1 ) {
				$jumps[] = array(
					'from' => 'h' . $prev,
					'to'   => 'h' . $h[0],
					'text' => Text::snippet( $h[1], 60 ),
				);
			}
			$prev = $h[0];
		}
		return $jumps ? $this->found( $jumps ) : null;
	}
}
