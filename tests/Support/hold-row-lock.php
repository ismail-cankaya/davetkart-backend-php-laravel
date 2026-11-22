<?php

declare(strict_types=1);

// TST-02 (K187): LockAssertions::holdRowLockInAnotherProcess() alt süreci. Test veritabanında
// bir transaction açar, verilen SQL'i koşar, "locked" yazar, bekler ve COMMIT eder.
// Ayrintili aciklama: docs/rehber/tests/Support/LockAssertions.md

$pdo = new PDO((string) getenv('DK_RACE_DSN'), (string) getenv('DK_RACE_USER'), (string) getenv('DK_RACE_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

/** @var list<string> $statements */
$statements = json_decode((string) getenv('DK_RACE_SQL'), true, flags: JSON_THROW_ON_ERROR);

$pdo->beginTransaction();

foreach ($statements as $sql) {
    $pdo->exec($sql);
}

fwrite(STDOUT, "locked\n");
fflush(STDOUT);

usleep(max(0, (int) getenv('DK_RACE_HOLD_MS')) * 1000);

$pdo->commit();
fwrite(STDOUT, "committed\n");
