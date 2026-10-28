<?php
/**
 * Kural kayıt defteri: modüllerin kurallarını toplar.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @var array<string,Rule>|null */
	private static ?array $rules = null;

	/** @return array<string,Rule> kural kimliği → kural */
	public static function rules(): array {
		if ( null === self::$rules ) {
			self::$rules = array();
			foreach ( Plugin::modules() as $module ) {
				foreach ( $module->rules() as $rule ) {
					self::$rules[ $rule->id() ] = $rule;
				}
			}
		}
		return self::$rules;
	}

	public static function get( string $id ): ?Rule {
		return self::rules()[ $id ] ?? null;
	}

	/**
	 * Açık kurallar, kapsama göre.
	 *
	 * @param array<int,string> $only Boşsa hepsi; doluysa yalnız bu kimlikler.
	 * @return array<string,Rule>
	 */
	public static function enabled( array $only = array() ): array {
		$out = array();
		foreach ( self::rules() as $id => $rule ) {
			if ( ( empty( $only ) || in_array( $id, $only, true ) ) && $rule->enabled() ) {
				$out[ $id ] = $rule;
			}
		}
		return $out;
	}

	/** @return array<string,string> */
	public static function groups(): array {
		return array(
			'content'  => __( 'Content cleanup', 'contenthealth' ),
			'seo'      => __( 'Meta and SEO', 'contenthealth' ),
			'media'    => __( 'Media', 'contenthealth' ),
			'links'    => __( 'Links', 'contenthealth' ),
			'taxonomy' => __( 'Tag and category', 'contenthealth' ),
		);
	}

	/** @return array<string,string> */
	public static function severities(): array {
		return array(
			Rule::CRITICAL => __( 'Critical', 'contenthealth' ),
			Rule::WARNING  => __( 'Warning', 'contenthealth' ),
			Rule::INFO     => __( 'Info', 'contenthealth' ),
		);
	}
}
