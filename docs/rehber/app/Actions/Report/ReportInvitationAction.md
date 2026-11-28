# `app/Actions/Report/ReportInvitationAction.php`

Faz 25 (K313). Riskler tablosundaki "davetkart alan adında oltalama sayfası" önlemi.

1. Yalnızca **yayındaki** davetiye bildirilir; taslak ya da olmayan kimlik 404 (misafir taslağı göremez).
2. Honeypot doluysa sessizce yutulur (204; bot ayırt edemesin, iletişim formuyla aynı desen).
3. Kayıt: neden, isteğe bağlı ayrıntı (≤500), `ip_hash`.
4. `davetkart.reports.notify_email` (`DAVETKART_REPORTS_EMAIL`) doluysa yöneticiye **kuyrukta** posta
   (`InvitationReportedNotification`, 15 saniye kuralı). Boşsa yalnızca tabloya yazılır.

Hız: `throttle:contact` (iletişim formunun dar kovası: dakikada 3, saatte 10, IP başına).
Otomatik yayından kaldırma YOK: karar insanındır (yanlış bildirimle meşru bir düğün davetiyesi kapanmasın).
