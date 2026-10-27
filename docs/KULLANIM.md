# ContentHealth — Kullanım

**Araçlar → ContentHealth** (yönetici yetkisi gerekir).

## Sekmeler

| Sekme | Ne yapar |
|---|---|
| **Özet** | Sağlık puanı (0–100), kritik/uyarı/bilgi sayıları, en çok bulgusu olan kurallar, **Şimdi tara** düğmesi (AJAX, ilerleme çubuğu, iptal) |
| **Bulgular** | Tüm bulgular: kural/ciddiyet/durum süzgeci, arama, sıralama, toplu **Yoksay / Yeniden aç**, CSV indirme. Satıra tıklayınca ayrıntı ve önerilen düzeltmenin **farkı** (silinecek `−`, eklenecek `+`) |
| **Etiket Doktoru** | Boş, yinelenen, aşırı uzun terimler; birleştirme, silme, yeniden adlandırma, noindex |
| **Alt Metin** | Alt metni olmayan görsellere şablondan (`{başlık}`, `{dosya_adı}`, `{site_adı}`) alt metin |
| **Ayarlar** | Tarama sıklığı, parti süresi/büyüklüğü, taranan durumlar, dışlanan türler, kural açma/kapama ve eşikleri |
| **Geri alma günlüğü** | Uygulanan her düzeltme yığını ve **Geri al** düğmesi |

## Güvenli düzeltme akışı

1. Panelde **Kuru koşu**: hiçbir şey yazılmaz; kaç nesne değişeceği ve fark gösterilir.
2. Sonuç uygunsa **Uygula** (onay sorar). Değişiklik geri alma günlüğüne yazılır.
3. Yanlışsa **Geri alma günlüğü → Geri al**. Nesne sonradan elle düzenlenmişse o satır atlanır.

Düzeltmeler yazının "son değiştirilme" tarihini ve revizyonları **değiştirmez** (Ayarlar'dan açılabilir).

## WP-CLI

```
wp contenthealth scan [--rules=a,b] [--post-type=post] [--limit=100]
wp contenthealth status [--format=json]
wp contenthealth list [--rule=<kural>] [--status=open|ignored|fixed] [--format=table|csv|json]
wp contenthealth fix <kural> [--dry-run] [--ids=1,2,3] [--alt-template="{başlık}"]
wp contenthealth undo <yığın-kimliği|günlük-no> [--dry-run]
wp contenthealth export [dosya.csv]
```

`fix` kuru koşu olmadan **uygular**; önce `--dry-run` kullanın. Uygulamada yığın kimliği yazılır: `wp contenthealth undo <kimlik>`.

## REST (yönetici + `X-WP-Nonce`)

`GET /contenthealth/v1/status`, `POST /scan {action:start|step|pause|resume|cancel}`, `GET /issues`, `POST /issues/status {ids,status}`, `POST /fix {rule,ids,dry_run,alt_template}`, `POST /undo {key,dry_run}`. `dry_run` verilmezse **kuru koşu** sayılır.

## Sık sorular

- **Ön yüzü yavaşlatır mı?** Hayır. Ön yüzde kod çalışmaz (bkz. `mimari.md` §8).
- **Büyük sitede?** Tarama parti parti ve imleçlidir; 363 yazı + 3.889 terim ~14 sn, bellek tepe ~66 MB.
- **Yoast/Rank Math ile?** Meta açıklaması bu eklentilerin alanlarından okunur.
- **Kaldırınca veri?** Varsayılan korunur; ayardan "Kaldırırken sil" seçilirse silinir.
