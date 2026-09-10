<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * DailyNutritionTracker
 *
 * Gün içinde tüketilen besinlerin ve takviyelerin makrolarını toplar,
 * MetabolismCalculator tarafından üretilen Dinamik Makro Hedefi ile
 * kıyaslar ve kalan/aşılan miktarları hesaplar.
 *
 * Hibrit Veri Akışı:
 *   ┌──────────────────────┐         ┌────────────────────────┐
 *   │   Gemini AI          │         │ Lokal MySQL supplements │
 *   │   (GeminiService)    │         │ (SupplementRepository)  │
 *   └──────────┬───────────┘         └────────────┬───────────┘
 *              │ analyzeFood()                     │ toMacroEntry()
 *              └────────────────┬──────────────────┘
 *                               │
 *                    DailyNutritionTracker
 *                       addEntry()
 *                               │
 *                    getConsumedTotals()
 *                               │
 *                    getDeficits(macroTarget)
 *                               │
 *                    ┌──────────┴──────────┐
 *                    │  Dashboard / Log    │
 *                    └─────────────────────┘
 *
 * @package App\Services
 */
class DailyNutritionTracker
{
    private PDO $db;

    /**
     * Gün içinde eklenen tüm besin/takviye girişleri.
     * Her eleman GeminiService::analyzeFood()
     * veya SupplementRepository::toMacroEntry() formatındadır.
     *
     * @var array[]
     */
    private array $entries = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =================================================================
    // PUBLIC: Giriş Ekleme
    // =================================================================

    /**
     * Bir besin veya takviye girişini günlük listeye ekler.
     * Kaynak 'gemini_ai' veya 'local_db' olabilir.
     *
     * @param  array $macroEntry GeminiService::analyzeFood() veya toMacroEntry() çıktısı
     * @return self              Method chaining için
     */
    public function addEntry(array $macroEntry): self
    {
        $this->entries[] = $macroEntry;
        return $this;
    }

    /**
     * Birden fazla girişi tek seferde ekler.
     *
     * @param  array[] $entries
     * @return self
     */
    public function addEntries(array $entries): self
    {
        foreach ($entries as $entry) {
            $this->addEntry($entry);
        }
        return $this;
    }

    // =================================================================
    // PUBLIC: Günlük Toplamlar
    // =================================================================

    /**
     * Eklenen tüm girişlerin makro toplamını hesaplar.
     *
     * @return array{
     *     calories: float,
     *     protein_g: float,
     *     carbs_g: float,
     *     fat_g: float,
     *     fiber_g: float,
     *     entry_count: int,
     *     breakdown: array
     * }
     */
    public function getConsumedTotals(): array
    {
        $totals = [
            'calories'    => 0.0,
            'protein_g'   => 0.0,
            'carbs_g'     => 0.0,
            'fat_g'       => 0.0,
            'fiber_g'     => 0.0,
            'entry_count' => count($this->entries),
            'breakdown'   => [],        // Öğün bazında detay
        ];

        foreach ($this->entries as $entry) {
            $totals['calories']  += (float)($entry['calories']  ?? 0);
            $totals['protein_g'] += (float)($entry['protein_g'] ?? 0);
            $totals['carbs_g']   += (float)($entry['carbs_g']   ?? 0);
            $totals['fat_g']     += (float)($entry['fat_g']     ?? 0);
            $totals['fiber_g']   += (float)($entry['fiber_g']   ?? 0);

            // Öğün bazında detay listesi
            $totals['breakdown'][] = [
                'id'        => $entry['id']         ?? null,
                'label'     => $entry['food_label'] ?? '—',
                'meal'      => $entry['meal_type']  ?? 'other',
                'source'    => $entry['source']     ?? 'unknown',
                'calories'  => round((float)($entry['calories']  ?? 0), 1),
                'protein'   => round((float)($entry['protein_g'] ?? 0), 1),
                'carbs'     => round((float)($entry['carbs_g']   ?? 0), 1),
                'fat'       => round((float)($entry['fat_g']     ?? 0), 1),
                'logged_at' => $entry['logged_at']  ?? null,
            ];
        }

        // Virgül sonrası temizle
        $totals['calories']  = round($totals['calories'],  1);
        $totals['protein_g'] = round($totals['protein_g'], 2);
        $totals['carbs_g']   = round($totals['carbs_g'],   2);
        $totals['fat_g']     = round($totals['fat_g'],     2);
        $totals['fiber_g']   = round($totals['fiber_g'],   2);

        return $totals;
    }

