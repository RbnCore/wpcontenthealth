<?php
/**
 * Modül arayüzü: çekirdek modüle yalnız bu kapılardan konuşur.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

interface Module {

	/** Kanca kaydı (yalnız yönetim/cron/REST/CLI bağlamında çağrılır). */
	public function register(): void;

	/** @return array<string,mixed> Modülün varsayılan ayarları (Settings::defaults'a eklenir). */
	public function settings(): array;

	/** @return array<string,array{label:string,render:callable}> Yönetim ekranı sekmeleri. */
	public function admin_pages(): array;

	/** @return array<string,callable> Ek WP-CLI alt komutları (şimdilik boş olabilir). */
	public function cli(): array;

	/** @return array<int,Rule> Modülün kuralları. */
	public function rules(): array;
}
