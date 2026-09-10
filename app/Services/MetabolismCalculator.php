<?php

declare(strict_types=1);

namespace App\Services;

/**
 * MetabolismCalculator
 *
 * Mifflin-St Jeor formülünü kullanarak BMR (Bazal Metabolizma Hızı)
 * ve TDEE (Toplam Günlük Enerji Harcaması) hesaplar.
 *
 * Antrenman günleri için dinamik makro hedefi üretir:
 *   Antrenman günü kalori hedefi = TDEE + 400 kcal
 *   Antrenman günü protein hedefi = hesaplanan protein + 30g
 *
 * @package App\Services
 */
class MetabolismCalculator
{
    // -----------------------------------------------------------------
    // Mifflin-St Jeor Formül Sabitleri
    // -----------------------------------------------------------------

    /** Erkek BMR'ye eklenen sabit */
    private const MALE_CONSTANT   = 5.0;

    /** Kadın BMR'den çıkarılan sabit */
    private const FEMALE_CONSTANT = -161.0;

    // -----------------------------------------------------------------
    // Aktivite Düzeyi Çarpanları (Harris-Benedict sınıflandırması)
    // -----------------------------------------------------------------

    private const ACTIVITY_MULTIPLIERS = [
        'sedentary'         => 1.2,    // Hareketsiz (masa başı iş, spor yok)
        'lightly_active'    => 1.375,  // Hafif aktif (haftada 1–3 gün spor)
        'moderately_active' => 1.55,   // Orta aktif (haftada 3–5 gün spor)
        'very_active'       => 1.725,  // Çok aktif (haftada 6–7 gün ağır spor)
        'extra_active'      => 1.9,    // Ekstra aktif (profesyonel sporcu)
    ];

    // -----------------------------------------------------------------
    // Dinlenme/Antrenman Günü Hedef Makro Oranları
    // (kalori dağılımı: protein 30%, karbonhidrat 45%, yağ 25%)
    // -----------------------------------------------------------------

    /** 1g protein = 4 kcal */
    private const KCAL_PER_GRAM_PROTEIN = 4.0;

    /** 1g karbonhidrat = 4 kcal */
    private const KCAL_PER_GRAM_CARB    = 4.0;

    /** 1g yağ = 9 kcal */
    private const KCAL_PER_GRAM_FAT     = 9.0;

    /** Proteinin toplam kaloriden aldığı pay */
    private const PROTEIN_RATIO = 0.30;

    /** Karbonhidratın toplam kaloriden aldığı pay */
    private const CARB_RATIO    = 0.45;

    /** Yağın toplam kaloriden aldığı pay */
    private const FAT_RATIO     = 0.25;

    // -----------------------------------------------------------------
    // Antrenman Günü Ekstra Değerler (dinamik makro)
    // -----------------------------------------------------------------

    /** Antrenman gününde TDEE'ye eklenecek ekstra kalori */
    private const TRAINING_EXTRA_CALORIES = 400;

    /** Antrenman gününde protein hedefine eklenecek ekstra gram */
    private const TRAINING_EXTRA_PROTEIN  = 30.0;

    // -----------------------------------------------------------------
    // Hedef (Goal) Kalori Düzeltme Miktarları
    // -----------------------------------------------------------------

    /** Kilo verme hedefi için günlük kalori açığı */
    private const GOAL_LOSE_DEFICIT  = -500;

    /** Kilo alma hedefi için günlük kalori fazlası */
    private const GOAL_GAIN_SURPLUS  = +300;

    // -----------------------------------------------------------------
    // Sınıf Özellikleri (Kullanıcı verileri)
    // -----------------------------------------------------------------

    private float  $weightKg;       // Mevcut kilo (kg)
    private float  $heightCm;       // Boy (cm)
    private int    $age;            // Yaş (yıl)
    private string $gender;         // 'male' | 'female' | 'other'
    private string $activityLevel;  // Aktivite düzeyi (const anahtarları)
    private string $goal;           // 'lose' | 'gain' | 'maintain'

    // -----------------------------------------------------------------
    // Hesaplanan Değerler (cache)
    // -----------------------------------------------------------------

    private ?float $bmr  = null;
    private ?float $tdee = null;

