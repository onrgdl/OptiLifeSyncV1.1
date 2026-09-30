<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * ExerciseLogService
 *
 * OptiLifeSync - Egzersiz (set / tekrar / ağırlık) kaydı ve ilerleme takibi.
 * exercise_logs tablosunu kullanır (daily_logs üzerinden kullanıcıya bağlıdır).
 *
 *  • Bir güne egzersiz ekleme / silme
 *  • Günün egzersiz listesi ve toplam hacim (set × tekrar × kg)
 *  • Kişisel rekorlar (en yüksek ağırlık, tahmini 1 tekrar maksimum — Epley)
 *  • Bir egzersizin zaman içindeki gelişimi
 *  • Son 8 haftanın haftalık toplam hacmi
 */
class ExerciseLogService
{
    private PDO $db;

    public const MUSCLE_GROUPS = [
        'chest' => 'Göğüs', 'back' => 'Sırt', 'legs' => 'Bacak', 'shoulders' => 'Omuz',
        'arms' => 'Kol', 'core' => 'Karın / Core', 'cardio' => 'Kardiyo', 'full' => 'Tüm vücut', 'other' => 'Diğer',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private static function validDate(?string $d): string
    {
        $d = trim((string) $d);
        $today = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d > $today) {
            return $today;
        }
        return $d;
    }

