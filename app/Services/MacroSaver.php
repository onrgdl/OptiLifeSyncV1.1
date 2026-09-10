<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * MacroSaver
 *
 * MetabolismCalculator tarafından üretilen makro hedeflerini
 * `macro_targets` tablosuna kaydeder veya günceller.
 *
 * Kullanım:
 *   $calculator = new MetabolismCalculator(80, 175, 28, 'male', 'moderately_active', 'gain');
 *   $saver = new MacroSaver($pdo);
 *   $saver->saveAll($userId, $calculator);
 *
 * @package App\Services
 */
class MacroSaver
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Hem dinlenme hem antrenman günü makrolarını veritabanına kaydeder/günceller.
     * Aynı user_id + day_type kombinasyonu varsa UPDATE, yoksa INSERT yapar (UPSERT).
     *
     * @param int                   $userId
     * @param MetabolismCalculator  $calculator
     * @return void
     */
    public function saveAll(int $userId, MetabolismCalculator $calculator): void
    {
        foreach ($calculator->getAllMacroTargets() as $macros) {
            $this->upsert($userId, $macros);
        }
    }

    /**
     * Tek bir makro satırını kaydeder veya günceller.
     *
     * @param int   $userId
     * @param array $macros buildMacroArray() çıktısı
     */
    private function upsert(int $userId, array $macros): void
    {
        $isPgsql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($isPgsql) {
            $sql = "
                INSERT INTO macro_targets
                    (user_id, day_type, calories, protein_g, carbs_g, fat_g,
                     extra_calories, extra_protein_g, source)
                VALUES
                    (:user_id, :day_type, :calories, :protein_g, :carbs_g, :fat_g,
                     :extra_calories, :extra_protein_g, :source)
                ON CONFLICT (user_id, day_type) DO UPDATE SET
                    calories        = EXCLUDED.calories,
                    protein_g       = EXCLUDED.protein_g,
                    carbs_g         = EXCLUDED.carbs_g,
                    fat_g           = EXCLUDED.fat_g,
                    extra_calories  = EXCLUDED.extra_calories,
                    extra_protein_g = EXCLUDED.extra_protein_g,
                    source          = EXCLUDED.source,
                    updated_at      = CURRENT_TIMESTAMP
            ";
        } else {
            $sql = "
                INSERT INTO macro_targets
                    (user_id, day_type, calories, protein_g, carbs_g, fat_g,
                     extra_calories, extra_protein_g, source)
                VALUES
                    (:user_id, :day_type, :calories, :protein_g, :carbs_g, :fat_g,
                     :extra_calories, :extra_protein_g, :source)
                ON DUPLICATE KEY UPDATE
                    calories        = VALUES(calories),
                    protein_g       = VALUES(protein_g),
                    carbs_g         = VALUES(carbs_g),
                    fat_g           = VALUES(fat_g),
                    extra_calories  = VALUES(extra_calories),
                    extra_protein_g = VALUES(extra_protein_g),
                    source          = VALUES(source),
                    updated_at      = CURRENT_TIMESTAMP
            ";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'         => $userId,
            ':day_type'        => $macros['day_type'],
            ':calories'        => $macros['calories'],
            ':protein_g'       => $macros['protein_g'],
            ':carbs_g'         => $macros['carbs_g'],
            ':fat_g'           => $macros['fat_g'],
            ':extra_calories'  => $macros['extra_calories'],
            ':extra_protein_g' => $macros['extra_protein_g'],
            ':source'          => $macros['source'],
        ]);
    }
}
