<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use DateTime;

/**
 * ReminderService
 *
 * OptiLifeSync - İlaç & Takviye Alarm Yönetim Servisi
 *
 * Sorumluluklar:
 * 1. supplements tablosu (ilaçlar, vitaminler, takviyeler, kullanım süresi / kür bitiş tarihi)
 * 2. reminders tablosu (günün saatlerine göre kurulan alarmlar)
 * 3. Otomatik Süre Dolumu (15 günlük kür bittiğinde alarmın otomatik kapanması)
 * 4. İlaç Bitirme / Alarm Silme: İlaç veya alarm silindiğinde Dashboard dahil her yerden anında kalkması
 */
class ReminderService
{
    private PDO $db;

    // Uyarı penceresi (kaç dakika öncesinden due sayılsın)
    private const DUE_WINDOW_MINUTES = 5;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =================================================================
    // TAKVİYE / İLAÇ SCHEDULE YÖNETİMİ
    // =================================================================

    /**
     * Bir takviyenin schedule_times alanını günceller ve
     * reminders tablosunu eşzamanlı olarak yeniden senkronize eder.
     *
     * @param  int      $supplementId
     * @param  int      $userId
     * @param  string[] $times  ['08:00', '14:00'] formatında saat dizisi
     * @return bool
     */
    public function updateSchedule(int $supplementId, int $userId, array $times): bool
    {
        $validated = [];
        foreach ($times as $t) {
            $trimmed = trim($t);
            if (preg_match('/^\d{1,2}:\d{2}$/', $trimmed)) {
                $validated[] = strlen($trimmed) === 4 ? '0' . $trimmed : $trimmed;
            }
        }
        sort($validated);

        $json = json_encode($validated, JSON_UNESCAPED_UNICODE);

        // 1. supplements tablosunu güncelle
        $stmt = $this->db->prepare("
            UPDATE supplements
               SET schedule_times = :times,
                   updated_at     = CURRENT_TIMESTAMP
             WHERE id      = :id
               AND user_id = :user_id
        ");
        $stmt->execute([':times' => $json, ':id' => $supplementId, ':user_id' => $userId]);

        // 2. reminders tablosunu senkronize et
        $this->syncRemindersForSupplement($supplementId, $userId, $validated);

        return true;
    }

    /**
     * supplements.schedule_times ile reminders tablosunu senkronize eder.
     * Mevcut reminder'ları siler, geçerlilik tarihleriyle (start_date, end_date) yenilerini ekler.
     *
     * @param  int      $supplementId
     * @param  int      $userId
     * @param  string[] $times
     */
    public function syncRemindersForSupplement(int $supplementId, int $userId, array $times): void
    {
        // 1. Önce bu takviyeye ait eski reminder'ları temizle
        $del = $this->db->prepare("
            DELETE FROM reminders
             WHERE supplement_id = :supp_id
               AND user_id       = :user_id
        ");
        $del->execute([':supp_id' => $supplementId, ':user_id' => $userId]);

        if (empty($times)) {
            return;
        }

        // 2. Takviye bilgilerini ve geçerlilik tarihlerini al
        $nameStmt = $this->db->prepare("
            SELECT name, type, start_date, end_date, is_active
            FROM supplements
            WHERE id = ? AND user_id = ?
        ");
        $nameStmt->execute([$supplementId, $userId]);
        $supp = $nameStmt->fetch(PDO::FETCH_ASSOC);
        if (!$supp || (int)$supp['is_active'] === 0) {
            // İlaç bitmiş veya pasif ise alarm oluşturma
            return;
        }

        $label = $supp['name'];
        $type = match ($supp['type'] ?? 'supplement') {
            'medication' => 'medication',
            'supplement' => 'supplement',
            'vitamin'    => 'supplement',
            'mineral'    => 'supplement',
            default      => 'supplement',
        };

        // 3. Her saat için reminder satırı ekle
        $ins = $this->db->prepare("
            INSERT INTO reminders
                (user_id, supplement_id, type, label, remind_at, start_date, end_date, days_of_week, is_active)
            VALUES
                (:user_id, :supp_id, :type, :label, :remind_at, :start_date, :end_date, :days, 1)
        ");

        foreach ($times as $time) {
            $ins->execute([
                ':user_id'    => $userId,
                ':supp_id'    => $supplementId,
                ':type'       => $type,
                ':label'      => $label . ' — ' . $time,
                ':remind_at'  => $time . ':00',
                ':start_date' => $supp['start_date'] ?: date('Y-m-d'),
                ':end_date'   => $supp['end_date'] ?: null,
                ':days'       => json_encode([0, 1, 2, 3, 4, 5, 6]), // Her gün
            ]);
        }
    }

    // =================================================================
    // ZAMANINDA GELECEK ALARM SORGULAMA (JS Polling / Bildirimler)
    // =================================================================

    /**
     * Şu anki saatten DUE_WINDOW_MINUTES dakika içinde çalacak aktif reminder'ları döner.
     * Süresi dolmuş (end_date < bugün) veya pasif yapılmış alarmları otomatik eler.
     *
     * @param  int    $userId
     * @return array  Due olan reminder kayıtları
     */
    /**
     * Tam şu anki dakikada (SS:DD) çalması gereken aktif reminder'ları döner.
     * Alarm kurulur kurulmaz tetiklenmez, yalnızca saati ve dakikası tam geldiğinde çalar.
     *
     * @param  int    $userId
     * @return array  Due olan reminder kayıtları
     */
    public function getDueReminders(int $userId): array
    {
        $now        = new DateTime('now');
        $minStart   = date('H:i:00', strtotime('-1 minute'));
        $minEnd     = date('H:i:59', strtotime('+1 minute'));
        $todayIndex = (int) $now->format('w'); // 0=Pazar … 6=Cumartesi
        $todayDate  = $now->format('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT
                r.id,
                r.supplement_id,
                r.type,
                r.label,
                r.remind_at,
                r.days_of_week,
                s.dose_amount,
                s.dose_unit,
                s.form
            FROM reminders r
            LEFT JOIN supplements s ON s.id = r.supplement_id
            WHERE r.user_id = :user_id
              AND r.is_active = 1
              AND (r.end_date IS NULL OR r.end_date >= :today)
              AND (s.id IS NULL OR (s.is_active = 1 AND (s.end_date IS NULL OR s.end_date >= :today2)))
              AND r.remind_at BETWEEN :min_start AND :min_end
        ");
        $stmt->execute([
            ':user_id'   => $userId,
            ':today'     => $todayDate,
            ':today2'    => $todayDate,
            ':min_start' => $minStart,
            ':min_end'   => $minEnd,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $due  = [];

        foreach ($rows as $row) {
            $days = json_decode($row['days_of_week'] ?? '[]', true);
            if (!in_array($todayIndex, $days, true)) {
                continue;
            }

            $due[] = [
                'id'          => (int) $row['id'],
                'type'        => $row['type'],
                'label'       => $row['label'],
                'remind_at'   => substr($row['remind_at'], 0, 5),
                'dose'        => ($row['dose_amount'] ?? '') . ' ' . ($row['dose_unit'] ?? ''),
                'form'        => $row['form'] ?? 'tablet',
                'minutes_left'=> 0,
            ];
        }

        return $due;
    }

    /**
     * Bir saatin şu an kaç dakika sonraya denk geldiğini hesaplar.
     */
    private function minutesUntil(string $timeStr): int
    {
        $now    = new DateTime('now');
        $target = new DateTime(date('Y-m-d') . ' ' . $timeStr);
        $diff   = $target->getTimestamp() - $now->getTimestamp();
        return (int) max(0, floor($diff / 60));
    }

    // =================================================================
    // LİSTELEME
    // =================================================================

    /**
     * Kullanıcının tüm takviye/ilaçlarını schedule_times ve kalan gün bilgisiyle döner.
     *
     * @param  int   $userId
     * @return array
     */
    public function getSupplementsWithSchedule(int $userId): array
    {
        $today = date('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT
                id, name, type, form,
                dose_amount, dose_unit, doses_per_day,
                schedule_times, start_date, end_date, duration_days,
                is_active, notes, created_at
            FROM supplements
            WHERE user_id = :user_id
            ORDER BY is_active DESC, type, name
        ");
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['schedule_times'] = json_decode($row['schedule_times'] ?? '[]', true) ?? [];
            
            // Süre dolumu kontrolü
            $isExpired = false;
            $remainingDays = null;

            if (!empty($row['end_date'])) {
                $endDateTs = strtotime($row['end_date']);
                $todayTs   = strtotime($today);
                $diffDays  = (int) ceil(($endDateTs - $todayTs) / 86400);

                if ($diffDays < 0) {
                    $isExpired = true;
                    $remainingDays = 0;
                    // Eğer veritabanında hala aktif görünüyorsa otomatik pasife al
                    if ((int)$row['is_active'] === 1) {
                        $this->finishSupplement((int)$row['id'], $userId);
                        $row['is_active'] = 0;
                    }
                } else {
                    $remainingDays = $diffDays;
                }
            }

            $row['is_expired']      = $isExpired;
            $row['remaining_days']  = $remainingDays;
        }

        return $rows;
    }

    // =================================================================
    // EKLEME / DÜZENLEME / BİTİRME / SİLME
    // =================================================================

    /**
     * Yeni bir takviye / ilaç ekler (Kullanım süresi desteğiyle).
     *
     * @param  int   $userId
     * @param  array $data   Form verisi
     * @return int   Oluşturulan ID
     */
    public function addSupplement(int $userId, array $data): int
    {
        $durationDays = !empty($data['duration_days']) ? (int)$data['duration_days'] : null;
        $startDate    = !empty($data['start_date']) ? $data['start_date'] : date('Y-m-d');
        $endDate      = null;

        if ($durationDays !== null && $durationDays > 0) {
            $endDate = date('Y-m-d', strtotime("{$startDate} +{$durationDays} days"));
        } elseif (!empty($data['end_date'])) {
            $endDate = $data['end_date'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO supplements
                (user_id, name, type, form, dose_amount, dose_unit,
                 doses_per_day, schedule_times, start_date, end_date, duration_days,
                 calories_per_dose, protein_g, carbs_g, fat_g, notes, is_active)
            VALUES
                (:user_id, :name, :type, :form, :dose_amount, :dose_unit,
                 :doses_per_day, '[]', :start_date, :end_date, :duration_days,
                 0, 0, 0, 0, :notes, 1)
        ");
        $stmt->execute([
            ':user_id'       => $userId,
            ':name'          => trim($data['name'] ?? ''),
            ':type'          => $data['type']          ?? 'supplement',
            ':form'          => $data['form']          ?? 'tablet',
            ':dose_amount'   => (float)($data['dose_amount'] ?? 0),
            ':dose_unit'     => trim($data['dose_unit'] ?? 'mg'),
            ':doses_per_day' => (int)($data['doses_per_day'] ?? 1),
            ':start_date'    => $startDate,
            ':end_date'      => $endDate,
            ':duration_days' => $durationDays,
            ':notes'         => trim($data['notes'] ?? ''),
        ]);

        return function_exists('dbLastInsertId')
            ? dbLastInsertId($this->db, 'supplements')
            : (int) $this->db->lastInsertId();
    }

    /**
     * İlaç veya takviyeyi siler.
     * İLİŞKİLİ TÜM ALARMLARI DA reminders TABLOSUNDAN KESİN OLARAK SİLER.
     * Bu sayede Dashboard ve polling'de asla hayalet alarm kalmaz.
     *
     * @param  int  $supplementId
     * @param  int  $userId
     * @return bool
     */
    public function deleteSupplement(int $supplementId, int $userId): bool
    {
        // 1. Önce bağlı tüm reminder'ları kesin olarak sil
        $delRem = $this->db->prepare("DELETE FROM reminders WHERE supplement_id = :id AND user_id = :user_id");
        $delRem->execute([':id' => $supplementId, ':user_id' => $userId]);

        // 2. Takviyeyi sil
        $stmt = $this->db->prepare("DELETE FROM supplements WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $supplementId, ':user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * İlacı / Takviyeyi "Bitti / Tedavi Tamamlandı" olarak işaretler.
     * supplements.is_active = 0 yapar ve tüm alarmlarını kaldırır.
     *
     * @param  int  $supplementId
     * @param  int  $userId
     * @return bool
     */
    public function finishSupplement(int $supplementId, int $userId): bool
    {
        // 1. Alarmlarını sil (dashboard ve polling'den anında kalkar)
        $delRem = $this->db->prepare("DELETE FROM reminders WHERE supplement_id = :id AND user_id = :user_id");
        $delRem->execute([':id' => $supplementId, ':user_id' => $userId]);

        // 2. supplements kaydını pasif yap ve bitiş tarihini bugüne çek
        $stmt = $this->db->prepare("
            UPDATE supplements
            SET is_active = 0,
                end_date  = CURRENT_DATE,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND user_id = :user_id
        ");
        $stmt->execute([':id' => $supplementId, ':user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Tek bir alarm satırını doğrudan siler (Dashboard'dan veya listeden).
     *
     * @param  int  $reminderId
     * @param  int  $userId
     * @return bool
     */
    public function deleteReminder(int $reminderId, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM reminders WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $reminderId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Bir reminder'ı aktif/pasif yapar.
     * MySQL, PostgreSQL ve SQLite ile %100 uyumlu CASE WHEN ifadesi kullanılır.
     */
    public function toggleReminder(int $reminderId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE reminders
               SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
             WHERE id = :id AND user_id = :user_id
        ");
        $stmt->execute([':id' => $reminderId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }
}
