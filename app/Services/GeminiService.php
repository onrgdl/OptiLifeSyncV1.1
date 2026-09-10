<?php

declare(strict_types=1);

namespace App\Services;

/**
 * GeminiService
 *
 * Google Gemini REST API ile iletişim kurar.
 * cURL kullanır; Composer veya harici bağımlılık gerektirmez.
 *
 * ── Beslenme Analizi Akışı ─────────────────────────────────────────
 *
 *   Kullanıcı metni ("150 gr tavuk, 1 kola")
 *         │
 *         ▼
 *   GeminiService::analyzeFood()
 *         │  POST /v1beta/models/gemini-1.5-flash:generateContent
 *         │  systemInstruction: Diyetisyen system prompt
 *         │  user message: kullanıcının metni
 *         │  responseMimeType: "application/json"
 *         ▼
 *   Gemini API yanıtı
 *         │  {"kalori":450,"protein":35,"karb":40,"yag":15}
 *         ▼
 *   json_decode() → doğrulanmış PHP array
 *         │
 *         ▼
 *   PDO INSERT → food_logs + daily_logs toplamları güncelle
 *
 * @package App\Services
 */
class GeminiService
{
    // REST API temel URL'i
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    // Diyetisyen system prompt — Gemini'ye rol tanımlar
    private const SYSTEM_PROMPT = <<<'PROMPT'
Sen uzman bir klinik diyetisyen ve besin kimyacısısın. Kullanıcının girdiği yemek, öğün veya besin metnini analiz et.
Türk ve dünya mutfağı standart besin tablolarını (USDA / TürKomp) temel alarak kalori ve makroları belirle.

KURALLAR:
1. Besin Değerleri Tutarlılığı (Termodinamik Kuralı):
   Kalori hesabı makro değerleriyle tam uyumlu olmalıdır:
   Toplam Kalori (kcal) = (Protein x 4) + (Karbonhidrat x 4) + (Yağ x 9).
   Asla makroların toplamından bağımsız veya çelişkili bir kalori yazma!
2. Yağ ve Porsiyon Gerçekçiliği:
   Örneğin haşlanmış veya ızgara tavuk göğsünün yağı düşüktür (100 gramında yaklaşık 2-3g yağ). Yağ oranını gereksiz yere abartma. Türk yemeklerindeki ortalama pişirme yağı miktarını (porsiyon başına ~1-1.5 tatlı kaşığı zeytinyağı / ayçiçek yağı) makul şekilde hesaba kat.
3. Çıktı Biçimi:
   Cevabını SADECE geçerli bir JSON formatında ver. Hiçbir açıklama, markdown, kod bloğu veya ek metin ekleme.
4. Porsiyon belirtilmemişse ortalama 1 porsiyon varsay.

Çıktı formatı (kesinlikle bu yapıda):
{"yemek_adi": "Izgara Tavuk Göğsü, Pirinç Pilavı ve Ayran", "porsiyon_ozeti": "150g tavuk göğsü (~240 kcal), 1 porsiyon pilav (~220 kcal), 1 bardak ayran (~75 kcal)", "kalori": 503, "protein": 51, "karb": 50, "yag": 11}
PROMPT;

    // Görsel yemek analizi system prompt'u — Gemini Multimodal Vision
    private const IMAGE_SYSTEM_PROMPT = <<<'PROMPT'
Sen uzman bir klinik diyetisyen ve görsel besin analistisin. Kullanıcının gönderdiği yemek, tabak veya besin fotoğrafını detaylıca analiz et.
Görseldeki tüm yiyecekleri tespit et, porsiyon büyüklüklerini tahmin et.

KURALLAR:
1. Besin Değerleri Tutarlılığı (Termodinamik Kuralı):
   Toplam Kalori (kcal) = (Protein x 4) + (Karbonhidrat x 4) + (Yağ x 9).
   Makro toplamı ile kalori birbiriyle kesinlikle uyumlu olmalıdır.
2. Porsiyon ve Yağ Gerçekçiliği:
   Görseldeki porsiyonları gerçekçi tahmin et, pişirme yağını abartma.
3. Kullanıcı ek not yazdıysa (örn: "yarısını yedim", "zeytinyağlı") bunu porsiyon ve makro hesabına dahil et.
4. Fotoğrafta yiyecek/içecek bulunmuyorsa veya tespit edilemiyorsa kalori ve makroları 0 yap, aciklama kısmına "Fotoğrafta yiyecek tespit edilemedi." yaz.
5. Cevabını SADECE geçerli JSON formatında ver. Markdown veya ek metin ekleme.

Çıktı formatı (kesinlikle bu yapıda):
{"yemek_adi": "Izgara Tavuk & Mevsim Salata", "aciklama": "Yaklaşık 180g ızgara tavuk göğsü, çoban salata ve 1 dilim kepekli ekmek", "kalori": 418, "protein": 44, "karb": 24, "yag": 16}
PROMPT;

