# ContentHealth — Kural kütüphanesi (0.1.1)

Her kural Ayarlar'dan açılıp kapatılabilir; eşik ve desenler kural altında düzenlenir. "Düzeltme" sütunu otomatik düzeltme eylemidir (hepsi kuru koşu → onay → geri alma günlüğü).

## A. İçerik temizliği

| Kimlik | Ciddiyet | Ad | Düzeltme | Açıklama |
|---|---|---|---|---|
| `ai_chat_residue` | kritik | Yapay zekâ sohbet kalıntısı | `fix_ai_chat_residue` | Yazının ilk ya da son bloğunda kalan sohbet cümleleri ("İşte … :", "Umarım yardımcı olur"). Yalnız ilk/son kısa blok sayılır; gövdedeki "Elbette…" gibi editoryal ifadelere dokunulmaz. |
| `ai_ui_artifacts` | uyarı | Yapay zekâ arayüz kalıntısı | `fix_ai_ui_artifacts` | Sohbet arayüzünden yapıştırılan sarmalayıcı sınıflar ve veri öznitelikleri (data-path-to-node, model-response-message-content, math-inline …). Düzeltme sarmalayıcıyı açar, içeriği korur. |
| `markdown_leftovers` | uyarı | Markdown kalıntısı | `fix_markdown_links` | HTML'e dönüşmemiş **kalın**, ### başlık, kod çiti, [metin](adres) bağlantısı ve "* madde" satırları. Kod blokları sayılmaz. |
| `placeholder_leftovers` | uyarı | Yer tutucu kalıntısı | — | [Karakter 1], {{alan}}, Lorem ipsum ve "…eklenecektir" gibi doldurulmamış şablon metinleri. Otomatik düzeltilmez: neyle doldurulacağı editörün kararıdır. |
| `encoding_garbage` | uyarı | Bozuk karakter kodlaması | — | â€™, Ã¼, ÅŸ, � gibi UTF-8 metnin yanlış çözülmesiyle oluşan karakterler. Otomatik düzeltilmez. |
| `latex_leftovers` | uyarı | LaTeX kalıntısı | `fix_latex_score` | $5.3/10$ gibi dolar ayraçlı sayı/puan ve \frac, \times komutları. |
| `duplicate_paragraphs` | uyarı | Yinelenen paragraf | — | Aynı yazıda birebir tekrar eden uzun paragraflar (kopyala-yapıştır hatası). |
| `empty_headings` | uyarı | Boş başlık | — | İçinde metin olmayan <h1>–<h6> etiketleri. |

## B. Meta ve SEO

| Kimlik | Ciddiyet | Ad | Düzeltme | Açıklama |
|---|---|---|---|---|
| `missing_meta_description` | uyarı | Meta açıklaması yok | — | SEO eklentisinin (Yoast, Rank Math, AIOSEO) açıklama alanı ve özet boş. Eklenti yoksa özet okunur. |
| `meta_description_length` | bilgi | Meta açıklaması uzunluğu | — | Dolu açıklama alt ya da üst sınırın dışında (varsayılan 70–160 karakter). |
| `duplicate_title` | uyarı | Yinelenen başlık | — | Aynı yazı türünde, büyük/küçük harf farkı hariç birebir aynı başlık. |
| `title_too_long` | bilgi | Başlık çok uzun | — | Yazı başlığı ayarlı karakter sınırını aşıyor (varsayılan 70). |
| `missing_featured_image` | bilgi | Öne çıkan görsel yok | — | Öne çıkan görseli desteklenen türde (varsayılan: yazı) görsel atanmamış. |
| `thin_content` | uyarı | İnce içerik | — | Metin kelime sayısı eşiğin altında (varsayılan 200). |
| `multiple_h1` | uyarı | Birden çok H1 | — | İçerikte birden fazla <h1> var (tema başlığı ayrıca sayılmaz). |
| `heading_hierarchy` | bilgi | Başlık hiyerarşisi | — | Başlık düzeyi atlıyor (ör. H2’den sonra doğrudan H4). |

## C. Medya

