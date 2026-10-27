=== ContentHealth — İçerik Sağlığı ===
Contributors: rbnbilisim
Tags: content health, seo, cleanup, taxonomy, wp-cli
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.5
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

= 0.1.5 =
* Yeni: Yazı kaydedilince yalnız o yazının tek-yazılık kuralları yeniden çalışır. Elle düzeltilen bulgu kendiliğinden "düzeltildi" olur ("Elle düzeltildi" etiketi); hâlâ eşleşen açık kalır; yeni çıkan açılır. Genel (küme) kurallar kayıtta çalışmaz, taramayı bekler.
* Yeni: Ayarlar → Güvenlik → "Kaydedince yeniden denetle" anahtarı (varsayılan açık; kapalıyken eski davranış).
* Yeni: Bulgular listesinde "Son denetim" satırı ve açık bulguda "Yazı sonradan değişti" rozeti.
* Revizyon, otomatik kayıt, içe aktarma ve yetkisiz bağlam denetlenmez; denetim hata verirse kayıt engellenmez.

= 0.1.4 =
* Düzeltme: "Yapay zekâ arayüz kalıntısı" bulgusu `attachment-container` (arama sonucu / medya kabı) için "değişecek bir şey yok" diyordu; düzeltici artık bu kabı da temizler. Başlık metni `<p>` içinde korunur, boş kap kalkar, görsel/bağlantı içeren kapta medya silinmez.
* Düzeltme: sohbet sarmalayıcısı `id` ile işaretliyse (model-response-message-content…), `source-inline-chip-container` gibi ek ekli sınıflar ve iç içe sarmalayıcılar bulunduktan sonra düzeltilmeden kalıyordu; artık temizleniyor.
* Düzeltme: LaTeX kalıntısı kuralı, yapay zekâ arayüz kalıntısıyla aynı `math-inline` izini ikinci kez bildiriyordu ve düzeltemiyordu; yinelenen bulgu kaldırıldı.
* Yeni: `attachment-container youtube` (boş kap) varsayılan desene eklendi. Ayarlardan desen listesini daha önce kaydettiyseniz yeni desen için listeyi varsayılana döndürün.

= 0.1.3 =
* Dokümantasyon `docs/` klasörüne taşındı ve kurulum paketinden çıkarıldı.

= 0.1.2 =
* Yönetim arayüzü yenilendi: Pano, Bulgular, Etiket Doktoru, Alt Metin, Ayarlar ve Geri alma günlüğü; ilk açılış (3 adım), çekmece ayrıntısı, toplu düzeltme sihirbazı (kuru koşu → onay → uygula → geri al), duraklat/iptal/yeniden dene.
* WordPress yönetici renk düzenine uyar; açık ve koyu (Gece yarısı) tema, 375 px'e kadar duyarlı, klavye ve ekran okuyucu erişimi.
* Çevirilere hazır: `languages/` altında .pot ve tr_TR (.po/.mo).
* Çekirdek davranış değişmedi: tarama motoru, 30 kural, geri alma, REST ve WP-CLI aynı; ön yüze etkisi yok.

= 0.1.1 =
* İlk sürüm: tarayıcı, 30 kural, düzeltme + geri alma, Etiket Doktoru, Alt Metin, REST, WP-CLI.