    private string $apiKey;
    private string $model;

    /**
     * @param string $apiKey  Gemini API anahtarı (Config::get('GEMINI_API_KEY'))
     * @param string $model   Kullanılacak model (varsayılan: gemini-2.5-flash)
     */
    public function __construct(string $apiKey, string $model = 'gemini-2.5-flash')
    {
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('Gemini API anahtarı boş olamaz.');
        }

        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    // =================================================================
    // PUBLIC: Besin Analizi
    // =================================================================

    /**
     * Serbest metin öğünü Gemini API'ye gönderir ve makro değerlerini döner.
     *
     * @param  string $mealText Kullanıcının girdiği serbest metin
     *                          ("150 gr ızgara tavuk ve 1 kola" gibi)
     * @return array{
     *     kalori: float,
     *     protein: float,
     *     karb: float,
     *     yag: float,
     *     raw_text: string,
     *     model: string
     * }
     *
     * @throws \InvalidArgumentException Metin boşsa
     * @throws \RuntimeException         API hatası veya geçersiz yanıt
     */
    public function analyzeFood(string $mealText): array
    {
        $mealText = trim($mealText);

        if (empty($mealText)) {
            throw new \InvalidArgumentException('Analiz edilecek öğün metni boş olamaz.');
        }

        // ── İstek Gövdesi ────────────────────────────────────────────
        $requestBody = [
            // Sistem rolü: diyetisyen kimliği
            'systemInstruction' => [
                'parts' => [
                    ['text' => self::SYSTEM_PROMPT],
                ],
            ],
            // Kullanıcı mesajı
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $mealText],
                    ],
                ],
            ],
            // Model ayarları
            'generationConfig' => [
                'temperature'       => 0.2,   // Tutarlılık için düşük tutuldu
                'maxOutputTokens'   => 256,   // JSON yanıt kısa olacak
                'responseMimeType'  => 'application/json',  // Doğrudan JSON zorlama
            ],
        ];

        // ── API İsteği Gönder (Model Fallback Destekli) ─────────────
        $candidateModels = array_unique([$this->model, 'gemini-2.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-flash-latest', 'gemini-3.5-flash-lite']);
        $lastException   = null;

        foreach ($candidateModels as $currentModel) {
            try {
                $endpoint = sprintf(
                    '%s/%s:generateContent?key=%s',
                    self::API_BASE,
                    $currentModel,
                    urlencode($this->apiKey)
                );
                $rawResponse = $this->curlPost($endpoint, $requestBody);
                $result = $this->parseResponse($rawResponse, $mealText);
                $result['model'] = $currentModel;
                return $result;
            } catch (\RuntimeException $e) {
                $lastException = $e;
                continue;
            }
        }

        throw $lastException ?? new \RuntimeException('Gemini besin analizi başarısız oldu.');
    }

    /**
     * Yemek fotoğrafını (base64) Gemini Multimodal Vision API'ye gönderir ve analiz eder.
     *
     * @param string $imageBase64  Base64 kodlanmış görsel verisi
     * @param string $mimeType     Görsel MIME türü (image/jpeg, image/png, image/webp)
     * @param string $userNotes    Kullanıcının isteğe bağlı açıklaması ("Yarısını yedim", "Zeytinyağlı" vb.)
     * @return array
     */
    public function analyzeFoodImage(string $imageBase64, string $mimeType = 'image/jpeg', string $userNotes = ''): array
    {
        // Data URI şemasını temizle (e.g. data:image/jpeg;base64,....)
        if (preg_match('/^data:(image\/[a-zA-Z0-9\+\-]+);base64,(.+)$/', $imageBase64, $matches)) {
            $mimeType    = $matches[1];
            $imageBase64 = $matches[2];
        }

        $imageBase64 = trim($imageBase64);
        if (empty($imageBase64)) {
            throw new \InvalidArgumentException('Analiz edilecek görsel verisi boş olamaz.');
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
        if (!in_array(strtolower($mimeType), $allowedMimes, true)) {
            $mimeType = 'image/jpeg';
        }

        $parts = [
            [
                'inlineData' => [
                    'mimeType' => $mimeType,
                    'data'     => $imageBase64,
                ],
            ],
        ];

        $promptText = "Bu yemek fotoğrafını analiz et ve besin değerlerini tahmin et.";
        if (!empty(trim($userNotes))) {
            $promptText .= " Ek kullanıcı notu: " . trim($userNotes);
        }
        $parts[] = ['text' => $promptText];

        $requestBody = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => self::IMAGE_SYSTEM_PROMPT],
                ],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature'      => 0.2,
                'maxOutputTokens'  => 512,
                'responseMimeType' => 'application/json',
            ],
        ];

        $candidateModels = array_unique([$this->model, 'gemini-2.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-flash-latest', 'gemini-3.5-flash-lite']);
        $lastException   = null;

        foreach ($candidateModels as $currentModel) {
            try {
                $endpoint = sprintf(
                    '%s/%s:generateContent?key=%s',
                    self::API_BASE,
                    $currentModel,
                    urlencode($this->apiKey)
                );
                $rawResponse = $this->curlPost($endpoint, $requestBody);
                $result = $this->parseImageResponse($rawResponse, $userNotes);
                $result['model'] = $currentModel;
                return $result;
            } catch (\RuntimeException $e) {
                $lastException = $e;
                continue;
            }
        }

        throw $lastException ?? new \RuntimeException('Gemini fotoğraftan yemek analizi başarısız oldu.');
    }

    /**
     * Birden fazla öğünü sırayla analiz eder (batch işlem).
     *
     * @param  string[] $mealTexts
     * @return array[]
     */
    public function analyzeFoodBatch(array $mealTexts): array
    {
        $results = [];
        foreach ($mealTexts as $text) {
            try {
                $results[] = $this->analyzeFood($text);
            } catch (\Throwable $e) {
                $results[] = ['error' => $e->getMessage(), 'raw_text' => $text];
            }
        }
        return $results;
    }

    /**
     * Haftalık istatistikleri değerlendirerek AI koçluk tavsiyesi üretir.
     *
     * @param  string $statsPrompt Kullanıcının haftalık özet metni
     * @return string Markdown formatında tavsiye metni
     */
    public function generateWeeklyReportAdvice(string $statsPrompt): string
    {
        $systemInstruction = "Sen OptiLifeSync kişisel sağlık takip sisteminin uzman klinik diyetisyeni ve fitness performans koçusun. Kullanıcının haftalık beslenme, kalori dengesi, makro oranları ve antrenman verilerini analiz et. Yanıtını Türkçe, motive edici, samimi ve net Markdown formatında sun. Başlıklar: 🎯 Haftanın Özeti, 💪 Başarılı Yönler, ⚠️ Dikkat Edilmesi Gerekenler, 🚀 Gelecek Hafta İçin 3 Somut Tavsiye şeklinde olsun.";

        $requestBody = [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $statsPrompt]],
                ],
            ],
            'generationConfig' => [
                'temperature'     => 0.4,
                'maxOutputTokens' => 1200,
            ],
        ];

        $candidateModels = array_unique([$this->model, 'gemini-2.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-flash-latest', 'gemini-3.5-flash-lite']);
        $lastException   = null;

        foreach ($candidateModels as $currentModel) {
            try {
                $endpoint = sprintf(
                    '%s/%s:generateContent?key=%s',
                    self::API_BASE,
                    $currentModel,
                    urlencode($this->apiKey)
                );
                $rawResponse = $this->curlPost($endpoint, $requestBody);
                $wrapper = json_decode($rawResponse, true);
                $text = $wrapper['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if ($text !== null) {
                    return trim($text);
                }
            } catch (\RuntimeException $e) {
                $lastException = $e;
                continue;
            }
        }

        throw $lastException ?? new \RuntimeException('Gemini haftalık rapor analizi üretilemedi.');
    }


    // =================================================================
    // PRIVATE: Yanıt Ayrıştırma
    // =================================================================

    /**
     * Gemini API ham yanıtını ayrıştırır ve doğrular.
     *
     * @throws \RuntimeException Geçersiz yanıt veya JSON ayrıştırma hatası
     */
    private function parseResponse(string $rawResponse, string $originalText): array
    {
        // 1. Dıştaki Gemini wrapper'ını çöz
        $wrapper = json_decode($rawResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Gemini yanıtı ayrıştırılamadı: ' . json_last_error_msg());
        }

        // Hata yanıtı kontrolü
        if (isset($wrapper['error'])) {
            $errMsg = $wrapper['error']['message'] ?? 'Bilinmeyen API hatası';
            $errCode = $wrapper['error']['code']    ?? 0;
            throw new \RuntimeException("Gemini API Hatası ({$errCode}): {$errMsg}");
        }

        // 2. candidates[0].content.parts[0].text alanına ulaş
        $jsonText = $wrapper['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($jsonText === null) {
            // Finish reason kontrolü (SAFETY, MAX_TOKENS vb.)
            $reason = $wrapper['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            throw new \RuntimeException("Gemini yanıt üretemedi. Sebep: {$reason}");
        }

        // 3. responseMimeType=application/json olsa da temizlik uygula
        $jsonText = $this->sanitizeJsonText($jsonText);

        // 4. İç JSON'ı çöz
        $macros = json_decode($jsonText, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($macros)) {
            throw new \RuntimeException(
                "Gemini'nin döndürdüğü değer geçerli JSON değil. Alınan: " . substr($jsonText, 0, 200)
            );
        }

        // 5. Zorunlu alanları doğrula ve normalize et
        return $this->normalizeMacros($macros, $originalText);
    }

    /**
     * Gemini Vision API ham yanıtını ayrıştırır.
     */
    private function parseImageResponse(string $rawResponse, string $userNotes): array
    {
        $wrapper = json_decode($rawResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Gemini görsel yanıtı ayrıştırılamadı: ' . json_last_error_msg());
        }

        if (isset($wrapper['error'])) {
            $errMsg = $wrapper['error']['message'] ?? 'Bilinmeyen API hatası';
            $errCode = $wrapper['error']['code'] ?? 0;
            throw new \RuntimeException("Gemini API Hatası ({$errCode}): {$errMsg}");
        }

        $jsonText = $wrapper['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($jsonText === null) {
            $reason = $wrapper['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            throw new \RuntimeException("Gemini görseli analiz edemedi. Sebep: {$reason}");
        }

        $jsonText = $this->sanitizeJsonText($jsonText);
        $data = json_decode($jsonText, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \RuntimeException('Gemini yanıtı geçerli JSON değil: ' . substr($jsonText, 0, 200));
        }

        $foodLabel = trim((string)($data['yemek_adi'] ?? $data['food_name'] ?? $data['title'] ?? 'Fotoğraflı Öğün'));
        if ($foodLabel === '') {
            $foodLabel = 'Fotoğraflı Öğün';
        }

        $desc = trim((string)($data['aciklama'] ?? $data['description'] ?? ''));

        $kalori  = round(min(5000.0, max(0.0, (float)($data['kalori'] ?? $data['calories'] ?? 0))), 1);
        $protein = round(min(500.0, max(0.0, (float)($data['protein'] ?? 0))), 1);
        $karb    = round(min(500.0, max(0.0, (float)($data['karb'] ?? $data['carbs'] ?? 0))), 1);
        $yag     = round(min(500.0, max(0.0, (float)($data['yag'] ?? $data['fat'] ?? 0))), 1);

        // Atwater Termodinamik Kalibrasyonu:
        // Toplam Kalori (kcal) = (Protein x 4) + (Karb x 4) + (Yağ x 9)
        $exactKcal = round(($protein * 4.0) + ($karb * 4.0) + ($yag * 9.0), 1);
        $statedKcal = round(min(5000.0, max(0.0, (float)($data['kalori'] ?? $data['calories'] ?? 0))), 1);
        $kalori = ($exactKcal > 0) ? $exactKcal : $statedKcal;

        return [
            'food_label'  => mb_substr($foodLabel, 0, 150),
            'description' => $desc,
            'kalori'      => $kalori,
            'protein'     => $protein,
            'karb'        => $karb,
            'yag'         => $yag,
            'user_notes'  => $userNotes,
        ];
    }

    /**
     * Model yine de markdown kod bloğu döndürürse temizler.
     * ```json ... ``` veya ``` ... ``` bloklarını soyar.
     */
    private function sanitizeJsonText(string $text): string
    {
        $text = trim($text);

        // ```json ... ``` veya ``` ... ``` bloklarını temizle
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $text, $matches)) {
            $text = trim($matches[1]);
        }

        // Başında/sonunda açık süslü parantez yoksa bul
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    /**
     * Gemini çıktısını standart formata dönüştürür ve tipler doğrular.
     * Atwater Termodinamik Kalibrasyonu uygular.
     */
    private function normalizeMacros(array $raw, string $originalText): array
    {
        // Olası alan adı varyasyonlarını birleştir
        $aliases = [
            'kalori'   => ['kalori', 'calories', 'kcal', 'energy'],
            'protein'  => ['protein', 'proteins'],
            'karb'     => ['karb', 'karbonhidrat', 'carb', 'carbs', 'karbohidrat'],
            'yag'      => ['yag', 'yağ', 'fat', 'fats', 'lipid'],
        ];

        $result = ['kalori' => 0.0, 'protein' => 0.0, 'karb' => 0.0, 'yag' => 0.0];

        foreach ($aliases as $canonical => $candidates) {
            foreach ($candidates as $alias) {
                if (isset($raw[$alias])) {
                    // Makul üst sınır: kalori ≤ 5000, diğerleri ≤ 500g
                    $maxValue = $canonical === 'kalori' ? 5000.0 : 500.0;
                    $result[$canonical] = min($maxValue, max(0.0, (float) $raw[$alias]));
                    break;
                }
            }
        }

        $protein = round($result['protein'], 1);
        $karb    = round($result['karb'],    1);
        $yag     = round($result['yag'],     1);

        // Atwater Termodinamik Kalibrasyonu:
        // 1g Protein = 4 kcal, 1g Karbonhidrat = 4 kcal, 1g Yağ = 9 kcal
        $exactCalories = round(($protein * 4.0) + ($karb * 4.0) + ($yag * 9.0), 1);
        $statedCalories = round($result['kalori'], 1);

        // Makrolar ile toplam kalori arasındaki tutarsızlığı ortadan kaldır
        $finalCalories = ($exactCalories > 0) ? $exactCalories : $statedCalories;

        $yemekAdi = trim((string)($raw['yemek_adi'] ?? $raw['food_name'] ?? $raw['title'] ?? ''));
        $porsiyon = trim((string)($raw['porsiyon_ozeti'] ?? $raw['aciklama'] ?? $raw['description'] ?? ''));

        return [
            'yemek_adi'      => $yemekAdi,
            'porsiyon_ozeti' => $porsiyon,
            'kalori'         => $finalCalories,
            'protein'        => $protein,
            'karb'           => $karb,
            'yag'            => $yag,
            'raw_text'       => $originalText,
            'model'          => $this->model,
        ];
    }

    // =================================================================
    // PRIVATE: cURL HTTP İstemcisi
    // =================================================================

    /**
     * JSON gövdeli POST isteği atar.
     *
     * @param  string $url
     * @param  array  $payload
     * @return string Ham HTTP yanıt gövdesi
     * @throws \RuntimeException cURL hatası
     */
    private function curlPost(string $url, array $payload): string
    {
        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'OptiLifeSync-App/1.0 (Gemini Client; PHP cURL)',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($jsonBody),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        // Geçici yoğunluk (503) veya hız limiti (429) durumunda kısa süre bekleyip tek seferlik yeniden dene
        if ($httpCode === 503 || $httpCode === 429) {
            usleep(1200000); // 1.2 sn bekle
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
        }

        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("cURL Hatası: {$curlErr}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            // Hata gövdesini de döndür
            $decoded = json_decode((string)$response, true);
            $apiMsg  = $decoded['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Gemini HTTP Hatası ({$httpCode}): {$apiMsg}");
        }

        return (string) $response;
    }
}