    /**
     * Constructor — kullanıcı profili ile başlatılır.
     *
     * @param float  $weightKg      Kilogram cinsinden ağırlık (ör: 80.5)
     * @param float  $heightCm      Santimetre cinsinden boy (ör: 175)
     * @param int    $age           Yaş (ör: 28)
     * @param string $gender        'male' | 'female' | 'other'
     * @param string $activityLevel Aktivite düzeyi (Activity Multipliers anahtarı)
     * @param string $goal          'lose' | 'gain' | 'maintain'
     *
     * @throws \InvalidArgumentException Geçersiz parametre verilirse
     */
    public function __construct(
        float  $weightKg,
        float  $heightCm,
        int    $age,
        string $gender        = 'male',
        string $activityLevel = 'sedentary',
        string $goal          = 'maintain'
    ) {
        $this->validateInputs($weightKg, $heightCm, $age, $gender, $activityLevel, $goal);

        $this->weightKg     = $weightKg;
        $this->heightCm     = $heightCm;
        $this->age          = $age;
        $this->gender       = strtolower($gender);
        $this->activityLevel= strtolower($activityLevel);
        $this->goal         = strtolower($goal);
    }

    // =================================================================
    // PUBLIC API
    // =================================================================

    /**
     * BMR'yi hesaplayıp döner (Mifflin-St Jeor).
     *
     * Formül:
     *   Erkek  : (10 × kg) + (6.25 × cm) - (5 × yaş) + 5
     *   Kadın  : (10 × kg) + (6.25 × cm) - (5 × yaş) - 161
     *   Diğer  : Erkek ve kadın ortalaması alınır
     *
     * @return float Yuvarlak BMR değeri (kcal/gün)
     */
    public function getBMR(): float
    {
        if ($this->bmr !== null) {
            return $this->bmr; // Önbellekten döner
        }

        $base = (10 * $this->weightKg)
              + (6.25 * $this->heightCm)
              - (5    * $this->age);

        switch ($this->gender) {
            case 'male':
                $this->bmr = $base + self::MALE_CONSTANT;
                break;

            case 'female':
                $this->bmr = $base + self::FEMALE_CONSTANT;
                break;

            default: // 'other' — cinsiyetsiz yaklaşım: iki formülün ortası
                $maleBMR   = $base + self::MALE_CONSTANT;
                $femaleBMR = $base + self::FEMALE_CONSTANT;
                $this->bmr = ($maleBMR + $femaleBMR) / 2;
                break;
        }

        return round($this->bmr, 2);
    }

    /**
     * TDEE'yi hesaplayıp döner (BMR × Aktivite Çarpanı).
     * Bilimsel olarak kişinin kilosunu sabit tuttuğu Günlük Toplam Enerji Harcaması / Bakım Kalorisidir.
     *
     * @return float TDEE (kcal/gün)
     */
    public function getTDEE(): float
    {
        if ($this->tdee !== null) {
            return $this->tdee;
        }

        $multiplier = self::ACTIVITY_MULTIPLIERS[$this->activityLevel] ?? 1.2;
        $this->tdee = round($this->getBMR() * $multiplier, 2);

        return $this->tdee;
    }

    /**
     * Hedefe (kilo ver: -500 kcal / kilo al: +300 kcal / koru: 0) göre net günlük kalori hedefini döner.
     * TDEE üzerinden tam matematiksel ekleme/çıkarma uygular.
     *
     * @return float Günlük Hedef Kalori (kcal/gün)
     */
    public function getTargetCalories(): float
    {
        $rawTDEE = $this->getTDEE();

        $adjustment = match ($this->goal) {
            'lose'  => self::GOAL_LOSE_DEFICIT, // -500 kcal
            'gain'  => self::GOAL_GAIN_SURPLUS, // +300 kcal
            default => 0,
        };

        $target = $rawTDEE + $adjustment;

        return round(max(500.0, $target), 2);
    }

    /**
     * Dinlenme günü makro hedeflerini döner.
     *
     * @return array
     */
    public function getRestDayMacros(): array
    {
        return $this->buildMacroArray('rest', $this->getTargetCalories());
    }

    /**
     * Günlük standart makro hedeflerini döner (Antrenman günü / dinlenme günü ayrımı olmadan sabit).
     *
     * @return array Makro hedef dizisi
     */
    public function getTrainingDayMacros(): array
    {
        return $this->buildMacroArray('training', $this->getTargetCalories());
    }

    /**
     * Günlük aktif sabit makroları döner.
     * Kullanıcılar için her gün istikrarlı, sürdürülebilir ve net tek bir hedef sunar.
     *
     * @param bool|null $isTrainingDay
     * @return array Makro hedef dizisi
     */
    public function getDailyMacros(?bool $isTrainingDay = null): array
    {
        return $this->buildMacroArray('daily', $this->getTargetCalories());
    }