    // =================================================================
    // PUBLIC: Dinamik Makro Hedefine Göre Eksik / Fazla Hesapla
    // =================================================================

    /**
     * Tüketilen makroları Dinamik Makro Hedefi ile kıyaslar.
     *
     * Pozitif değer = o makrodan EKSİK kalan miktar
     * Negatif değer = o makrodan AŞILAN miktar
     *
     * @param  array $macroTarget  MetabolismCalculator::getDailyMacros() çıktısı
     *                             (veya macro_targets tablosundan çekilen kayıt)
     * @return array{
     *     target: array,
     *     consumed: array,
     *     remaining: array,
     *     progress_pct: array,
     *     status: array,
     *     is_training_day: bool,
     *     summary_message: string
     * }
     */
    public function getDeficits(array $macroTarget): array
    {
        $consumed = $this->getConsumedTotals();

        // Hedef değerler
        $targetCalories = (float)($macroTarget['calories']  ?? 0);
        $targetProtein  = (float)($macroTarget['protein_g'] ?? 0);
        $targetCarbs    = (float)($macroTarget['carbs_g']   ?? 0);
        $targetFat      = (float)($macroTarget['fat_g']     ?? 0);

        // Kalan = Hedef - Tüketilen (negatif = aşıldı)
        $remainingCalories = round($targetCalories - $consumed['calories'],  1);
        $remainingProtein  = round($targetProtein  - $consumed['protein_g'], 1);
        $remainingCarbs    = round($targetCarbs    - $consumed['carbs_g'],   1);
        $remainingFat      = round($targetFat      - $consumed['fat_g'],     1);

        // İlerleme yüzdesi (max %100 gösterge için cap uygulanmaz, aşım görünür)
        $pctCalories = $targetCalories > 0 ? round(($consumed['calories']  / $targetCalories) * 100, 1) : 0.0;
        $pctProtein  = $targetProtein  > 0 ? round(($consumed['protein_g'] / $targetProtein)  * 100, 1) : 0.0;
        $pctCarbs    = $targetCarbs    > 0 ? round(($consumed['carbs_g']   / $targetCarbs)    * 100, 1) : 0.0;
        $pctFat      = $targetFat      > 0 ? round(($consumed['fat_g']     / $targetFat)      * 100, 1) : 0.0;

        // Durum etiketi: 'deficit' | 'on_track' | 'over'
        $statusCalories = $this->statusLabel($remainingCalories);
        $statusProtein  = $this->statusLabel($remainingProtein);
        $statusCarbs    = $this->statusLabel($remainingCarbs);
        $statusFat      = $this->statusLabel($remainingFat);

        // Genel özet mesajı
        $summaryMessage = $this->buildSummaryMessage($remainingCalories, $remainingProtein);

        return [
            'is_training_day' => ($macroTarget['day_type'] ?? 'rest') === 'training',

            'target' => [
                'calories'  => $targetCalories,
                'protein_g' => $targetProtein,
                'carbs_g'   => $targetCarbs,
                'fat_g'     => $targetFat,
            ],

            'consumed' => [
                'calories'  => $consumed['calories'],
                'protein_g' => $consumed['protein_g'],
                'carbs_g'   => $consumed['carbs_g'],
                'fat_g'     => $consumed['fat_g'],
                'fiber_g'   => $consumed['fiber_g'],
            ],

            'remaining' => [
                'calories'  => $remainingCalories,
                'protein_g' => $remainingProtein,
                'carbs_g'   => $remainingCarbs,
                'fat_g'     => $remainingFat,
            ],

            'progress_pct' => [
                'calories'  => $pctCalories,
                'protein_g' => $pctProtein,
                'carbs_g'   => $pctCarbs,
                'fat_g'     => $pctFat,
            ],

            'status' => [
                'calories'  => $statusCalories,
                'protein_g' => $statusProtein,
                'carbs_g'   => $statusCarbs,
                'fat_g'     => $statusFat,
            ],

            'entry_breakdown' => $consumed['breakdown'],
            'entry_count'     => $consumed['entry_count'],
            'summary_message' => $summaryMessage,
        ];
    }

