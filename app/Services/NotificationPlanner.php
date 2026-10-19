<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

require_once __DIR__ . '/MedicationTracker.php';
require_once __DIR__ . '/DashboardService.php';
require_once __DIR__ . '/WorkoutService.php';

/**
 * NotificationPlanner — Aşama 2 akıllı bildirimler.
 *
 * Akış (Android uygulaması):
 *   1) plan()    : telefonun kuracağı "akıllı" alarm saatleri (sabah özeti, su, makro,
 *                  doz takibi, antrenman öncesi/başlangıç/onay, gün sonu).
 *   2) compose() : alarm çaldığında telefon sunucuya sorar; o anki gerçek verilerle
 *                  bildirim metni ve butonları üretilir (gerek yoksa show=false).
 *   3) act()     : bildirim butonları ("Aldım", "+250 ml", "Yaptım" …) sunucuya işlenir.
 */
final class NotificationPlanner
{
    /** Akıllı alarm id aralıkları (ilaç hatırlatıcı id'leriyle çakışmaz). */
    public const ID_MORNING      = 800000001;
    public const ID_EOD          = 800000002;
    public const ID_WATER_BASE   = 800000100;
    public const ID_MACRO_BASE   = 800000200;
    public const ID_DOSE_BASE    = 810000000;
    public const ID_WORKOUT_BASE = 820000000;

