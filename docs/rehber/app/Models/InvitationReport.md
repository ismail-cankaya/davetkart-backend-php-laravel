# `app/Models/InvitationReport.php`

Faz 25 (K313). Misafirin bir davetiyeyi bildirmesi. Doldurulabilir yalnızca `reason`, `details`; `invitation_id`
ilişkiden (`associate`), `ip_hash` Action'dan yazılır (KVKK: ham IP saklanmaz, `IpHasher`). Davetiye silinince
bildirimleri de silinir (`cascadeOnDelete`).