    // =================================================================
    // PUBLIC: Veritabanı Kaydetme
    // =================================================================

    /**
     * Eklenen tüm girişleri günlük log tablosuna kaydeder.
     * Önce daily_logs başlığını UPSERT eder, sonra food_logs ve
     * supplement_logs tablolarını doldurur.
     *
     * @param  int    $userId
     * @param  string $date     'Y-m-d' formatında (varsayılan: bugün)
     * @param  bool   $workoutDone Antrenman yapıldı mı?
     * @return int               Oluşturulan/güncellenen daily_log ID
     */
    public function saveToDatabase(int $userId, string $date = '', bool $workoutDone = false): int
    {
        if ($date === '') {
            $date = date('Y-m-d');
        }

        $totals = $this->getConsumedTotals();

        // daily_logs başlığını oluştur veya güncelle
        $dailyLogId = $this->upsertDailyLog($userId, $date, $totals, $workoutDone);

        // Her girişi uygun log tablosuna kaydet
        foreach ($this->entries as $entry) {
            if (($entry['source'] ?? '') === 'local_db') {
                $this->insertSupplementLog($dailyLogId, $entry);
            } else {
                $this->insertFoodLog($dailyLogId, $entry);
            }
        }

        return $dailyLogId;
    }

    // =================================================================
    // PRIVATE: Veritabanı Yardımcıları
    // =================================================================

    /**
     * daily_logs tablosunu UPSERT eder.
     */
    private function upsertDailyLog(
        int    $userId,
        string $date,
        array  $totals,
        bool   $workoutDone
    ): int {
        $isPgsql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($isPgsql) {
            $sql = "
                INSERT INTO daily_logs
                    (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done)
                VALUES
                    (:user_id, :log_date, :calories, :protein_g, :carbs_g, :fat_g, :workout_done)
                ON CONFLICT (user_id, log_date) DO UPDATE SET
                    total_calories  = EXCLUDED.total_calories,
                    total_protein_g = EXCLUDED.total_protein_g,
                    total_carbs_g   = EXCLUDED.total_carbs_g,
                    total_fat_g     = EXCLUDED.total_fat_g,
                    workout_done    = EXCLUDED.workout_done,
                    updated_at      = CURRENT_TIMESTAMP
            ";
        } else {
            $sql = "
                INSERT INTO daily_logs
                    (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done)
                VALUES
                    (:user_id, :log_date, :calories, :protein_g, :carbs_g, :fat_g, :workout_done)
                ON DUPLICATE KEY UPDATE
                    total_calories  = VALUES(total_calories),
                    total_protein_g = VALUES(total_protein_g),
                    total_carbs_g   = VALUES(total_carbs_g),
                    total_fat_g     = VALUES(total_fat_g),
                    workout_done    = VALUES(workout_done),
                    updated_at      = CURRENT_TIMESTAMP
            ";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'      => $userId,
            ':log_date'     => $date,
            ':calories'     => $totals['calories'],
            ':protein_g'    => $totals['protein_g'],
            ':carbs_g'      => $totals['carbs_g'],
            ':fat_g'        => $totals['fat_g'],
            ':workout_done' => $workoutDone ? 1 : 0,
        ]);