    public const DEFAULTS = [
        'enabled'          => true,
        'morning_enabled'  => true,
        'morning_time'     => '07:45',
        'water_enabled'    => true,
        'water_every_min'  => 90,
        'water_start'      => '09:00',
        'water_end'        => '21:30',
        'macro_enabled'    => true,
        'macro_times'      => ['13:30', '19:30'],
        'dose_followup_enabled' => true,
        'dose_followup_min'     => 30,
        'workout_enabled'       => true,
        'workout_default_time'  => '18:00',
        'workout_lead_min'      => 60,
        'workout_duration_min'  => 60,
        'eod_enabled'      => true,
        'eod_time'         => '21:30',
        'quiet_start'      => '23:00',
        'quiet_end'        => '07:30',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =================================================================
    // TERCİHLER
    // =================================================================

    public function getPrefs(int $userId): array
    {
        $p = self::DEFAULTS;
        try {
            $q = $this->db->prepare('SELECT prefs FROM notification_prefs WHERE user_id = ?');
            $q->execute([$userId]);
            $raw = $q->fetchColumn();
            $saved = $raw ? json_decode((string)$raw, true) : null;
            if (is_array($saved)) {
                $p = self::sanitize($saved + $p);
            }
        } catch (\Throwable) {
            // tablo yoksa varsayılanlar
        }
        return $p;
    }

    public function savePrefs(int $userId, array $in): array
    {
        $p = self::sanitize($in + $this->getPrefs($userId));
        $this->db->prepare("
            INSERT INTO notification_prefs (user_id, prefs, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (user_id) DO UPDATE SET prefs = EXCLUDED.prefs, updated_at = CURRENT_TIMESTAMP
        ")->execute([$userId, json_encode($p, JSON_UNESCAPED_UNICODE)]);
        return $p;
    }

    /** Gelen değerleri doğrular; geçersiz olanlar varsayılana döner. */
    public static function sanitize(array $in): array
    {
        $d = self::DEFAULTS;
        $out = [];
        foreach ($d as $k => $def) {
            $v = $in[$k] ?? $def;
            if (is_bool($def)) {
                $out[$k] = is_bool($v) ? $v : !in_array(strtolower((string)$v), ['0', 'false', 'off', '', 'no'], true);
            } elseif (is_int($def)) {
                $out[$k] = (int)$v;
            } elseif (is_array($def)) {
                $arr = is_array($v) ? $v : array_map('trim', explode(',', (string)$v));
                $arr = array_values(array_unique(array_filter($arr, fn($t) => self::validTime((string)$t))));
                sort($arr);
                $out[$k] = array_slice($arr, 0, 4);
            } else {
                $out[$k] = self::validTime((string)$v) ? substr((string)$v, 0, 5) : $def;
            }
        }
        $out['water_every_min']      = max(30, min(240, $out['water_every_min']));
        $out['dose_followup_min']    = max(10, min(120, $out['dose_followup_min']));
        $out['workout_lead_min']     = max(10, min(240, $out['workout_lead_min']));
        $out['workout_duration_min'] = max(15, min(240, $out['workout_duration_min']));
        return $out;
    }

    private static function validTime(string $t): bool
    {
        return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', substr($t, 0, 5)) && strlen($t) >= 5;
    }

    private static function toMin(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));
        return $h * 60 + $m;
    }

    private static function fromMin(int $m): string
    {
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    public static function inQuiet(int $minute, array $p): bool
    {
        $s = self::toMin($p['quiet_start']);
        $e = self::toMin($p['quiet_end']);
        if ($s === $e) {
            return false;
        }
        return $s < $e ? ($minute >= $s && $minute < $e) : ($minute >= $s || $minute < $e);
    }

    // =================================================================
    // 1) TELEFONA KURULACAK AKILLI ALARMLAR
    // =================================================================

    /**
     * @param array $doseAlarms MedicationTracker::getNativeAlarmList() çıktısı (doz takibi için)
     */
    public function plan(int $userId, array $doseAlarms = []): array
    {
        $p = $this->getPrefs($userId);
        if (!$p['enabled']) {
            return [];
        }
        $all = [0, 1, 2, 3, 4, 5, 6];
        $out = [];
        $add = function (int $id, string $kind, int $min, array $days, array $extra = []) use (&$out, $p) {
            if ($min < 0 || $min >= 1440 || self::inQuiet($min, $p)) {
                return;
            }
            $out[] = array_merge([
                'id'        => $id,
                'type'      => 'smart',
                'kind'      => $kind,
                'smart'     => true,
                'hour'      => intdiv($min, 60),
                'minute'    => $min % 60,
                'time'      => self::fromMin($min),
                'days'      => $days,
                'startDate' => null,
                'endDate'   => null,
                'trackable' => false,
                'title'     => 'OptiLifeSync',
                'body'      => '',
                'fallback'  => false,
                'ref'       => new \stdClass(),
            ], $extra);
        };

        // Sabah özeti
        if ($p['morning_enabled']) {
            $m = self::toMin($p['morning_time']);
            if (self::inQuiet($m, $p)) {
                $m = self::toMin($p['quiet_end']);
            }
            $add(self::ID_MORNING, 'morning', $m, $all, [
                'title' => '☀️ Günaydın!', 'body' => 'Bugünkü planına göz at.', 'fallback' => true,
            ]);
        }

        // Su: gün içinde düzenli aralıklarla
        if ($p['water_enabled']) {
            $i = 0;
            for ($m = self::toMin($p['water_start']); $m <= self::toMin($p['water_end']) && $i < 24; $m += $p['water_every_min'], $i++) {
                $add(self::ID_WATER_BASE + $i, 'water', $m, $all, [
                    'title' => '💧 Su vakti', 'body' => 'Bir bardak su içmeyi unutma.', 'fallback' => true,
                ]);
            }
        }

        // Makro durumu
        if ($p['macro_enabled']) {
            foreach ($p['macro_times'] as $i => $t) {
                $add(self::ID_MACRO_BASE + $i, 'macro', self::toMin($t), $all, ['title' => '🍽️ Beslenme durumu']);
            }
        }

        // Gün sonu kontrolü
        if ($p['eod_enabled']) {
            $add(self::ID_EOD, 'eod', self::toMin($p['eod_time']), $all, [
                'title' => '🌙 Gün sonu kontrolü', 'body' => 'Bugünün eksik kayıtlarını tamamla.', 'fallback' => true,
            ]);
        }

        // Kaçırılan doz takibi: her ilaç alarmından N dk sonra (alındıysa sessiz kalır)
        if ($p['dose_followup_enabled']) {
            foreach ($doseAlarms as $a) {
                if (empty($a['trackable']) || empty($a['supplementId'])) {
                    continue;
                }
                $m = (int)$a['hour'] * 60 + (int)$a['minute'] + $p['dose_followup_min'];
                $add(self::ID_DOSE_BASE + ((int)$a['id'] % 9000000), 'dose_check', $m, $a['days'] ?: $all, [
                    'startDate' => $a['startDate'] ?? null,
                    'endDate'   => $a['endDate'] ?? null,
                    'title'     => '💊 Dozunu aldın mı?',
                    'ref'       => ['supplement_id' => (int)$a['supplementId'], 'time' => (string)$a['time'], 'reminder_id' => (int)$a['id']],
                ]);
            }
        }

        // Antrenmanlar: önümüzdeki 14 gün (tarihe özel tek seferlik alarmlar)
        if ($p['workout_enabled']) {
            foreach ($this->upcomingWorkouts($userId, 14) as $w) {
                $date  = (string)$w['tarih'];
                $dow   = (int)date('w', strtotime($date));
                $start = self::toMin($w['planlanan_saat'] ? substr((string)$w['planlanan_saat'], 0, 5) : $p['workout_default_time']);
                $dur   = (int)($w['sure_dk'] ?: $p['workout_duration_min']);
                $base  = self::ID_WORKOUT_BASE + ((int)$w['id'] % 9000000) * 3;
                $ref   = ['workout_id' => (int)$w['id']];
                $extra = ['startDate' => $date, 'endDate' => $date, 'ref' => $ref];
                $add($base, 'workout_pre', $start - $p['workout_lead_min'], [$dow], $extra + [
                    'title' => '🏋️ Antrenman yaklaşıyor', 'body' => (string)$w['antrenman_tipi'], 'fallback' => true,
                ]);
                $add($base + 1, 'workout_start', $start, [$dow], $extra + [
                    'title' => '🏋️ Antrenman zamanı', 'body' => (string)$w['antrenman_tipi'], 'fallback' => true,
                ]);
                $add($base + 2, 'workout_check', min(1439, $start + $dur + 15), [$dow], $extra + [
                    'title' => '🏋️ Antrenmanını yaptın mı?',
                ]);
            }
        }

        return $out;
    }

    private function upcomingWorkouts(int $userId, int $days): array
    {
        try {
            $q = $this->db->prepare("
                SELECT id, tarih, antrenman_tipi, zorluk_seviyesi, planlanan_saat, sure_dk
                  FROM workouts
                 WHERE user_id = ? AND tarih BETWEEN ? AND ?
                   AND tamamlandi_mi = 0 AND atlandi_mi = 0
                 ORDER BY tarih, planlanan_saat
            ");
            $q->execute([$userId, date('Y-m-d'), date('Y-m-d', strtotime("+{$days} days"))]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    // =================================================================
    // GÜN DURUMU (gün sonu bildirimi + gün sonu ekranı)
    // =================================================================

    public function dayStatus(int $userId, ?string $date = null): array
    {
        $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
        $dash = DashboardService::getDashboardData($this->db, $userId, $date);
        $doses = $dash['doses']['doses'] ?? [];
        // Eksik: saati gelmiş/geçmiş ama işaretlenmemiş dozlar (saati gelmemiş "pending" dozlar eksik sayılmaz;
        // geçmiş günlerde tüm işaretlenmemişler "missed" olarak gelir)
        $openDoses = array_values(array_filter($doses, fn($d) => in_array($d['status'], ['due', 'missed'], true)));
        $laterDoses = array_values(array_filter($doses, fn($d) => $d['status'] === 'pending'));

        $workouts = [];
        try {
            $q = $this->db->prepare("
                SELECT id, antrenman_tipi, zorluk_seviyesi, planlanan_saat, sure_dk, tamamlandi_mi, atlandi_mi
                  FROM workouts WHERE user_id = ? AND tarih = ? ORDER BY planlanan_saat NULLS LAST, id
            ");
            $q->execute([$userId, $date]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $w) {
                $workouts[] = [
                    'id'     => (int)$w['id'],
                    'type'   => $w['antrenman_tipi'],
                    'level'  => $w['zorluk_seviyesi'],
                    'time'   => $w['planlanan_saat'] ? substr((string)$w['planlanan_saat'], 0, 5) : null,
                    'status' => (int)$w['tamamlandi_mi'] === 1 ? 'done' : ((int)$w['atlandi_mi'] === 1 ? 'skipped' : 'open'),
                ];
            }
        } catch (\Throwable) {
        }
        $openWorkouts = array_values(array_filter($workouts, fn($w) => $w['status'] === 'open'));

        return [
            'date'          => $date,
            'doses'         => $doses,
            'open_doses'    => $openDoses,
            'later_doses'   => $laterDoses,
            'dose_summary'  => $dash['doses']['summary'] ?? null,
            'workouts'      => $workouts,
            'open_workouts' => $openWorkouts,
            'water'         => $dash['water'] ?? null,
            'meals_count'   => count($dash['recent_meals'] ?? []),
            'consumed'      => $dash['consumed'] ?? null,
            'target'        => $dash['target'] ?? null,
            'low_stock'     => $dash['low_stock'] ?? [],
        ];
    }

    // =================================================================
    // 2) BİLDİRİM İÇERİĞİ (alarm çaldığı anda)
    // =================================================================

    public function compose(int $userId, string $kind, array $ref = []): array
    {
        $p = $this->getPrefs($userId);
        $none = ['show' => false];
        $nowMin = (int)date('H') * 60 + (int)date('i');
        if (!$p['enabled'] || self::inQuiet($nowMin, $p)) {
            return $none;
        }
        $catOn = [
            'morning' => 'morning_enabled', 'water' => 'water_enabled', 'macro' => 'macro_enabled',
            'eod' => 'eod_enabled', 'dose_check' => 'dose_followup_enabled',
            'workout_pre' => 'workout_enabled', 'workout_start' => 'workout_enabled', 'workout_check' => 'workout_enabled',
        ];
        if (!isset($catOn[$kind]) || !$p[$catOn[$kind]]) {
            return $none;
        }

        return match ($kind) {
            'morning'       => $this->composeMorning($userId),
            'water'         => $this->composeWater($userId),
            'macro'         => $this->composeMacro($userId),
            'eod'           => $this->composeEod($userId),
            'dose_check'    => $this->composeDoseCheck($userId, $ref),
            'workout_pre', 'workout_start', 'workout_check' => $this->composeWorkout($userId, $kind, $ref, $p),
            default         => $none,
        };
    }

    private static function msg(string $title, string $body, array $actions = [], string $url = '/dashboard.php'): array
    {
        return ['show' => true, 'title' => $title, 'body' => $body, 'actions' => $actions, 'url' => $url];
    }

    /** "18:00'de", "19:30'da", "17:45'te" — saatin okunuşuna göre Türkçe bulunma eki. */
    public static function atTime(string $hm): string
    {
        [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));
        $n = $m > 0 ? $m : ($h % 12 === 0 && $h !== 0 ? 12 : $h);
        if ($n === 0) {
            return $hm . "'da"; // sıfır
        }
        $ones = $n % 10;
        $tens = intdiv($n, 10) * 10;
        $last = $ones !== 0 ? $ones : $tens;
        $suffix = [
            1 => "'de", 2 => "'de", 3 => "'te", 4 => "'te", 5 => "'te", 6 => "'da", 7 => "'de", 8 => "'de", 9 => "'da",
            10 => "'da", 20 => "'de", 30 => "'da", 40 => "'ta", 50 => "'de",
        ][$last] ?? "'de";
        return $hm . $suffix;
    }

    private static function liters(int $ml): string
    {
        return rtrim(rtrim(number_format($ml / 1000, 1, ',', ''), '0'), ',') . ' L';
    }

    private function composeMorning(int $userId): array
    {
        $s = $this->dayStatus($userId);
        $lines = [];
        $doses = array_filter($s['doses'], fn($d) => $d['status'] !== 'taken' && $d['status'] !== 'skipped');
        if ($doses) {
            $parts = [];
            foreach (array_slice(array_values($doses), 0, 4) as $d) {
                $parts[] = $d['time'] . ' ' . $d['name'];
            }
            $more = count($doses) - count($parts);
            $lines[] = '💊 ' . count($doses) . ' doz: ' . implode(', ', $parts) . ($more > 0 ? " +{$more}" : '');
        }
        if ($s['water']) {
            $lines[] = '💧 Su hedefi: ' . self::liters((int)$s['water']['target_ml']);
        }
        foreach ($s['open_workouts'] as $w) {
            $lines[] = '🏋️ Antrenman: ' . $w['type'] . ' (' . $w['level'] . ')' . ($w['time'] ? ' · ' . $w['time'] : '');
        }
        if (!empty($s['target']['calories'])) {
            $lines[] = '🍽️ Kalori hedefi: ' . (int)$s['target']['calories'] . ' kcal · protein ' . (int)$s['target']['protein_g'] . ' g';
        }
        foreach (array_slice($s['low_stock'], 0, 2) as $ls) {
            $lines[] = '📦 ' . $ls['name'] . ': ' . ($ls['status'] === 'empty' ? 'paket bitti' : $ls['label']);
        }
        try {
            foreach (DashboardService::getMacroTrend($this->db, $userId, 7)['insights'] as $in) {
                if ($in['level'] === 'warn') {
                    $lines[] = '📈 ' . $in['text'];
                    break; // sabah özetinde tek eğilim notu yeter
                }
            }
        } catch (\Throwable) {
        }
        return self::msg('☀️ Günaydın! Bugünün planı', $lines ? implode("\n", $lines) : 'Bugün için planlı bir şey yok. İyi bir gün geçir!');
    }

    private function composeWater(int $userId): array
    {
        $dash = DashboardService::getDashboardData($this->db, $userId, null, true);
        $w = $dash['water'];
        $done = (int)$w['consumed_ml'];
        $target = (int)$w['target_ml'];
        $acts = [['id' => 'water_250', 'label' => '+250 ml'], ['id' => 'water_500', 'label' => '+500 ml']];
        if ($target > 0 && $done >= $target) {
            return self::msg('💧 Su vakti', 'Bugünkü hedefini tamamladın 👏 Yine de bir bardak su iyi gelir.', $acts);
        }
        $tips = [
            'Bir bardak su iç, odaklanman ve enerjin artsın.',
            'Kısa bir mola ver ve bir bardak su iç.',
            'Susamayı bekleme, şimdi bir bardak su iç.',
            'Su içmek metabolizmana ve antrenman performansına destek olur.',
        ];
        $tip = $tips[(int)date('G') % count($tips)];
        return self::msg('💧 Su vakti', $tip . "\nBugün: " . self::liters($done) . ' / ' . self::liters($target), $acts);
    }

    private function composeMacro(int $userId): array
    {
        $dash = DashboardService::getDashboardData($this->db, $userId, null, true);
        $c = $dash['consumed'];
        $t = $dash['target'];
        $hour = (int)date('G');
        $frac = $hour < 16 ? 0.45 : 0.8;
        $kcal = (int)round((float)$c['calories']);
        $kcalT = (int)round((float)$t['calories']);
        $prot = (int)round((float)$c['protein_g']);
        $protT = (int)round((float)$t['protein_g']);

        if ($kcal <= 0) {
            return self::msg('🍽️ Beslenme durumu', 'Bugün henüz öğün girmedin. Yediklerini eklersen eksik/fazlanı takip edebilirim.', [], '/nutrition.php');
        }
        $notes = [];
        if ($kcalT > 0 && $kcal > $kcalT * 1.05) {
            $notes[] = '⚠️ Kalori hedefini ' . ($kcal - $kcalT) . ' kcal aştın.';
        } elseif ($hour >= 16 && $kcalT - $kcal > 400) {
            $notes[] = 'Kalan ' . ($kcalT - $kcal) . ' kcal var; akşam öğününü buna göre planla.';
        }
        if ($protT > 0 && $prot < $protT * $frac * 0.8) {
            $notes[] = '🥩 Protein geride: ' . $prot . ' / ' . $protT . ' g (' . max(0, $protT - $prot) . ' g eksik).';
        }
        $fatT = (float)$t['fat_g'];
        if ($fatT > 0 && (float)$c['fat_g'] > $fatT * 1.1) {
            $notes[] = 'Yağ hedefini aştın: ' . (int)$c['fat_g'] . ' / ' . (int)$fatT . ' g.';
        }
        $summary = $kcal . ' / ' . $kcalT . ' kcal · protein ' . $prot . ' / ' . $protT . ' g';
        if (!$notes) {
            return self::msg('🍽️ Beslenme yolunda ✔', $summary, [], '/nutrition.php');
        }
        return self::msg('🍽️ Beslenme durumu', implode("\n", $notes) . "\n" . $summary, [], '/nutrition.php');
    }

    private function composeDoseCheck(int $userId, array $ref): array
    {
        $sid = (int)($ref['supplement_id'] ?? 0);
        $time = substr((string)($ref['time'] ?? ''), 0, 5);
        if ($sid <= 0) {
            return ['show' => false];
        }
        $doses = (new MedicationTracker($this->db))->getDosesForDate($userId)['doses'] ?? [];
        foreach ($doses as $d) {
            if ((int)$d['supplement_id'] === $sid && $d['time'] === $time) {
                if (in_array($d['status'], ['taken', 'skipped'], true)) {
                    return ['show' => false];
                }
                return self::msg('💊 Dozunu aldın mı?', $d['name'] . ' · ' . $d['dose'] . " ({$time}) henüz işaretlenmedi.", [
                    ['id' => 'dose_taken', 'label' => '✅ Aldım'],
                    ['id' => 'dose_skip', 'label' => 'Atla'],
                ], '/reminders.php');
            }
        }
        return ['show' => false];
    }

    private function composeWorkout(int $userId, string $kind, array $ref, array $p): array
    {
        $wid = (int)($ref['workout_id'] ?? 0);
        $q = $this->db->prepare('SELECT * FROM workouts WHERE id = ? AND user_id = ? LIMIT 1');
        $q->execute([$wid, $userId]);
        $w = $q->fetch(PDO::FETCH_ASSOC);
        if (!$w || (int)$w['tamamlandi_mi'] === 1 || (int)($w['atlandi_mi'] ?? 0) === 1 || (string)$w['tarih'] !== date('Y-m-d')) {
            return ['show' => false];
        }
        $time = $w['planlanan_saat'] ? substr((string)$w['planlanan_saat'], 0, 5) : $p['workout_default_time'];
        $desc = $w['antrenman_tipi'] . ' · ' . $w['zorluk_seviyesi'];
        $done = [['id' => 'workout_done', 'label' => '✅ Yaptım'], ['id' => 'workout_skip', 'label' => 'Yapmadım']];
        return match ($kind) {
            'workout_pre'   => self::msg('🏋️ Antrenman ' . self::atTime($time), $desc . "\nSu şişeni ve ekipmanını hazırla.", [], '/workout.php'),
            'workout_start' => self::msg('🏋️ Antrenman zamanı', $desc . "\nİyi antrenmanlar! Bitince işaretlemeyi unutma.", [['id' => 'workout_done', 'label' => '✅ Yaptım']], '/workout.php'),
            default         => self::msg('🏋️ Antrenmanını yaptın mı?', $desc . " ({$time})", $done, '/workout.php'),
        };
    }

    private function composeEod(int $userId): array
    {
        $s = $this->dayStatus($userId);
        $lines = [];
        if ($s['open_doses']) {
            $names = array_map(fn($d) => $d['name'] . ' ' . $d['time'], array_slice($s['open_doses'], 0, 3));
            $lines[] = '💊 ' . count($s['open_doses']) . ' doz işaretlenmedi: ' . implode(', ', $names);
        }
        if ($s['later_doses']) {
            $names = array_map(fn($d) => $d['time'] . ' ' . $d['name'], array_slice($s['later_doses'], 0, 2));
            $lines[] = '⏰ Gece dozu: ' . implode(', ', $names);
        }
        foreach ($s['open_workouts'] as $w) {
            $lines[] = '🏋️ ' . $w['type'] . ' — yaptın mı?';
        }
        if ($s['water'] && (int)$s['water']['consumed_ml'] < (int)$s['water']['target_ml']) {
            $lines[] = '💧 Su: ' . self::liters((int)$s['water']['consumed_ml']) . ' / ' . self::liters((int)$s['water']['target_ml']);
        }
        if ($s['meals_count'] === 0) {
            $lines[] = '🍽️ Bugün öğün girilmedi';
        }
        if (!$lines) {
            $sum = $s['dose_summary'];
            $extra = $sum && $sum['total'] > 0 ? " Doz {$sum['taken']}/{$sum['total']}." : '';
            return self::msg('🌙 Harika bir gün!', 'Bugünün tüm kayıtları tamam 👏' . $extra, [], '/checkin.php');
        }
        return self::msg('🌙 Gün sonu kontrolü', implode("\n", $lines), [['id' => 'open', 'label' => 'Tamamla']], '/checkin.php');
    }

    // =================================================================
    // 3) BİLDİRİM BUTONLARI
    // =================================================================

    public function act(int $userId, string $do, array $ref = [], ?string $date = null): array
    {
        $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
        switch ($do) {
            case 'water_250':
            case 'water_500':
                $ml = $do === 'water_500' ? 500 : 250;
                $log = DashboardService::getOrCreateDailyLog($this->db, $userId, $date);
                $this->db->prepare('UPDATE daily_logs SET water_ml = GREATEST(0, water_ml + ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$ml, (int)$log['id']]);
                return ['ok' => true, 'message' => "+{$ml} ml su eklendi 💧"];

            case 'dose_taken':
            case 'dose_skip':
                $res = (new MedicationTracker($this->db))->logDose(
                    $userId, (int)($ref['supplement_id'] ?? 0), (string)($ref['time'] ?? ''), $date,
                    $do === 'dose_taken' ? 'taken' : 'skipped'
                );
                return ['ok' => !empty($res['ok']), 'message' => $do === 'dose_taken' ? 'Kaydedildi: alındı ✅' : 'Atlandı olarak kaydedildi', 'stock' => $res['stock'] ?? null];

            case 'workout_done':
                (new WorkoutService($this->db))->completeWorkout((int)($ref['workout_id'] ?? 0), $userId);
                return ['ok' => true, 'message' => 'Tebrikler! Antrenman tamamlandı 💪'];

            case 'workout_skip':
                $st = $this->db->prepare('UPDATE workouts SET atlandi_mi = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
                $st->execute([(int)($ref['workout_id'] ?? 0), $userId]);
                return ['ok' => $st->rowCount() > 0, 'message' => 'Antrenman yapılmadı olarak kaydedildi'];
        }
        return ['ok' => false, 'error' => 'Bilinmeyen işlem'];
    }
}
