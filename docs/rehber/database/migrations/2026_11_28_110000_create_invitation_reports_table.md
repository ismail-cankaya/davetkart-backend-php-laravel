# `2026_11_28_110000_create_invitation_reports_table.php`

Faz 25 (K313). `invitation_reports`: `invitation_id` (ULID, davetiyeyle birlikte silinir), `reason` (CHECK, enum'dan),
`details` (null), `ip_hash`, zaman damgaları; `(invitation_id, created_at)` indeksi.