        // UPSERT sonrası ID'yi al
        $idSql  = "SELECT id FROM daily_logs WHERE user_id = ? AND log_date = ? LIMIT 1";
        $idStmt = $this->db->prepare($idSql);
        $idStmt->execute([$userId, $date]);

        return (int) $idStmt->fetchColumn();
    }

    /** food_logs tablosuna API besin girişi ekler. */
    private function insertFoodLog(int $dailyLogId, array $entry): void
    {
        $sql = "
            INSERT INTO food_logs
                (daily_log_id, meal_type, food_id, food_label, quantity, unit,
                 calories, protein_g, carbs_g, fat_g, fiber_g)
            VALUES
                (:daily_log_id, :meal_type, :food_id, :food_label, :quantity, :unit,
                 :calories, :protein_g, :carbs_g, :fat_g, :fiber_g)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':daily_log_id' => $dailyLogId,
            ':meal_type'    => $entry['meal_type']  ?? 'snack',
            ':food_id'      => $entry['food_id']    ?? null,
            ':food_label'   => $entry['food_label'] ?? 'Bilinmeyen',
            ':quantity'     => $entry['quantity']   ?? 0,
            ':unit'         => $entry['unit']       ?? 'gram',
            ':calories'     => $entry['calories']   ?? 0,
            ':protein_g'    => $entry['protein_g']  ?? 0,
            ':carbs_g'      => $entry['carbs_g']    ?? 0,
            ':fat_g'        => $entry['fat_g']      ?? 0,
            ':fiber_g'      => $entry['fiber_g']    ?? 0,
        ]);
    }

    /** supplement_logs tablosuna lokal takviye girişi ekler. */
    private function insertSupplementLog(int $dailyLogId, array $entry): void
    {
        $sql = "
            INSERT INTO supplement_logs
                (daily_log_id, supplement_id, taken_at, dose_taken, dose_unit, is_taken)
            VALUES
                (:daily_log_id, :supplement_id, :taken_at, :dose_taken, :dose_unit, 1)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':daily_log_id'  => $dailyLogId,
            ':supplement_id' => $entry['supplement_id'] ?? 0,
            ':taken_at'      => date('H:i:s'),
            ':dose_taken'    => $entry['quantity'] ?? 0,
            ':dose_unit'     => $entry['unit']     ?? 'mg',
        ]);
    }

    // =================================================================
    // PRIVATE: Yardımcı Metodlar
    // =================================================================

    /**
     * Kalan değere göre durum etiketi üretir.
     *
     * @param  float  $remaining Kalan miktar (negatif = aşıldı)
     * @return string 'deficit' | 'on_track' | 'over'
     */
    private function statusLabel(float $remaining): string
    {
        if ($remaining > 50) {
            return 'deficit';      // Yetersiz tüketim
        } elseif ($remaining >= -50) {
            return 'on_track';     // ±50 tolerans içinde: hedefe ulaşıldı
        } else {
            return 'over';         // Aşıldı
        }
    }

    /**
     * Kalori ve protein durumuna göre kısa özet mesajı üretir.
     */
    private function buildSummaryMessage(float $remCalories, float $remProtein): string
    {
        $parts = [];

        if ($remCalories > 50) {
            $parts[] = abs($remCalories) . " kcal daha tüketin";
        } elseif ($remCalories < -50) {
            $parts[] = abs($remCalories) . " kcal hedefinizi aştınız";
        } else {
            $parts[] = "Kalori hedefine ulaştınız ✅";
        }

        if ($remProtein > 5) {
            $parts[] = abs($remProtein) . "g daha protein almanız gerekiyor";
        } elseif ($remProtein < -5) {
            $parts[] = "Protein hedefinizi " . abs($remProtein) . "g aştınız";
        } else {
            $parts[] = "Protein hedefine ulaştınız ✅";
        }

        return implode(" • ", $parts);
    }
}