    /**
     * Veritabanına kaydetmeye hazır makro hedef satırlarını döner.
     * Her iki gün tipine de sabit ve tutarlı günlük hedefleri atar.
     *
     * @return array{rest: array, training: array}
     */
    public function getAllMacroTargets(): array
    {
        $daily = $this->getDailyMacros();
        return [
            'rest'     => array_merge($daily, ['day_type' => 'rest']),
            'training' => array_merge($daily, ['day_type' => 'training']),
        ];
    }

    /**
     * Hesaplanan tüm değerlerin özetini döner.
     *
     * @return array{
     *     bmr: float,
     *     tdee: float,
     *     target_calories: int,
     *     goal: string,
     *     goal_adjustment: int,
     *     activity_level: string,
     *     activity_multiplier: float,
     *     macros: array,
     *     rest_day: array,
     *     training_day: array
     * }
     */
    public function getSummary(): array
    {
        $adjustment = match ($this->goal) {
            'lose'    => self::GOAL_LOSE_DEFICIT,
            'gain'    => self::GOAL_GAIN_SURPLUS,
            default   => 0,
        };

        $daily = $this->getDailyMacros();

        return [
            'bmr'                => $this->getBMR(),
            'tdee'               => $this->getTDEE(),
            'target_calories'    => (int) round($this->getTargetCalories()),
            'goal'               => $this->goal,
            'goal_adjustment'    => $adjustment,
            'activity_level'     => $this->activityLevel,
            'activity_multiplier'=> self::ACTIVITY_MULTIPLIERS[$this->activityLevel] ?? 1.2,
            'macros'             => $daily,
            'rest_day'           => $this->getRestDayMacros(),
            'training_day'       => $this->getTrainingDayMacros(),
        ];
    }

    // =================================================================
    // PRIVATE HELPERS
    // =================================================================

    /**
     * Gün tipine ve kalori miktarına göre makro dizisi oluşturur.
     *
     * @param string $dayType   'rest' | 'training'
     * @param float  $calories  Toplam hedef kalori
     * @return array
     */
    private function buildMacroArray(string $dayType, float $calories): array
    {
        /*
         * Makro dağılımı oranlarına göre gram hesabı:
         *   protein_g = (kalori × 0.30) / 4
         *   carbs_g   = (kalori × 0.45) / 4
         *   fat_g     = (kalori × 0.25) / 9
         */
        $proteinG = round(($calories * self::PROTEIN_RATIO) / self::KCAL_PER_GRAM_PROTEIN, 1);
        $carbsG   = round(($calories * self::CARB_RATIO)    / self::KCAL_PER_GRAM_CARB,    1);
        $fatG     = round(($calories * self::FAT_RATIO)      / self::KCAL_PER_GRAM_FAT,     1);

        return [
            'day_type'       => $dayType,
            'calories'       => (int) round($calories),
            'protein_g'      => $proteinG,
            'carbs_g'        => $carbsG,
            'fat_g'          => $fatG,
            'extra_calories' => 0,      // Antrenman metodu tarafından override edilir
            'extra_protein_g'=> 0.0,    // Antrenman metodu tarafından override edilir
            'source'         => 'auto',
        ];
    }

    /**
     * Girdi doğrulaması.
     *
     * @throws \InvalidArgumentException
     */
    private function validateInputs(
        float  $weightKg,
        float  $heightCm,
        int    $age,
        string $gender,
        string $activityLevel,
        string $goal
    ): void {
        if ($weightKg <= 0 || $weightKg > 500) {
            throw new \InvalidArgumentException("Ağırlık 0–500 kg arasında olmalıdır. Verilen: {$weightKg}");
        }

        if ($heightCm <= 0 || $heightCm > 300) {
            throw new \InvalidArgumentException("Boy 0–300 cm arasında olmalıdır. Verilen: {$heightCm}");
        }

        if ($age <= 0 || $age > 120) {
            throw new \InvalidArgumentException("Yaş 1–120 arasında olmalıdır. Verilen: {$age}");
        }

        $validGenders = ['male', 'female', 'other'];
        if (!in_array(strtolower($gender), $validGenders, true)) {
            throw new \InvalidArgumentException("Geçersiz cinsiyet. Kabul edilenler: " . implode(', ', $validGenders));
        }

        $validActivities = array_keys(self::ACTIVITY_MULTIPLIERS);
        if (!in_array(strtolower($activityLevel), $validActivities, true)) {
            throw new \InvalidArgumentException("Geçersiz aktivite seviyesi. Kabul edilenler: " . implode(', ', $validActivities));
        }

        $validGoals = ['lose', 'gain', 'maintain'];
        if (!in_array(strtolower($goal), $validGoals, true)) {
            throw new \InvalidArgumentException("Geçersiz hedef. Kabul edilenler: " . implode(', ', $validGoals));
        }
    }
}
