<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EmailNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Kayitli e-postalari EmailNormalizer'in bugunku kanonik bicimine getirir
 * (Faz 10, 10.14 · D-5 · denetim K-3).
 *
 * 🔴 10.13'ten sonra YENI yazimlar dogru, ama Faz 9'a kadar `İ` ile
 * kaydolmus hesaplarin satirinda hala "i̇" (i + U+0307) duruyor. O kullanici
 * `ismail@…` ile giremiyor: giris artik "ismail@…" ariyor, satirda baska bir
 * dizi var. Mutator yalnizca YAZARKEN calisir; eski satirlar kendiliginden
 * duzelmez.
 *
 * 🔴 CAKISMA: ayni adresin iki hesabi (K-3'un 3. adimi Faz 9'da 201
 * donuyordu). Biri "i̇smail@…", digeri "ismail@…". Ilki duzeltilirse UNIQUE
 * kisiti patlar — ve patlamasa bile hangi hesabin kalacagi (davetiyeler,
 * siparisler hangisinde?) bir KOD karari degil. Komut o satirlara YAZMAZ,
 * raporlar ve basarisiz cikis koduyla biter: elle karar gerekir.
 *
 * Zamanlayicida YOK (K84): bakim komutu once elle, `--dry-run` ile kosulur.
 * Idempotent: ikinci kosu hicbir satir bulmaz.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/NormalizeUserEmails.md
 */
final class NormalizeUserEmails extends Command
{
    protected $signature = 'users:normalize-emails
                            {--dry-run : Yazma, yalnizca neyin degisecegini ve cakismalari soyle}';

    protected $description = 'Kayitli e-postalari kanonik bicime getirir; cakismalari yazmaz, raporlar';

    public function handle(): int
    {
        [$fixable, $conflicts] = $this->partition($this->pendingChanges());

        if ($this->option('dry-run') === true) {
            $this->report($fixable, $conflicts);
            $this->components->info(sprintf('%d e-posta duzeltilecek (yazilmadi).', count($fixable)));

            return $conflicts === [] ? self::SUCCESS : self::FAILURE;
        }

        $written = 0;

        foreach ($fixable as $change) {
            try {
                // Kosullu UPDATE (ExpireStaleOrders deseni): satir okundugu
                // andan beri degistiyse 0 satir etkilenir, ustune yazilmaz.
                // Deger zaten normalize; builder update'i mutator'i atlar.
                $written += User::query()
                    ->whereKey($change['id'])
                    ->where('email', $change['from'])
                    ->update(['email' => $change['to']]);
            } catch (UniqueConstraintViolationException) {
                // Okuma ile yazma arasinda biri AYNI adresle kaydoldu.
                // Yaris dar ama gercek; sonucu yukaridaki cakismayla ayni.
                $conflicts[] = $change + ['reason' => 'yazim sirasinda adres alindi'];
            }
        }

        $this->report([], $conflicts);
        $this->components->info(sprintf('%d e-posta duzeltildi.', $written));

        return $conflicts === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Normalizer'in degistirecegi her satir.
     *
     * 🔴 SQL'de U+0307 ARANMIYOR: o, kurali ikinci kez yazmak olurdu (C3).
     * Her satir normalizer'dan gecirilir; kural yarin genislerse komut
     * degismeden onu da uygular. `lazyById`: tablo bellege bir kerede
     * alinmaz, 500'luk parcalarla okunur.
     *
     * @return list<array{id: int, from: string, to: string}>
     */
    private function pendingChanges(): array
    {
        $changes = [];

        foreach (User::query()->select(['id', 'email'])->lazyById(500) as $user) {
            $normalized = EmailNormalizer::normalize($user->email);

            if ($normalized !== $user->email) {
                $changes[] = ['id' => $user->id, 'from' => $user->email, 'to' => $normalized];
            }
        }

        return $changes;
    }

    /**
     * Degisiklikleri yazilabilir ve cakisan olarak ayirir.
     *
     * Iki cakisma turu:
     *   1. Hedef adres BASKA bir satirda zaten var (K-3: "ismail@" ayri hesap).
     *   2. Iki aday AYNI hedefe dusuyor (ikisi de ayri bicimde bozuk).
     *
     * @param  list<array{id: int, from: string, to: string}>  $changes
     *
     * @return array{0: list<array{id: int, from: string, to: string}>, 1: list<array{id: int, from: string, to: string, reason: string}>}
     */
    private function partition(array $changes): array
    {
        $targets = array_column($changes, 'to');
        $perTarget = array_count_values($targets);

        /** @var array<string, int> $taken hedef adres -> o adresi zaten tasiyan kullanici */
        $taken = User::query()->whereIn('email', array_keys($perTarget))->pluck('id', 'email')->all();

        $fixable = [];
        $conflicts = [];

        foreach ($changes as $change) {
            if (isset($taken[$change['to']])) {
                $conflicts[] = $change + ['reason' => sprintf('adres #%d numarali hesapta', $taken[$change['to']])];
            } elseif ($perTarget[$change['to']] > 1) {
                $conflicts[] = $change + ['reason' => 'baska bir satir da ayni adrese duser'];
            } else {
                $fixable[] = $change;
            }
        }

        return [$fixable, $conflicts];
    }

    /**
     * @param  list<array{id: int, from: string, to: string}>  $fixable
     * @param  list<array{id: int, from: string, to: string, reason: string}>  $conflicts
     */
    private function report(array $fixable, array $conflicts): void
    {
        if ($fixable !== []) {
            $this->table(
                ['Kullanici', 'Simdiki', 'Olacak'],
                array_map(fn (array $c): array => [$c['id'], $this->visible($c['from']), $c['to']], $fixable),
            );
        }

        if ($conflicts !== []) {
            $this->components->error(sprintf(
                '%d satir YAZILMADI: ayni adresin birden fazla hesabi var. Hangisinin kalacagi elle karar verilmeli.',
                count($conflicts),
            ));

            $this->table(
                ['Kullanici', 'Simdiki', 'Olacak', 'Neden'],
                array_map(fn (array $c): array => [$c['id'], $this->visible($c['from']), $c['to'], $c['reason']], $conflicts),
            );
        }
    }

    /**
     * 🔴 "i̇smail@" ile "ismail@" terminalde AYNI gorunur. JSON kacisi ASCII
     * disini gorunur kilar: "i\u0307smail@…". Operator neyin degistigini
     * gormeden bir satira dokunmamali.
     */
    private function visible(string $email): string
    {
        return trim(json_encode($email, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), '"');
    }
}
