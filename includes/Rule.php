<?php
/**
 * Kural taban sınıfı.
 *
 * Nesne bazlı kural: scopes() + check(). Küme bazlı (global) kural: scope 'global' + scan_batch().
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

abstract class Rule {

	public const CRITICAL = 'critical';
	public const WARNING  = 'warning';
	public const INFO     = 'info';

	abstract public function id(): string;

	/** content | seo | media | links | taxonomy */
	abstract public function group(): string;

	abstract public function severity(): string;

	abstract public function label(): string;

	abstract public function description(): string;

	/** @return array<int,string> post | attachment | term | global */
	public function scopes(): array {
		return array( 'post' );
	}

	/** Bu kural için sunulan düzeltme eylemi (yoksa null = yalnız elle). */
	public function fix_action(): ?string {
		return null;
	}

	/** @return array<string,mixed> */
	public function defaults(): array {
		return array( 'enabled' => true );
	}

	/** @return array<string,mixed> */
	public function config(): array {
		return Settings::rule( $this->id() );
	}

	public function enabled(): bool {
		return ! empty( $this->config()['enabled'] );
	}

	/**
	 * Nesne denetimi.
	 *
	 * @param object $obj PostView | stdClass (terim / ek).
	 * @return array<string,mixed>|null Bulgu ayrıntısı; sağlamsa null.
	 */
	public function check( object $obj, Ctx $ctx ): ?array {
		return null;
	}

	/**
	 * Küme bazlı kural partisi.
	 *
	 * @param array<string,mixed> $cursor Kalıcı imleç.
	 * @return array{findings:array<int,array{0:string,1:int,2:array<string,mixed>}>,cursor:array<string,mixed>,done:bool,units:int}
	 */
	public function scan_batch( array $cursor, int $limit, Ctx $ctx ): array {
		return array(
			'findings' => array(),
			'cursor'   => $cursor,
			'done'     => true,
			'units'    => 0,
		);
	}

	/**
	 * Düzeltme: Fixer'a devreder.
	 *
	 * @param array<string,mixed> $opts Eylem seçenekleri.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function fix( object $issue, bool $dry_run, array $opts = array() ) {
		$action = (string) ( $opts['fix'] ?? $this->fix_action() );
		if ( '' === $action ) {
			return new \WP_Error( 'no_fix', __( 'There is no automatic fix for this rule.', 'contenthealth' ) );
		}
		return Fixer::run( $action, $issue, $dry_run, $opts );
	}

	/**
	 * Kuralın ayar şeması (motor biçimi): `defaults()` bir SÖZLEŞMEDİR, tür buradan türetilir.
	 * Temizleme/doğrulama AdminKit ayar motorundadır; kural sınıfı yalnız alan bildirir.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	public function settings_schema(): array {
		$schema = array(
			'enabled' => array(
				'type'     => 'bool',
				'default'  => true,
				'portable' => true,
			),
		);
		foreach ( $this->defaults() as $key => $default ) {
			if ( 'enabled' !== $key ) {
				$schema[ (string) $key ] = self::field_schema( (string) $key, $default );
			}
		}
		return $schema;
	}

	/**
	 * Bir varsayılan değerin şema satırı.
	 *
	 * @param string $key     Alan adı (desen alanları ayrı temizlenir).
	 * @param mixed  $default Varsayılan.
	 * @return array<string,mixed>
	 */
	public static function field_schema( string $key, $default ): array {
		// Kural ayarları siteye bağlı değildir (eşik/desen): ayar dosyasıyla başka siteye taşınır (AdminKit).
		return self::field_row( $key, $default ) + array( 'portable' => true );
	}

	/**
	 * @param string $key     Alan adı.
	 * @param mixed  $default Varsayılan.
	 * @return array<string,mixed>
	 */
	private static function field_row( string $key, $default ): array {
		if ( is_bool( $default ) ) {
			return array(
				'type'    => 'bool',
				'default' => $default,
			);
		}
		if ( is_int( $default ) ) {
			return array(
				'type'    => 'int',
				'default' => $default,
				'min'     => 0,
				'max'     => 100000,
			);
		}
		if ( is_array( $default ) ) {
			$is_pattern = self::is_pattern_key( $key );
			$row        = array(
				'type'        => 'list',
				'default'     => array_map( 'strval', array_values( $default ) ),
				// Desen alanları kullanıcı regex'idir: ham metin (etiket sıyırma yok), yalnız satır ayracı.
				'key'         => $is_pattern ? 'raw' : 'text',
				'split'       => $is_pattern ? 'lines' : 'both',
				'max_len'     => Patterns::MAX_LEN,
				'max_entries' => 100,
			);
			if ( $is_pattern ) {
				$row['clean'] = array( self::class, 'clean_patterns' );
			}
			return $row;
		}
		return array(
			'type'    => 'text',
			'default' => (string) $default,
		);
	}

	/** Desen listesi alanı mı (`patterns`, `patterns_head`, `pat_x`)? */
	public static function is_pattern_key( string $key ): bool {
		return 'patterns' === substr( $key, 0, 8 ) || 'pat_' === substr( $key, 0, 4 );
	}

	/**
	 * Desen listesi temizleyicisi (motor `clean` kancası): geçersiz/çok yavaş desen atılır, neden hata olarak döner.
	 *
	 * @param mixed                $value  Motorun temizlediği liste.
	 * @param mixed                $old    Eski değer.
	 * @param string               $path   Alan yolu.
	 * @param array<string,string> $errors Toplanan hatalar (yol => ileti).
	 * @return array<int,string>
	 */
	public static function clean_patterns( $value, $old, string $path, array &$errors ): array {
		$check = Patterns::validate_list( array_values( array_map( 'strval', (array) $value ) ) );
		foreach ( $check['errors'] as $message ) {
			$errors[ $path ] = (string) $message;
		}
		return array_values( array_map( 'strval', $check['valid'] ) );
	}

	/** Kısa yardımcı: bulgu ayrıntısı biçimi. */
	protected function found( array $items, array $extra = array() ): array {
		return array_merge(
			array(
				'count' => count( $items ),
				'items' => array_slice( $items, 0, 8 ),
			),
			$extra
		);
	}
}
