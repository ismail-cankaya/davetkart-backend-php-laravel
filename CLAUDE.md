# DavetKart Backend Geliştirme Standartları

Bu proje **PHP 8.3+ ve Laravel 13** kullanılarak, **Modüler Monolit** (Modular Monolith) mimarisi ile geliştirilmektedir. Yeni kod yazarken veya mevcut kodu güncellerken aşağıdaki kurallara **kesinlikle** uyulmalıdır.

## 1. Mimari Katmanlar ve Kurallar

Bu projede "Repository Pattern" ve "Fat Service" kalıpları KESİNLİKLE YASAKTIR. Geleneksel Repository+Service yerine **Action-Based Architecture (Eylem Odaklı Mimari)** kullanılacaktır. Veritabanı işlemleri dolambaçlı yollarla değil, doğrudan Action sınıfları içinde Eloquent Model'leri üzerinden yapılacaktır.

- **`app/Actions/` (İşlem/Eylem Katmanı):** Uygulamanın kalbi burasıdır. Her bir kullanıcı işlemi (Örn: `RegisterUserAction`, `CreateInvoiceAction`) için ayrı bir sınıf oluşturulur. **Kesin Kurallar:**
  1. Her sınıf sadece TEK bir eylemi gerçekleştirir (Single Responsibility).
  2. Action sınıfları içerisine asla HTTP doğrulama (Validation) yazılmaz; doğrulama işi `FormRequest`'lere aittir. Action'a gelen veri saf ve güvenilir kabul edilir.
  3. Action sınıfları asla `return response()->json(...)` gibi HTTP yanıtları dönmez; sadece saf veri/Model döner (Yanıt dönme işi Controller'ındır).
  4. Veritabanı kaydı (DB) ve işle ilişkili ek görevler (Mail, Log vb.) bu sınıfın içinde birleşik olarak yazılır.
- **`app/Http/Controllers/Api/V1/`:** Controller'lar sadece gelen isteği ilgili Action'a yönlendirmekten ve Resource dönmekten sorumludur (Maksimum 3-8 satır olmalıdır). İçerisinde `if` blokları veya iş mantığı bulunamaz.
- **`app/Http/Requests/`:** Kullanıcı girdilerinin doğrulaması (validation) ve yetki kontrolleri (authorization) burada yapılmalıdır.
- **`app/Http/Resources/`:** Veritabanındaki `snake_case` alan adlarının, Frontend için `camelCase` formatına dönüştürüldüğü TEK yerdir. Dönüşümler "sihirli" fonksiyonlarla değil, açıkça yazılmalıdır.
- **`app/Policies/`:** Sahiplik ve erişim kontrolleri (IDOR önlemleri) mutlaka policyler ile yapılmalıdır.
- **`app/Services/`:** Dış servislerle (Ödeme, AI, Depolama) olan iletişim arayüzler (Interfaces) üzerinden burada yapılır. Tüm API anahtarları sadece bu sınıfların erişiminde olmalıdır.
- **`app/Enums/`:** Uygulama içinde kesinlikle "sihirli string" (magic string) kullanılmamalıdır. Durumlar (status) ve tipler için mutlaka PHP 8 Backed Enum kullanılmalıdır.

## 2. API Sözleşmesi (Routing & Naming)

- **Versiyonlama:** API endpointleri `/api/` kök URL'ini kullanır (Örn: `/api/auth/login`). Versiyonlama işlemi URL'de DEĞİL, Controller namespace'inde yapılır (`App\Http\Controllers\Api\V1\`).
- **Yanıt Zarfı (Response Envelope):**
  - **Auth (`/auth/login`, `/auth/register`):** `{ data: ... }` zarfı OLMADAN, doğrudan `{ user, token }` objesi döner.
  - **Diğer Tüm API Yanıtları:** Laravel'in varsayılanı olan `{ data: ... }` zarfı (envelope) ile döner.
- **Public Rotalar:** Giriş (auth) gerektirmeyen açık rotalar, yanlışlıkla dışarıya veri sızmasını önlemek için özellikle `/api/public/` önekiyle (prefix) gruplanmalıdır.

## 3. Güvenlik (Security)

- **Mass Assignment:** Modellerde kesinlikle `$guarded = []` kullanılamaz. İzin verilen tüm veriler `$fillable` dizisinde açıkça tanımlanmalıdır.
- **Paywall / Abonelik Sınırları:** Sınır ve yetki kısıtlamaları kesinlikle Frontend'den gelen isteklere güvenilerek yapılamaz. Sunucu tarafında `TierResolver` vb. sınıflar ile zorunlu paket (tier) hesaplaması backend'de doğrulanmalıdır.
- **KVKK (Veri Gizliliği):** IP adresleri gibi kişisel veriler ham haliyle kaydedilemez. Mutlaka hash'lenerek (`hash(ip + app_key)`) saklanmalıdır.
- **Idempotency (Ödeme İşlemleri):** Ödeme webhook ve callback işlemlerinde aynı ödemenin mükerrer çalışmaması için veritabanında `provider_ref` vb. UNIQUE kısıtlamalar bulunmalıdır.

## 4. Performans (Performance)

- **N+1 Sorgu Problemi:** İlişkili veriler çekilirken her zaman `with()` ile Eager Loading kullanılmalıdır. Geliştirme ortamında N+1 hatalarını anında fark etmek için `Model::preventLazyLoading()` açık olacaktır.
- **Polling ve Önbellek (Cache):** Özellikle public davetiye okuma sayfaları (`/api/public/invitations/{slug}`) gibi sık ziyaret edilen rotalarda yoğun Cache kullanımı ve ETag ile "304 Not Modified" optimizasyonları zorunludur.
- **15 Saniye Kuralı:** İsteğe hemen cevap verilmeli, uzun sürecek (resim optimizasyonu, mail gönderimi vb.) işlemler asla ana HTTP sürecini bekletmemeli ve `app/Jobs/` (Kuyruk) sistemine gönderilmelidir.
