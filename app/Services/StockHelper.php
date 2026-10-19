<?php

declare(strict_types=1);

namespace App\Services;

/**
 * StockHelper — takviye/ilaç paketinde kalan adet hesapları.
 *
 * Bir supplements satırından (track_stock, stock_quantity, pack_size,
 * units_per_dose, low_stock_days, repurchase, schedule_times, doses_per_day,
 * end_date) şu özeti üretir:
 *   quantity, doses_left, days_left, pct, status, final_pack, covers_course, label
 *
 * status:
 *   'ok'    — yeterli
 *   'low'   — bitmek üzere (yeni paket alınacaksa uyarı)
 *   'empty' — bitti
 */
final class StockHelper
{
    /** Takip kapalıysa null döner. */
    public static function compute(array $row, ?string $today = null): ?array
    {
        if ((int)($row['track_stock'] ?? 0) !== 1 || $row['stock_quantity'] === null || $row['stock_quantity'] === '') {
            return null;
        }
        $today    = $today ?: date('Y-m-d');
        $qty      = max(0.0, (float)$row['stock_quantity']);
        $perDose  = max(0.01, (float)($row['units_per_dose'] ?? 1));
        $perDay   = $perDose * self::dosesPerDay($row);
        $pack     = (float)($row['pack_size'] ?? 0);
        $thr      = max(0, (int)($row['low_stock_days'] ?? 5));
        $rebuy    = (int)($row['repurchase'] ?? 1) === 1;

        $dosesLeft = (int)floor($qty / $perDose + 1e-9);
        $daysLeft  = $perDay > 0 ? (int)floor($qty / $perDay + 1e-9) : null;

        // Kür bitiş tarihi varsa: eldeki stok kürün sonuna kadar yetiyor mu?
        $coversCourse = null;
        if (!empty($row['end_date']) && $daysLeft !== null) {
            $courseLeft = (int)ceil((strtotime((string)$row['end_date']) - strtotime($today)) / 86400) + 1;
            $coversCourse = $daysLeft >= max(0, $courseLeft);
        }

        if ($dosesLeft <= 0) {
            $status = 'empty';
        } elseif ($rebuy && $daysLeft !== null && $daysLeft <= $thr && $coversCourse !== true) {
            $status = 'low';
        } else {
            $status = 'ok';
        }

        $unit = self::unitWord($row);
        $qtyTxt = rtrim(rtrim(number_format($qty, 2, ',', ''), '0'), ',');
        if ($status === 'empty') {
            $label = $rebuy ? 'Paket bitti — yenisini alın' : 'Paket bitti';
        } elseif ($daysLeft === 0) {
            $label = "{$qtyTxt} {$unit} kaldı · bugünlük";
        } elseif ($daysLeft !== null) {
            $label = "{$qtyTxt} {$unit} kaldı · ~{$daysLeft} gün yeter";
        } else {
            $label = "{$qtyTxt} {$unit} kaldı";
        }
        if ($coversCourse === true && $status !== 'empty') {
            $label .= ' (kür sonuna kadar yeterli)';
        }

        return [
            'quantity'      => $qty,
            'pack_size'     => $pack > 0 ? $pack : null,
            'units_per_dose'=> $perDose,
            'doses_left'    => $dosesLeft,
            'days_left'     => $daysLeft,
            'pct'           => $pack > 0 ? (int)max(0, min(100, round($qty / $pack * 100))) : null,
            'status'        => $status,
            'final_pack'    => !$rebuy,
            'covers_course' => $coversCourse,
            'threshold'     => $thr,
            'label'         => $label,
        ];
    }

    /** Günlük doz sayısı: alarm saati sayısı, yoksa doses_per_day. */
    public static function dosesPerDay(array $row): int
    {
        $times = $row['schedule_times'] ?? [];
        if (is_string($times)) {
            $times = json_decode($times, true) ?: [];
        }
        $n = is_array($times) ? count(array_filter($times, fn($t) => trim((string)$t) !== '')) : 0;
        return max(1, $n ?: (int)($row['doses_per_day'] ?? 1));
    }

    private static function unitWord(array $row): string
    {
        return match ($row['form'] ?? '') {
            'tablet'    => 'tablet',
            'capsule'   => 'kapsül',
            'powder'    => 'ölçek',
            'liquid'    => 'doz',
            'injection' => 'iğne',
            'patch'     => 'bant',
            default     => 'adet',
        };
    }
}
