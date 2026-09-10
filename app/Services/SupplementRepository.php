<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * SupplementRepository
 *
 * Lokal MySQL `supplements` tablosundan takviye ve ilaç verisi çeker.
 * Dış API'ye bağlanmaz; tamamen lokal veritabanı üzerinde çalışır.
 *
 * @package App\Services
 */
class SupplementRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =================================================================
    // PUBLIC: Takviye Arama (Lokal)
    // =================================================================

    /**
     * Kullanıcının supplements tablosunda ada göre arama yapar.
     *
     * Kullanım:
     *   $results = $repo->searchByName(userId: 1, query: 'Whey');
     *
     * @param  int    $userId Kullanıcı ID
     * @param  string $query  Arama terimi (kısmi eşleşme desteklenir)
     * @return array          Takviye kayıtları dizisi
     */
    public function searchByName(int $userId, string $query): array
    {
        $query = trim($query);

        $sql = "
            SELECT
                id,
                name,
                type,
                form,
                dose_amount,
                dose_unit,
                doses_per_day,
                schedule_times,
                calories_per_dose,
                protein_g,
                carbs_g,
                fat_g,
                notes
            FROM supplements
            WHERE user_id   = :user_id
              AND is_active  = 1
              AND name LIKE  :query
            ORDER BY name
            LIMIT 20
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':query'   => '%' . $query . '%',
        ]);

        return $stmt->fetchAll();
    }

    /**
     * ID ile tek bir takviye kaydını çeker.
     *
     * @param  int $supplementId
     * @param  int $userId       Güvenlik: başkasının takviyeleri görünmesin
     * @return array|null        Kayıt yoksa null
     */
    public function findById(int $supplementId, int $userId): ?array
    {
        $sql = "
            SELECT *
            FROM supplements
            WHERE id      = :id
              AND user_id = :user_id
              AND is_active = 1
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $supplementId, ':user_id' => $userId]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Kullanıcının aktif tüm takviyelerini ve ilaçlarını listeler.
     *
     * @param  int    $userId
     * @param  string $type   Filtre: 'supplement','medication','vitamin','mineral',''=hepsi
     * @return array
     */
    public function getAllActive(int $userId, string $type = ''): array
    {
        $sql = "
            SELECT
                id, name, type, form, dose_amount, dose_unit,
                doses_per_day, schedule_times,
                calories_per_dose, protein_g, carbs_g, fat_g
            FROM supplements
            WHERE user_id  = :user_id
              AND is_active = 1
        ";

        $params = [':user_id' => $userId];

        if ($type !== '') {
            $sql .= " AND type = :type";
            $params[':type'] = $type;
        }

        $sql .= " ORDER BY type, name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    // =================================================================
    // PUBLIC: Makro Formatı (Log tablosu ile uyumlu yapı üret)
    // =================================================================

    /**
     * Bir takviye kaydını, gün içi makro toplamlarına eklenebilecek
     * standart formata çevirir.
     *
     * @param  array $supplement findById() veya searchByName() çıktısı
     * @param  int   $doseCount  O gün kaç doz alındı (varsayılan: doses_per_day)
     * @return array             Standart makro dizisi
     */
    public function toMacroEntry(array $supplement, int $doseCount = -1): array
    {
        // Doz sayısı girilmemişse tablodaki günlük doz sayısı kullanılır
        if ($doseCount < 0) {
            $doseCount = (int) ($supplement['doses_per_day'] ?? 1);
        }

        $multiplier = max(1, $doseCount);

        return [
            'supplement_id' => $supplement['id'],
            'food_label'    => $supplement['name'],
            'meal_type'     => 'supplement',
            'quantity'      => $supplement['dose_amount'] * $multiplier,
            'unit'          => $supplement['dose_unit'],
            'source'        => 'local_db',          // API'dan değil, yerelden geldi

            // Doz × alınan doz adedi
            'calories'      => round((float)($supplement['calories_per_dose'] ?? 0) * $multiplier, 1),
            'protein_g'     => round((float)($supplement['protein_g'] ?? 0) * $multiplier, 2),
            'carbs_g'       => round((float)($supplement['carbs_g']   ?? 0) * $multiplier, 2),
            'fat_g'         => round((float)($supplement['fat_g']     ?? 0) * $multiplier, 2),
            'fiber_g'       => 0.0,
        ];
    }
}
