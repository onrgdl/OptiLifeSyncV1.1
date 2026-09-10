<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Kimlik Doğrulama & Oturum Güvenliği Kontrolörü
 * ─────────────────────────────────────────────────────────────
 * Tüm korumalı web sayfalarının en başında çağrılır.
 * Oturum yoksa kullanıcıyı login.php'ye yönlendirir.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Services\AuthService;

$authService = new AuthService($pdo);

// Oturum kontrolü (Giriş yapılmamışsa login.php'ye yönlendir)
$userId = AuthService::requireAuth(false);
$currentUser = AuthService::getCurrentUser();
$isCreator = AuthService::isCreator();
$isImpersonating = AuthService::isImpersonating();