| Kimlik | Ciddiyet | Ad | Düzeltme | Açıklama |
|---|---|---|---|---|
| `images_missing_alt` | uyarı | Görselde alt metin yok | `fix_alt_from_title` | İçerikteki <img> etiketlerinde alt özniteliği yok ya da boş. Süs görseli (role="presentation") sayılmaz. |
| `broken_image_host` | kritik | Görsel hatalı sunucudan | `fix_bad_host_url` | Görsel adresi geliştirme/staging sunucusuna (.test, localhost), tekrarlı uzantıya ya da bozuk biçime işaret ediyor. |

## D. Bağlantılar

| Kimlik | Ciddiyet | Ad | Düzeltme | Açıklama |
|---|---|---|---|---|
| `bad_host_url` | kritik | Hatalı sunucu adresi | `fix_bad_host_url` | İçerikte geliştirme/staging adresi (.test, .local, localhost), tekrarlı uzantı (.test.test) ya da bozuk biçim (https.alanadi) sızıntısı. Sitenin kendi adresi hariçtir. |
| `mixed_content` | uyarı | Karışık içerik (http) | — | Site https iken içerikte http:// görsel, iframe, betik ya da kendi sitene http bağlantı. |
| `no_internal_links` | bilgi | İç bağlantı yok | — | Yeterince uzun içerikte siteye ait hiçbir bağlantı yok. |
| `orphan_content` | bilgi | Yetim içerik | — | Başka hiçbir yazının içeriğinden bağlantı almayan yazı. Menü, widget ve ilgili yazı kutuları sayılmaz; yalnız içerik içi bağlantılar. |
| `broken_internal_links` | uyarı | Kırık iç bağlantı (yavaş mod) | — | Site içi bağlantılara hız sınırlı (saniyede en çok 2) HEAD isteği atar; 404/410 ve bağlantı hatalarını bulur. Sunucuya yük bindirmemek için varsayılan kapalıdır. |

## E. Etiket ve kategori

| Kimlik | Ciddiyet | Ad | Düzeltme | Açıklama |
|---|---|---|---|---|
| `term_empty` | uyarı | Boş terim | `fix_term_delete_empty` | Hiçbir içeriğe bağlı olmayan etiket ya da kategori. |
| `term_duplicate` | uyarı | Yinelenen terim | `fix_term_merge` | Büyük/küçük harf, aksan ve noktalama farkıyla ya da tek harf farkıyla (uzun adlarda) aynı olan terimler. Birleştirme önerilir. |
| `term_too_long` | uyarı | Çok uzun terim | `fix_term_rename` | Etiket adı karakter ya da kelime sınırını aşıyor; çoğunlukla cümle ya da paragraf etiket olarak girilmiştir. |
| `term_single_use` | bilgi | Tek kullanımlık terim | `fix_term_noindex` | Yalnız bir içeriğe bağlı etiket; arşiv sayfası ince içerik üretir. Yalnız bilgi amaçlıdır. |
| `term_ai_residue` | kritik | Terimde yapay zekâ kalıntısı | `fix_term_rename` | Terim adında sohbet cümlesi, markdown işareti ya da satır sonu bulunuyor ("İşte etiketler:", "**film**"). |
| `term_numeric_only` | bilgi | Yalnız rakamdan oluşan terim | — | Terim adı yalnız sayı (ör. "2019"). Yıl etiketleri bilinçliyse kuralı kapatın. |
| `terms_per_post_high` | bilgi | Yazı başına çok terim | — | Bir yazıya eşikten fazla etiket bağlanmış (varsayılan 15). |

## Notlar

- Yer tutucu kuralı kayıtlı kısa kodları (shortcode) ve köşeli parantezli büyük harf/rakam etiketlerini saymaz.
- Sohbet kalıntısı yalnız yazının ilk/son kısa bloğuna bakar; gövdedeki editoryal cümleye dokunmaz.
- Terim yineleme kuralı rakamla ayrılan adları (ör. 7.1 / 7.3) yineleme saymaz.
- Kullanıcı desenleri ReDoS korumalıdır (bkz. `mimari.md` §7).
