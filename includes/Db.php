<?php
/**
 * Tablolar ve geçiş sürümü. dbDelta ile eklemeli ve tekrar çalıştırılabilir.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Db {

	public const VERSION = '1';

	public static function table( string $name ): string {
		return \AdminKit\Helpers\Db::table( 'contenthealth', $name );
	}

	public static function activate(): void {
		self::install();
		Cron::schedule();
	}

	public static function maybe_upgrade(): void {
		\AdminKit\Helpers\Db::maybe_upgrade( 'contenthealth_db_version', self::VERSION, array( self::class, 'install' ) );
	}

	public static function install(): void {
		$runs   = self::table( 'runs' );
		$issues = self::table( 'issues' );
		$undo   = self::table( 'undo' );

		\AdminKit\Helpers\Db::install(
			array(
				"CREATE TABLE {$runs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'running',
  trigger_type varchar(20) NOT NULL DEFAULT 'manual',
  scope_json longtext,
  cursor_json longtext,
  state_json longtext,
  total bigint(20) unsigned NOT NULL DEFAULT 0,
  done bigint(20) unsigned NOT NULL DEFAULT 0,
  score smallint(6) DEFAULT NULL,
  stats_json longtext,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  started_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) %s;",
				"CREATE TABLE {$issues} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(20) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  rule_id varchar(64) NOT NULL,
  group_id varchar(20) NOT NULL DEFAULT '',
  severity varchar(10) NOT NULL DEFAULT 'warning',
  details longtext,
  status varchar(12) NOT NULL DEFAULT 'open',
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  run_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY obj_rule (object_type,object_id,rule_id),
  KEY status_sev (status,severity),
  KEY rule_status (rule_id,status),
  KEY run_id (run_id)
) %s;",
				"CREATE TABLE {$undo} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id varchar(32) NOT NULL,
  action varchar(40) NOT NULL,
  object_type varchar(20) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  field varchar(80) NOT NULL,
  before_value longtext,
  after_value longtext,
  before_hash char(64) NOT NULL DEFAULT '',
  after_hash char(64) NOT NULL DEFAULT '',
  issue_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  undone_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY batch_id (batch_id),
  KEY object (object_type,object_id)
) %s;",
			),
			'contenthealth_db_version',
			self::VERSION,
			false
		);
	}

	/** Kaldırma: tüm tablolar ve seçenekler. */
	public static function drop_all(): void {
		\AdminKit\Helpers\Db::drop_tables(
			array(
				self::table( 'runs' ),
				self::table( 'issues' ),
				self::table( 'undo' ),
			)
		);
		foreach ( array( 'contenthealth_settings', 'contenthealth_db_version', 'contenthealth_noindex_terms', 'contenthealth_term_redirects', 'contenthealth_onboarded' ) as $o ) {
			delete_option( $o );
		}
	}
}
