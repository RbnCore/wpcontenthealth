# ContentHealth — Mimari (0.1.1)

ContentHealth, WordPress "Site Sağlığı" ekranının **içerik** karşılığıdır: yazı, etiket ve görsel içeriğindeki kalıntıları bulur, güvenli ve geri alınabilir biçimde düzeltir. Film/dizi'den bağımsızdır; yalnız çekirdek WordPress API'sini kullanır.

## 1. Modül yapısı

| Modül | Dosya | İçerik |
|---|---|---|
| Core | `includes/Modules/Core.php` | İçerik temizliği (A), SEO (B), bağlantı (D) kuralları |
| Taxonomy | `includes/Modules/Taxonomy.php` | Terim kuralları (E), **Etiket Doktoru** sekmesi, `wp_robots` noindex |
| Media | `includes/Modules/Media.php` | Görsel kuralları (C), **Alt Metin** sekmesi |

İleride eklenecek modüller (yer ayrıldı, 0.1.1'de yok): `Redirects` (301 yönetimi; şu an yalnız `contenthealth_term_redirects` option'ı ayrılmıştır), `Counter`, `Gate`.

**Modül arayüzü** (`includes/Module.php`):

```php
interface Module {
    public function register(): void;      // kanca kaydı (yalnız gerekliyse)
    public function settings(): array;     // varsayılan ayarlar, Settings'e birleşir
    public function admin_pages(): array;  // ek sekmeler: slug => [label, render]
    public function cli(): array;          // ek CLI alt komutları
    public function rules(): array;        // Rule örnekleri
}
```

`Plugin::boot()` modülleri yükler, `Registry` kuralları toplar.

## 2. Rule API (`includes/Rule.php`)

Her kural soyut `Rule` sınıfından türer:

- `id()` — kalıcı kimlik (`ai_ui_artifacts`), `group()` — `content|seo|media|links|taxonomy`
- `severity()` — `critical` (kritik) | `warning` (uyarı) | `info` (bilgi)
- `label()`, `description()` — Türkçe, gettext
- `defaults()` — ayarlanabilir eşik/desenler; `enabled` zorunlu
- `check($obj, $ctx)` — tek nesne; bulgu dizisi ya da `null`
- `scan_batch($cursor, $limit, $ctx)` — **genel** kurallar için (tüm tabloyu görmesi gerekenler: yinelenen başlık, yetim içerik, yinelenen terim)
- `fix_action()` / `fix($issue, $dry_run, $opts)` — otomatik düzeltme (yoksa `null`)

Bağlam `Ctx` (ayarlar, site adresi, SEO eklentisi) ve tembel `PostView` (düz metin, başlıklar, bağlantılar yalnız bir kural isterse hesaplanır) ile paylaşılır; bu yüzden 30+ kural tek geçişte çalışır.

## 3. Veri modeli

Tablolar `{prefix}contenthealth_*` (dbDelta, `contenthealth_db_version` ile sürümlü, iki kez koşması güvenli):

- **runs**: `id, status, trigger, scope_json, cursor_json, state_json, stats_json, total, done, score, started_at, finished_at`
- **issues**: `id, rule_id, severity, object_type, object_id, status(open|ignored|fixed), details, first_seen, last_seen, run_id` — `UNIQUE(object_type, object_id, rule_id)`; yeniden tarama `ON DUPLICATE KEY UPDATE` ile günceller, **yoksayılan bulgu açılmaz**, düzeltilmiş bulgu yeniden görülürse açılır.
- **undo**: `id, batch_id, action, object_type, object_id, field, before_value, after_value, after_hash, issue_id, user_id, created_at, undone_at`

Option'lar: `contenthealth_settings` (tek option, tüm ayarlar), `contenthealth_db_version`, `contenthealth_noindex_terms`, `contenthealth_term_redirects`.

## 4. Taramalar (partiler)

- **Kalıcı imleç**: her koşunun aşaması (`post` → `term` → `global:<kural>`) ve son kimliği `runs.cursor_json`'dadır; sunucu kesilse bile kalınan yerden sürer.
- **Zaman bütçesi**: bir adım varsayılan 5 sn (ayar: 1–20) ya da parti büyüklüğü (varsayılan 100) dolunca biter; MySQL `GET_LOCK` aynı koşunun eşzamanlı iki adımını engeller.
- **Tetikleyiciler**: yönetim ekranı AJAX (REST `scan` adımları), WP-Cron (`hourly|daily|contenthealth_weekly`, uzun koşu `contenthealth_cron_continue` ile 10 sn arayla sürer), WP-CLI (bitene dek döngü).
- **Koşu yönetimi**: başlat / duraklat / devam / iptal. 900 sn hareketsiz koşu "terk edilmiş" sayılıp iptal edilir. Aktif koşu varken yenisi başlamaz (çakışma kilidi).
- Aşama bitince görülmeyen açık bulgular `fixed` olur (`Issues::sweep`); kısmi taramada (`--limit`, `--post-type`) yazı süpürmesi **atlanır**, böylece kapsam dışı bulgu yanlışlıkla kapanmaz.

