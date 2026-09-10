<?php

declare(strict_types=1);

require_once __DIR__ . '/app/Services/MetabolismCalculator.php';
require_once __DIR__ . '/app/Services/MacroSaver.php';

use App\Services\MetabolismCalculator;
use App\Services\MacroSaver;

// -----------------------------------------------------------------
// 1. Kullanıcı profilinden sınıfı oluştur
// -----------------------------------------------------------------

$calculator = new MetabolismCalculator(
    weightKg:      80.0,
    heightCm:      175.0,
    age:           28,
    gender:        'male',
    activityLevel: 'moderately_active', // Haftada 3-5 gün spor
    goal:          'gain'               // Kilo alma hedefi
);

// -----------------------------------------------------------------
// 2. Temel hesaplar
// -----------------------------------------------------------------

echo "BMR  : " . $calculator->getBMR()  . " kcal/gün\n";
echo "TDEE : " . $calculator->getTDEE() . " kcal/gün\n\n";

// -----------------------------------------------------------------
// 3. Bugün antrenman var mı? → Dinamik makroyu döner
// -----------------------------------------------------------------

$isTrainingToday = true; // Bu değer daily_logs tablosundan ya da workout_plans'tan gelir

$macros = $calculator->getDailyMacros(isTrainingDay: $isTrainingToday);

echo "Bugün: " . ($isTrainingToday ? "⚡ Antrenman Günü" : "😴 Dinlenme Günü") . "\n";
echo "Kalori Hedefi : " . $macros['calories'] . " kcal\n";
echo "Protein       : " . $macros['protein_g'] . " g\n";
echo "Karbonhidrat  : " . $macros['carbs_g']   . " g\n";
echo "Yağ           : " . $macros['fat_g']     . " g\n\n";

// -----------------------------------------------------------------
// 4. Tam özet (tüm gün tipleri)
// -----------------------------------------------------------------

$summary = $calculator->getSummary();
echo "=== TAM ÖZET ===\n";
print_r($summary);

// -----------------------------------------------------------------
// 5. Veritabanına kaydet (isteğe bağlı — PDO bağlantısı gerekir)
// -----------------------------------------------------------------

/*
$pdo = new PDO('mysql:host=localhost;dbname=gyp_db;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$saver = new MacroSaver($pdo);
$saver->saveAll(userId: 1, calculator: $calculator);

echo "\n✅ Makro hedefleri veritabanına kaydedildi.\n";
*/
