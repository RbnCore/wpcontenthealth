=== ContentHealth — İçerik Sağlığı ===
Contributors: rbnbilisim
Tags: content health, seo, cleanup, taxonomy, wp-cli
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bağlantı: https://github.com/RbnCore

WordPress "Site Sağlığı" gibi İÇERİK sağlığı paneli: yapay zekâ/arayüz kalıntısı, markdown, bozuk adres, boş ve yinelenen etiket, eksik alt metin. Kuru koşu, onay ve geri alma ile güvenli düzeltme.

== Description ==

* 30 kural: içerik temizliği, SEO, medya, bağlantı, etiket/kategori. Her biri Ayarlar'dan açılıp kapatılır.
* Sağlık puanı (0–100), kritik/uyarı/bilgi sayıları, süzülebilir ve aranabilir bulgu listesi, CSV dışa aktarma.
* Parti parti, kalıcı imleçli tarama: büyük sitede de sunucuyu yormaz (AJAX, WP-Cron, WP-CLI).
* Düzeltmeler önce kuru koşu ile fark gösterir, onayla uygulanır, geri alma günlüğüne yazılır. "Son değiştirilme" tarihi ve revizyonlar değişmez.
* Etiket Doktoru: boş etiketi silme, yinelenenleri birleştirme, uzun adı kısaltma, tek kullanımlıkları noindex.
* Alt Metin: eksik alt metni şablondan doldurma.
* Yoast, Rank Math ve AIOSEO meta açıklama alanlarıyla uyumlu.
* Ön yüzde sıfır yük: betik, stil, sorgu ve çıktı eklemez. Dış servis çağırmaz.
* WP-CLI: `wp contenthealth scan|status|list|fix|undo|export`.

== Installation ==

1. Klasörü `wp-content/plugins/` altına yükleyin ve etkinleştirin.
2. Araçlar → ContentHealth → "Şimdi tara".

== Frequently Asked Questions ==

= Ön yüzü yavaşlatır mı? =
Hayır. Ön yüzde yalnızca (varsa) noindex listesi için bir `wp_robots` süzgeci çalışır.

= Düzeltme geri alınabilir mi? =
Evet. Her uygulama önceki değeri ve SHA-256 özetini günlüğe yazar; elle değiştirilmiş içerik atlanır.

= Kaldırınca veri silinir mi? =
Varsayılan olarak silinmez. Ayarlardan "Kaldırırken sil" seçilirse tablolar ve ayarlar silinir.

== Changelog ==

= 0.1.1 =
* İlk sürüm: tarayıcı, 30 kural, düzeltme + geri alma, Etiket Doktoru, Alt Metin, REST, WP-CLI.