## 5. Puanlama (0–100)

`ceza(kural) = min(tavan, ağırlık × etkilenen_nesne_yüzdesi)`; tavan kritik 15 / uyarı 8 / bilgi 3, yüzde başına ağırlık 1.5 / 0.8 / 0.3. `puan = 100 − Σ ceza` (alt sınır 0). Payda yazı kuralları için taranan yazı sayısı, terim kuralları için taranan terim sayısıdır. Böylece büyük sitede tek hata puanı çökertmez, yaygın sorun çökertir.

## 6. Düzeltmeler ve geri alma

1. **Kuru koşu**: `dry_run=true` hiçbir şey yazmaz; önce/sonra farkını döner. REST ve ekranda **varsayılan kuru koşudur**.
2. **Onay**: ekranda "Uygula" yalnız kuru koşudan sonra açılır ve tarayıcı onayı ister.
3. **Uygulama**: `$wpdb->update` ile doğrudan yazılır (`wp_update_post` yok): revizyon üretilmez, kanca zinciri tetiklenmez. `post_modified` **değişmez** (ayar `keep_modified`, varsayılan açık).
4. **UNDO günlüğü**: her alan için önceki/sonraki değer ve `after_hash` (SHA‑256) yazılır. Geri alırken mevcut değerin hash'i `after_hash` ile aynıysa geri yazılır; sonradan elle düzenlenmişse **atlanır** ve uyarılır.
5. Terim silme/birleştirme tam anlık görüntüyle (terim, sınıflandırma, meta, taşınan/çift ilişkiler) geri alınır.

## 7. Güvenlik

- Tüm REST uçları `manage_options`; tarayıcıda çerez + `X-WP-Nonce`. Nonce'suz istek 401/403.
- Yönetim POST'ları `check_admin_referer` + yetki; çıktı `esc_*`; girdi `sanitize_*`/`absint`.
- Kullanıcı desenleri `Patterns` ile derlenir: iç içe niceleyici (ReDoS), geçersiz sözdizimi ve aşırı uzunluk **kaydedilmez**; çalışma anında `pcre.backtrack_limit` düşürülür, hata varsa eşleşme "yok" sayılır.
- SQL: tümü `$wpdb->prepare` / `$wpdb->update`; ham kullanıcı girdisi sorguya girmez.
- CSV dışa aktarma formül enjeksiyonuna karşı (`=,+,-,@`) önek koyar.
- Ortam değişkeni, sır, kişisel veri yok; günlük yalnız `WP_DEBUG_LOG` açıkken kısa satır yazar.
- Dış istek yalnız "Kırık iç bağlantı" kuralıyla (varsayılan kapalı, ≤2 istek/sn, yalnız kendi site adresi).

## 8. Ön yüz sıfır yük

Ön yüzde yalnız ana dosyadaki `cron_schedules`, `rest_api_init` ve `wp_robots` (**yalnız** noindex listesi doluysa) kaydedilir. CSS/JS yalnız `tools_page_contenthealth` ekranında yüklenir. Ön yüzde tablo sorgusu, betik, stil, çıktı yoktur (kanıt: eklenti açık/kapalı ön yüz çıktısı bayt‑bayt aynı).

## 9. Kaldırma

`uninstall.php` yalnız "Kaldırırken sil" ayarı açıksa tabloları ve option'ları siler; varsayılan olarak veri korunur. Devre dışı bırakma yalnız cron'u temizler.

## 10. i18n ve uyumluluk

Metin alanı `contenthealth`, kaynak dil Türkçe (gettext, `languages/`). SEO eklentisi açıklama alanları okunur: Yoast `_yoast_wpseo_metadesc`, Rank Math `rank_math_description`, AIOSEO `_aioseo_description`. Tek terim kaydı noindex'i SEO eklentisi açıkken **uygulanmaz** (çakışmamak için talimat verilir). WPML/Polylang bu sürümün kapsamı dışındadır.
