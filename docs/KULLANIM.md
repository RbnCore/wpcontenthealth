# ContentHealth — Kullanım

**Araçlar → ContentHealth** (yönetici yetkisi gerekir).

## İlk açılış

Eklenti ilk açıldığında 3 adımlı bir karşılama gelir: (1) ne yaptığı ve neyi değiştirmediği, (2) tarama sıklığı ile kapsam, (3) ilk tarama. **Şimdi değil** ile atlanabilir; sonra Pano'dan **Şimdi tara** kullanılır.

## Sekmeler

| Sekme | Ne yapar |
|---|---|
| **Pano** | Sağlık puanı (0–100), kritik/uyarı/bilgi sayıları, en çok bulgusu olan kurallar, son tarama bilgisi, **Şimdi tara** (ilerleme çubuğu, duraklat, iptal, ağ hatasında yeniden dene) |
| **Bulgular** | Kural/ciddiyet/durum süzgeci, arama, sayfalama, toplu seçim. Satıra tıklayınca **çekmece**: ayrıntı ve önerilen düzeltmenin farkı (silinecek `−`, eklenecek `+`; alt alta ya da yan yana). Toplu seçimde **Düzelt…** sihirbazı: kuru koşu → örnek farklar → onay → uygula; **Yoksay / Yeniden aç**, CSV |
| **Etiket Doktoru** | Yinelenen etiket kümeleri (kanonik etiketi siz seçersiniz) ve **birleştir**; boş etiketleri silme; çok uzun adlar |
| **Alt Metin** | Alt metni olmayan görsellere şablondan (`{başlık}`, `{dosya_adı}`, `{site_adı}`) alt metin; canlı örnek önizleme |
| **Ayarlar** | Tarama sıklığı, adım süresi/büyüklüğü, taranan durumlar, dışlanan türler, kural eşikleri, yapay zekâ kalıntı desenleri (ekle/sil/**dene**; yavaş ya da geçersiz regex reddedilir). Değişiklik varken kaydet çubuğu açılır |
| **Geri alma günlüğü** | Uygulanan her düzeltme yığını, ayrıntısı ve **Geri al** |

Klavye: Tab ile gezin, **Esc** çekmeceyi/iletişim kutusunu kapatır ve odak açan düğmeye döner. Durum iletileri ekran okuyucuya duyurulur.

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