    private function dailyLogId(int $userId, string $date): int
    {
        $sel = $this->db->prepare("SELECT id FROM daily_logs WHERE user_id = ? AND log_date = ? LIMIT 1");
        $sel->execute([$userId, $date]);
        $id = (int) $sel->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $this->db->prepare("
            INSERT INTO daily_logs (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done)
            VALUES (?, ?, 0, 0, 0, 0, 0)
        ")->execute([$userId, $date]);
        $sel->execute([$userId, $date]);
        return (int) $sel->fetchColumn();
    }

    /** Tahmini 1RM (Epley). Tekrar 1 ise ağırlığın kendisi. */
    public static function estimate1RM(float $weight, int $reps): float
    {
        if ($weight <= 0 || $reps <= 0) {
            return 0.0;
        }
        return $reps === 1 ? $weight : round($weight * (1 + $reps / 30), 1);
    }

    /**
     * @return int Yeni kayıt ID'si
     */
    public function addExercise(int $userId, array $in): int
    {
        $name = trim((string) ($in['exercise_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 140) {
            throw new \InvalidArgumentException('Egzersiz adı 1-140 karakter olmalı.');
        }
        $group = (string) ($in['muscle_group'] ?? '');
        $group = array_key_exists($group, self::MUSCLE_GROUPS) ? self::MUSCLE_GROUPS[$group] : (mb_substr(trim($group), 0, 90) ?: null);
        $sets     = max(0, min(50, (int) ($in['sets'] ?? 0))) ?: null;
        $reps     = max(0, min(500, (int) ($in['reps'] ?? 0))) ?: null;
        $weight   = (float) str_replace(',', '.', (string) ($in['weight_kg'] ?? '0'));
        $weight   = $weight > 0 ? min(1000, round($weight, 2)) : null;
        $duration = max(0, min(1440, (int) ($in['duration_minutes'] ?? 0))) ?: null;
        $notes    = trim((string) ($in['notes'] ?? '')) ?: null;

        if ($sets === null && $reps === null && $duration === null) {
            throw new \InvalidArgumentException('Set/tekrar veya süre girin.');
        }

        $dailyLogId = $this->dailyLogId($userId, self::validDate($in['date'] ?? null));
        $this->db->prepare("
            INSERT INTO exercise_logs (daily_log_id, exercise_name, muscle_group, sets, reps, weight_kg, duration_minutes, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$dailyLogId, $name, $group, $sets, $reps, $weight, $duration, $notes]);

        return function_exists('dbLastInsertId') ? dbLastInsertId($this->db, 'exercise_logs') : (int) $this->db->lastInsertId();
    }

    public function deleteExercise(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM exercise_logs
            WHERE id = ? AND daily_log_id IN (SELECT id FROM daily_logs WHERE user_id = ?)
        ");
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** Günün egzersizleri + özet. */
    public function getForDate(int $userId, ?string $date = null): array
    {
        $date = self::validDate($date);
        $stmt = $this->db->prepare("
            SELECT el.id, el.exercise_name, el.muscle_group, el.sets, el.reps, el.weight_kg, el.duration_minutes, el.notes
            FROM exercise_logs el
            JOIN daily_logs dl ON dl.id = el.daily_log_id
            WHERE dl.user_id = ? AND dl.log_date = ?
            ORDER BY el.id ASC
        ");
        $stmt->execute([$userId, $date]);
        $items = [];
        $volume = 0.0;
        $sets = 0;
        $minutes = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = (int) ($r['sets'] ?? 0);
            $rp = (int) ($r['reps'] ?? 0);
            $w = (float) ($r['weight_kg'] ?? 0);
            $vol = max(1, $s) * $rp * $w;
            $volume += $vol;
            $sets += $s;
            $minutes += (int) ($r['duration_minutes'] ?? 0);
            $items[] = [
                'id'               => (int) $r['id'],
                'exercise_name'    => $r['exercise_name'],
                'muscle_group'     => $r['muscle_group'],
                'sets'             => $s ?: null,
                'reps'             => $rp ?: null,
                'weight_kg'        => $w > 0 ? round($w, 2) : null,
                'duration_minutes' => $r['duration_minutes'] !== null ? (int) $r['duration_minutes'] : null,
                'notes'            => $r['notes'],
                'volume'           => round($vol),
                'e1rm'             => self::estimate1RM($w, $rp),
            ];
        }
        return [
            'date'    => $date,
            'items'   => $items,
            'summary' => ['volume' => round($volume), 'sets' => $sets, 'minutes' => $minutes, 'count' => count($items)],
        ];
    }

    /** Kişisel rekorlar: egzersiz başına en ağır set ve en yüksek tahmini 1RM. */
    public function getPersonalRecords(int $userId, int $limit = 12): array
    {
        $stmt = $this->db->prepare("
            SELECT el.exercise_name, el.weight_kg, el.reps, dl.log_date
            FROM exercise_logs el
            JOIN daily_logs dl ON dl.id = el.daily_log_id
            WHERE dl.user_id = ? AND el.weight_kg IS NOT NULL AND el.weight_kg > 0
            ORDER BY dl.log_date ASC, el.id ASC
        ");
        $stmt->execute([$userId]);
        $best = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = mb_strtolower(trim((string) $r['exercise_name']));
            $w = (float) $r['weight_kg'];
            $e = self::estimate1RM($w, (int) ($r['reps'] ?? 1) ?: 1);
            $best[$key] ??= ['exercise_name' => $r['exercise_name'], 'max_weight' => 0.0, 'max_reps_at_max' => 0, 'e1rm' => 0.0, 'date' => null, 'sessions' => 0];
            $best[$key]['sessions']++;
            if ($w > $best[$key]['max_weight'] || ($w === $best[$key]['max_weight'] && (int) $r['reps'] > $best[$key]['max_reps_at_max'])) {
                $best[$key]['max_weight'] = $w;
                $best[$key]['max_reps_at_max'] = (int) $r['reps'];
                $best[$key]['date'] = substr((string) $r['log_date'], 0, 10);
            }
            $best[$key]['e1rm'] = max($best[$key]['e1rm'], $e);
        }
        $list = array_values($best);
        usort($list, fn($a, $b) => $b['sessions'] <=> $a['sessions'] ?: $b['e1rm'] <=> $a['e1rm']);
        return array_slice($list, 0, $limit);
    }

    /** Bir egzersizin gün gün en iyi seti ve tahmini 1RM gelişimi. */
    public function getProgress(int $userId, string $exerciseName, int $days = 180): array
    {
        $from = date('Y-m-d', strtotime('-' . max(7, min(730, $days)) . ' days'));
        $stmt = $this->db->prepare("
            SELECT dl.log_date, el.weight_kg, el.reps, el.sets
            FROM exercise_logs el
            JOIN daily_logs dl ON dl.id = el.daily_log_id
            WHERE dl.user_id = ? AND LOWER(el.exercise_name) = LOWER(?) AND dl.log_date >= ?
            ORDER BY dl.log_date ASC
        ");
        $stmt->execute([$userId, trim($exerciseName), $from]);
        $byDay = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = substr((string) $r['log_date'], 0, 10);
            $w = (float) ($r['weight_kg'] ?? 0);
            $rp = (int) ($r['reps'] ?? 0);
            $byDay[$d] ??= ['date' => $d, 'max_weight' => 0.0, 'e1rm' => 0.0, 'volume' => 0.0];
            $byDay[$d]['max_weight'] = max($byDay[$d]['max_weight'], $w);
            $byDay[$d]['e1rm'] = max($byDay[$d]['e1rm'], self::estimate1RM($w, $rp));
            $byDay[$d]['volume'] += max(1, (int) ($r['sets'] ?? 1)) * $rp * $w;
        }
        return array_values(array_map(function ($x) { $x['volume'] = round($x['volume']); return $x; }, $byDay));
    }

    /** Son N haftanın toplam hacmi ve antrenman günü sayısı (Pazartesi başlangıçlı). */
    public function getWeeklyVolume(int $userId, int $weeks = 8): array
    {
        $weeks = max(1, min(26, $weeks));
        $monday = new \DateTime('monday this week');
        $start = (clone $monday)->modify('-' . ($weeks - 1) . ' weeks');
        $stmt = $this->db->prepare("
            SELECT dl.log_date, el.sets, el.reps, el.weight_kg, el.duration_minutes
            FROM exercise_logs el
            JOIN daily_logs dl ON dl.id = el.daily_log_id
            WHERE dl.user_id = ? AND dl.log_date >= ?
        ");
        $stmt->execute([$userId, $start->format('Y-m-d')]);
        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $wk = (clone $start)->modify("+{$i} weeks");
            $out[$wk->format('Y-m-d')] = ['week_start' => $wk->format('Y-m-d'), 'label' => $wk->format('d.m'), 'volume' => 0.0, 'days' => [], 'minutes' => 0];
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = new \DateTime(substr((string) $r['log_date'], 0, 10));
            $wkStart = (clone $d)->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
            if (!isset($out[$wkStart])) {
                continue;
            }
            $out[$wkStart]['volume'] += max(1, (int) ($r['sets'] ?? 1)) * (int) ($r['reps'] ?? 0) * (float) ($r['weight_kg'] ?? 0);
            $out[$wkStart]['minutes'] += (int) ($r['duration_minutes'] ?? 0);
            $out[$wkStart]['days'][$d->format('Y-m-d')] = true;
        }
        return array_values(array_map(fn($w) => [
            'week_start' => $w['week_start'], 'label' => $w['label'],
            'volume' => round($w['volume']), 'minutes' => $w['minutes'], 'active_days' => count($w['days']),
        ], $out));
    }

    /** Daha önce kullanılan egzersiz adları (otomatik tamamlama için). */
    public function getExerciseNames(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT el.exercise_name, MAX(el.muscle_group) AS muscle_group, COUNT(*) AS n
            FROM exercise_logs el
            JOIN daily_logs dl ON dl.id = el.daily_log_id
            WHERE dl.user_id = ?
            GROUP BY el.exercise_name
            ORDER BY COUNT(*) DESC
            LIMIT 60
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Aynı egzersizin bir önceki kaydı (formu hızlı doldurmak için). */
    public function getLastEntry(int $userId, string $exerciseName): ?array
    {
        $stmt = $this->db->prepare("
            SELECT el.sets, el.reps, el.weight_kg, el.duration_minutes, el.muscle_group, dl.log_date
            FROM exercise_logs el
            JOIN daily_logs dl ON dl.id = el.daily_log_id
            WHERE dl.user_id = ? AND LOWER(el.exercise_name) = LOWER(?)
            ORDER BY dl.log_date DESC, el.id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, trim($exerciseName)]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}
