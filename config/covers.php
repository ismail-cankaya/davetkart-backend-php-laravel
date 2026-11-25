<?php

declare(strict_types=1);

// K235 (B-E4): frontend derlemesinin sabit adla yayimladigi sablon kapaklarinin dizini (preset -> /covers/...).
// Uretim yerlesimi: backend ve frontend/dist kardes klasor. Ayrinti: docs/rehber/config/covers.md
return [
    'index' => base_path('../frontend/dist/covers/index.json'),
];
