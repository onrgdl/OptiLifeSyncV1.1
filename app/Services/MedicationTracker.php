<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use DateTime;

/**
 * MedicationTracker
 *
 * OptiLifeSync - İlaç / Takviye Doz Takibi & Uyum (Adherence) Servisi
 *
 * Sorumluluklar:
 *  1. Bugünkü doz listesi (her ilacın her alarm saati için: alındı / atlandı / bekliyor / kaçırıldı)
 *  2. "Aldım" / "Atla" / "Geri al" işlemleri (supplement_logs tablosuna)
 *  3. Son N günün uyum oranı (planlanan doza göre alınan doz yüzdesi)
 *  4. Telefonun alarm sistemine (Android APK) gönderilecek alarm listesi
 *
 * supplement_logs.scheduled_time sütunu hangi alarm saatine ait dozun
 * alındığını tutar (database/migrations/2026_09_add_scheduled_time.sql).
 */
class MedicationTracker
{
    /** Alarm saatinden bu kadar dakika sonra işaretlenmemiş doz "kaçırıldı" sayılır. */
    private const MISSED_AFTER_MINUTES = 90;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =================================================================
    // YARDIMCILAR
    // =================================================================

    private function getOrCreateDailyLogId(int $userId, string $date): int
    {
        $stmt = $this->db->prepare("SELECT id FROM daily_logs WHERE user_id = ? AND log_date = ? LIMIT 1");
        $stmt->execute([$userId, $date]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $ins = $this->db->prepare("
            INSERT INTO daily_logs (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done)
            VALUES (?, ?, 0, 0, 0, 0, 0)
        ");
        $ins->execute([$userId, $date]);
        $stmt->execute([$userId, $date]);
        return (int) $stmt->fetchColumn();
    }

    /** Günlük makro toplamlarını (öğünler + alınan takviyeler) yeniden hesaplar. */
    public function recalcDailyTotals(int $dailyLogId): void
    {
        $this->db->prepare("
            UPDATE daily_logs
            SET total_calories  = (SELECT COALESCE(SUM(calories),0)  FROM food_logs WHERE daily_log_id = daily_logs.id)
                                + (SELECT COALESCE(SUM(s.calories_per_dose),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id = s.id WHERE sl.daily_log_id = daily_logs.id AND sl.is_taken = 1),
                total_protein_g = (SELECT COALESCE(SUM(protein_g),0) FROM food_logs WHERE daily_log_id = daily_logs.id)
                                + (SELECT COALESCE(SUM(s.protein_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id = s.id WHERE sl.daily_log_id = daily_logs.id AND sl.is_taken = 1),
                total_carbs_g   = (SELECT COALESCE(SUM(carbs_g),0)   FROM food_logs WHERE daily_log_id = daily_logs.id)
                                + (SELECT COALESCE(SUM(s.carbs_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id = s.id WHERE sl.daily_log_id = daily_logs.id AND sl.is_taken = 1),
                total_fat_g     = (SELECT COALESCE(SUM(fat_g),0)     FROM food_logs WHERE daily_log_id = daily_logs.id)
                                + (SELECT COALESCE(SUM(s.fat_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id = s.id WHERE sl.daily_log_id = daily_logs.id AND sl.is_taken = 1)
            WHERE id = ?
        ")->execute([$dailyLogId]);
    }

    private static function normTime(?string $t): ?string
    {
        $t = trim((string) $t);
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $i);
    }

    private static function validDate(?string $d): ?string
    {
        $d = trim((string) $d);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    /** Bir takviyenin verilen günde aktif (kür süresi içinde) olup olmadığı. */
    private static function isActiveOn(array $s, string $date): bool
    {
        $start = substr((string) ($s['start_date'] ?? ''), 0, 10);
        $end   = substr((string) ($s['end_date'] ?? ''), 0, 10);
        if ($start !== '' && $date < $start) {
            return false;
        }
        if ($end !== '' && $date > $end) {
            return false;
        }
        if ((int) ($s['is_active'] ?? 1) === 0 && ($end === '' || $date > $end)) {
            return false;
        }
        return true;
    }

    private static function decodeTimes($raw): array
    {
        $arr = is_array($raw) ? $raw : (json_decode((string) ($raw ?? '[]'), true) ?? []);
        $out = [];
        foreach ($arr as $t) {
            $n = self::normTime((string) $t);
            if ($n !== null) {
                $out[$n] = true;
            }
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    // =================================================================
    // BUGÜNÜN DOZ LİSTESİ
    // =================================================================

    /**
     * Verilen gün için tüm planlı dozları durumlarıyla döner.
     *
     * @return array{date:string, doses:array, summary:array}
     */
    public function getDosesForDate(int $userId, ?string $date = null): array
    {
        $date  = self::validDate($date) ?? date('Y-m-d');
        $today = date('Y-m-d');
        $nowMin = (int) date('H') * 60 + (int) date('i');

        $stmt = $this->db->prepare("
            SELECT id, name, type, form, dose_amount, dose_unit, schedule_times, start_date, end_date, is_active
            FROM supplements
            WHERE user_id = ?
            ORDER BY name
        ");
        $stmt->execute([$userId]);
        $supps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // O güne ait loglar
        $lStmt = $this->db->prepare("
            SELECT sl.id, sl.supplement_id, sl.scheduled_time, sl.taken_at, sl.is_taken
            FROM supplement_logs sl
            JOIN daily_logs dl ON dl.id = sl.daily_log_id
            WHERE dl.user_id = ? AND dl.log_date = ?
            ORDER BY sl.id
        ");
        $lStmt->execute([$userId, $date]);
        $logsBySupp = [];
        foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $logsBySupp[(int) $l['supplement_id']][] = $l;
        }

        $doses = [];
        foreach ($supps as $s) {
            if (!self::isActiveOn($s, $date)) {
                continue;
            }
            $times = self::decodeTimes($s['schedule_times']);
            if (!$times) {
                continue;
            }
            $sid  = (int) $s['id'];
            $logs = $logsBySupp[$sid] ?? [];

            // Saatli loglar doğrudan eşleşir; saatsiz loglar (ör. hızlı ekleme) ilk boş saate yazılır.
            $bySlot = [];
            $loose  = [];
            foreach ($logs as $l) {
                $st = self::normTime($l['scheduled_time'] ?? null);
                if ($st !== null && in_array($st, $times, true) && !isset($bySlot[$st])) {
                    $bySlot[$st] = $l;
                } else {
                    $loose[] = $l;
                }
            }
            foreach ($times as $t) {
                if (!isset($bySlot[$t]) && $loose) {
                    $bySlot[$t] = array_shift($loose);
                }
            }

            foreach ($times as $t) {
                $log = $bySlot[$t] ?? null;
                [$h, $i] = array_map('intval', explode(':', $t));
                $slotMin = $h * 60 + $i;
                if ($log) {
                    $status = ((int) $log['is_taken'] === 1) ? 'taken' : 'skipped';
                } elseif ($date < $today || ($date === $today && $nowMin - $slotMin > self::MISSED_AFTER_MINUTES)) {
                    $status = 'missed';
                } elseif ($date === $today && $nowMin >= $slotMin) {
                    $status = 'due';
                } else {
                    $status = 'pending';
                }
                $doses[] = [
                    'supplement_id' => $sid,
                    'name'          => $s['name'],
                    'type'          => $s['type'],
                    'form'          => $s['form'],
                    'dose'          => rtrim(rtrim(number_format((float) $s['dose_amount'], 2, '.', ''), '0'), '.') . ' ' . $s['dose_unit'],
                    'time'          => $t,
                    'status'        => $status,
                    'taken_at'      => $log ? substr((string) $log['taken_at'], 0, 5) : null,
                    'log_id'        => $log ? (int) $log['id'] : null,
                ];
            }
        }

        usort($doses, fn($a, $b) => [$a['time'], $a['name']] <=> [$b['time'], $b['name']]);

        $total  = count($doses);
        $taken  = count(array_filter($doses, fn($d) => $d['status'] === 'taken'));
        $missed = count(array_filter($doses, fn($d) => $d['status'] === 'missed'));
        $next   = null;
        foreach ($doses as $d) {
            if (in_array($d['status'], ['pending', 'due'], true)) {
                $next = $d;
                break;
            }
        }

        return [
            'date'    => $date,
            'doses'   => $doses,
            'summary' => [
                'total'   => $total,
                'taken'   => $taken,
                'missed'  => $missed,
                'skipped' => count(array_filter($doses, fn($d) => $d['status'] === 'skipped')),
                'pct'     => $total > 0 ? (int) round($taken / $total * 100) : 0,
                'next'    => $next,
            ],
        ];
    }

    // =================================================================
    // DOZ İŞARETLEME
    // =================================================================

    /**
     * Bir dozu "alındı" (taken) veya "atlandı" (skipped) olarak işaretler.
     * Aynı gün + ilaç + saat için ikinci kayıt oluşturmaz, var olanı günceller.
     *
     * @return array{ok:bool, duplicate?:bool, error?:string}
     */
    public function logDose(int $userId, int $supplementId, ?string $scheduledTime, ?string $date = null, string $status = 'taken'): array
    {
        $date = self::validDate($date) ?? date('Y-m-d');
        if ($date > date('Y-m-d', strtotime('+1 day'))) {
            return ['ok' => false, 'error' => 'Gelecek bir tarih için doz işaretlenemez.'];
        }
        $slot    = self::normTime($scheduledTime);
        $isTaken = $status === 'skipped' ? 0 : 1;

        $sStmt = $this->db->prepare("SELECT id, dose_amount, dose_unit FROM supplements WHERE id = ? AND user_id = ? LIMIT 1");
        $sStmt->execute([$supplementId, $userId]);
        $supp = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$supp) {
            return ['ok' => false, 'error' => 'İlaç/takviye bulunamadı.'];
        }

        $dailyLogId = $this->getOrCreateDailyLogId($userId, $date);

        $existing = null;
        if ($slot !== null) {
            $q = $this->db->prepare("
                SELECT id, is_taken FROM supplement_logs
                WHERE daily_log_id = ? AND supplement_id = ? AND scheduled_time = ?
                ORDER BY id LIMIT 1
            ");
            $q->execute([$dailyLogId, $supplementId, $slot . ':00']);
            $existing = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $duplicate = false;
        if ($existing) {
            $duplicate = (int) $existing['is_taken'] === $isTaken;
            $this->db->prepare("UPDATE supplement_logs SET is_taken = ?, taken_at = ? WHERE id = ?")
                ->execute([$isTaken, date('H:i:s'), $existing['id']]);
        } else {
            $takenAt = ($date === date('Y-m-d')) ? date('H:i:s') : (($slot ?? date('H:i')) . ':00');
            $this->db->prepare("
                INSERT INTO supplement_logs (daily_log_id, supplement_id, taken_at, dose_taken, dose_unit, is_taken, scheduled_time)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $dailyLogId, $supplementId, $takenAt,
                (float) $supp['dose_amount'], (string) $supp['dose_unit'],
                $isTaken, $slot !== null ? $slot . ':00' : null,
            ]);
        }

        $this->recalcDailyTotals($dailyLogId);
        return ['ok' => true, 'duplicate' => $duplicate];
    }

    /** Doz işaretini geri alır (kaydı siler). */
    public function undoDose(int $userId, int $supplementId, ?string $scheduledTime, ?string $date = null): bool
    {
        $date = self::validDate($date) ?? date('Y-m-d');
        $slot = self::normTime($scheduledTime);

        $stmt = $this->db->prepare("SELECT id FROM daily_logs WHERE user_id = ? AND log_date = ? LIMIT 1");
        $stmt->execute([$userId, $date]);
        $dailyLogId = (int) $stmt->fetchColumn();
        if ($dailyLogId <= 0) {
            return false;
        }

        if ($slot !== null) {
            $del = $this->db->prepare("DELETE FROM supplement_logs WHERE daily_log_id = ? AND supplement_id = ? AND scheduled_time = ?");
            $del->execute([$dailyLogId, $supplementId, $slot . ':00']);
            $count = $del->rowCount();
        } else {
            $count = 0;
        }
        if ($count === 0) {
            // Saatsiz son kaydı sil
            $q = $this->db->prepare("SELECT id FROM supplement_logs WHERE daily_log_id = ? AND supplement_id = ? ORDER BY id DESC LIMIT 1");
            $q->execute([$dailyLogId, $supplementId]);
            $lid = (int) $q->fetchColumn();
            if ($lid > 0) {
                $this->db->prepare("DELETE FROM supplement_logs WHERE id = ?")->execute([$lid]);
                $count = 1;
            }
        }
        $this->recalcDailyTotals($dailyLogId);
        return $count > 0;
    }

    // =================================================================
    // UYUM (ADHERENCE) İSTATİSTİĞİ
    // =================================================================

    /**
     * Son $days gün için günlük planlanan/alınan doz sayıları ve genel uyum yüzdesi.
     */
    public function getAdherence(int $userId, int $days = 7, ?string $endDate = null): array
    {
        $days = max(1, min(90, $days));
        $end  = self::validDate($endDate) ?? date('Y-m-d');
        $start = (new DateTime($end))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');

        $sStmt = $this->db->prepare("SELECT id, name, schedule_times, start_date, end_date, is_active, created_at FROM supplements WHERE user_id = ?");
        $sStmt->execute([$userId]);
        $supps = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        $lStmt = $this->db->prepare("
            SELECT dl.log_date, sl.supplement_id, SUM(CASE WHEN sl.is_taken = 1 THEN 1 ELSE 0 END) AS taken
            FROM supplement_logs sl
            JOIN daily_logs dl ON dl.id = sl.daily_log_id
            WHERE dl.user_id = ? AND dl.log_date BETWEEN ? AND ?
            GROUP BY dl.log_date, sl.supplement_id
        ");
        $lStmt->execute([$userId, $start, $end]);
        $taken = [];
        foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $taken[substr((string) $r['log_date'], 0, 10)][(int) $r['supplement_id']] = (int) $r['taken'];
        }

        $today  = date('Y-m-d');
        $series = [];
        $perSupp = [];
        $totPlanned = 0;
        $totTaken = 0;
        $cursor = new DateTime($start);
        for ($i = 0; $i < $days; $i++) {
            $d = $cursor->format('Y-m-d');
            $planned = 0;
            $got = 0;
            foreach ($supps as $s) {
                $created = substr((string) ($s['created_at'] ?? ''), 0, 10);
                if ($created !== '' && $d < $created) {
                    continue;
                }
                if (!self::isActiveOn($s, $d)) {
                    continue;
                }
                $n = count(self::decodeTimes($s['schedule_times']));
                if ($n === 0) {
                    continue;
                }
                // Bugün için sadece saati geçmiş dozları planlanmış say
                if ($d === $today) {
                    $nowMin = (int) date('H') * 60 + (int) date('i');
                    $n = count(array_filter(self::decodeTimes($s['schedule_times']), function ($t) use ($nowMin) {
                        [$h, $m] = array_map('intval', explode(':', $t));
                        return $h * 60 + $m <= $nowMin;
                    }));
                }
                $t = min($n, $taken[$d][(int) $s['id']] ?? 0);
                $planned += $n;
                $got += $t;
                $sid = (int) $s['id'];
                $perSupp[$sid] ??= ['supplement_id' => $sid, 'name' => $s['name'], 'planned' => 0, 'taken' => 0];
                $perSupp[$sid]['planned'] += $n;
                $perSupp[$sid]['taken'] += $t;
            }
            $series[] = [
                'date'    => $d,
                'label'   => $cursor->format('d.m'),
                'planned' => $planned,
                'taken'   => $got,
                'pct'     => $planned > 0 ? (int) round($got / $planned * 100) : null,
            ];
            $totPlanned += $planned;
            $totTaken += $got;
            $cursor->modify('+1 day');
        }

        foreach ($perSupp as &$p) {
            $p['pct'] = $p['planned'] > 0 ? (int) round($p['taken'] / $p['planned'] * 100) : null;
        }
        unset($p);

        // Üst üste %100 uyumlu gün serisi (bugün hariç, geriye doğru)
        $streak = 0;
        for ($i = count($series) - 1; $i >= 0; $i--) {
            if ($series[$i]['date'] === $today) {
                continue;
            }
            if ($series[$i]['planned'] > 0 && $series[$i]['taken'] >= $series[$i]['planned']) {
                $streak++;
            } else {
                break;
            }
        }

        return [
            'start'   => $start,
            'end'     => $end,
            'planned' => $totPlanned,
            'taken'   => $totTaken,
            'pct'     => $totPlanned > 0 ? (int) round($totTaken / $totPlanned * 100) : null,
            'streak'  => $streak,
            'series'  => $series,
            'by_supplement' => array_values($perSupp),
        ];
    }

    // =================================================================
    // TELEFON ALARM LİSTESİ (Android APK)
    // =================================================================

    /**
     * Telefonun alarm sistemine kurulacak tüm aktif alarmlar.
     * Hem ilaç/takviye alarmlarını hem de özel hatırlatıcıları içerir.
     */
    public function getNativeAlarmList(int $userId): array
    {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT r.id, r.supplement_id, r.type, r.label, r.remind_at, r.days_of_week, r.start_date, r.end_date,
                   s.name AS supp_name, s.dose_amount, s.dose_unit, s.form, s.end_date AS supp_end
            FROM reminders r
            LEFT JOIN supplements s ON s.id = r.supplement_id
            WHERE r.user_id = ?
              AND r.is_active = 1
              AND (r.end_date IS NULL OR r.end_date >= ?)
              AND (s.id IS NULL OR (s.is_active = 1 AND (s.end_date IS NULL OR s.end_date >= ?)))
            ORDER BY r.remind_at
        ");
        $stmt->execute([$userId, $today, $today]);

        $titles = [
            'medication' => '💊 İlaç zamanı',
            'supplement' => '🧪 Takviye zamanı',
            'water'      => '💧 Su içme zamanı',
            'meal'       => '🍽️ Öğün zamanı',
            'workout'    => '🏋️ Antrenman zamanı',
            'custom'     => '⏰ Hatırlatıcı',
        ];

        $alarms = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $time = self::normTime($r['remind_at']);
            if ($time === null) {
                continue;
            }
            [$h, $m] = array_map('intval', explode(':', $time));
            $days = json_decode((string) ($r['days_of_week'] ?? '[]'), true);
            $days = is_array($days) ? array_values(array_unique(array_map('intval', $days))) : [];
            if (!$days) {
                $days = [0, 1, 2, 3, 4, 5, 6];
            }

            $isSupp = !empty($r['supplement_id']);
            $name   = $isSupp ? (string) $r['supp_name'] : (string) $r['label'];
            $dose   = $isSupp ? trim(rtrim(rtrim(number_format((float) $r['dose_amount'], 2, '.', ''), '0'), '.') . ' ' . $r['dose_unit']) : '';
            $end    = $r['end_date'] ?: ($r['supp_end'] ?? null);

            $alarms[] = [
                'id'            => (int) $r['id'],
                'supplementId'  => $isSupp ? (int) $r['supplement_id'] : 0,
                'type'          => (string) $r['type'],
                'hour'          => $h,
                'minute'        => $m,
                'time'          => $time,
                'days'          => $days,
                'startDate'     => $r['start_date'] ? substr((string) $r['start_date'], 0, 10) : null,
                'endDate'       => $end ? substr((string) $end, 0, 10) : null,
                'title'         => $titles[$r['type']] ?? $titles['custom'],
                'body'          => $isSupp ? ($name . ($dose !== '' ? ' · ' . $dose : '')) : $name,
                'trackable'     => $isSupp,
            ];
        }
        return $alarms;
    }
}
