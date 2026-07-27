<?php
/**
 * LetaDial — CSP Violation Report Endpoint
 * Krok 2 planu naprawy CSP unsafe-inline — patrz PLAN_NAPRAWY_CSP.md
 *
 * POST /api/csp-report
 *   Body:    JSON raport naruszenia CSP, wysyłany SAMOCZYNNIE przez
 *            przeglądarkę, kiedy Content-Security-Policy(-Report-Only)
 *            (patrz src/CSP.php) zostanie naruszona.
 *   Zwraca:  zawsze 204 No Content — przeglądarka i tak ignoruje treść
 *            i status odpowiedzi na te requesty; 204 to jawne
 *            potwierdzenie "przyjąłem, nic więcej nie oczekuję".
 *
 * CELOWO bez Auth::getUser() i bez CSRF::require():
 *   Raporty naruszeń generuje silnik CSP przeglądarki, na KAŻDEJ stronie —
 *   również w pełni niezalogowanej (/login, /forgot-password). Nie ma tam
 *   sesji do uwierzytelnienia ani świadomego submitu formularza do
 *   ochrony tokenem CSRF; wymóg któregokolwiek z nich po cichu odrzucałby
 *   każdy raport ze strony bez sesji — a to właśnie tam błąd escapowania
 *   jest najgroźniejszy do przeoczenia.
 *
 * Kontrole nadużyć (w zastępstwie auth/CSRF):
 *   - php://input czytane z twardym limitem 8 KB (5-ty argument
 *     file_get_contents) — realny CSP-raport to kilkaset bajtów; próba
 *     wysłania więcej jest ucinana na poziomie samego odczytu, niezależnie
 *     od tego, co deklaruje (spoofowalny) nagłówek Content-Length.
 *   - Rate limit 100/h/IP przez istniejący RateLimit::check() — ogranicza,
 *     ile ten z założenia otwarty endpoint może posłużyć do zapychania
 *     dysku.
 *   - Zapis: jeden obiekt JSON na linię do logs/csp-violations.log — ten
 *     katalog jest już poza zasięgiem web roota (nginx
 *     `location ^~ /logs/` deny-all na obu serwerach + .htaccess jako
 *     druga warstwa), więc nic dodatkowego nie trzeba tu zabezpieczać.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

// Przeglądarki zawsze POST-ują raporty. Cokolwiek innego nie jest
// prawdziwym raportem — ale i tak zawsze 204, zero informacji zwrotnej.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(204);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Rate limit najpierw — tani check, oszczędza odczyt/zapis dla IP, które
// już wyczerpało limit.
if (RateLimit::check('csp_report', $ip, 100, 3600, 3600)) {
    http_response_code(204);
    exit;
}

// Twardy limit 8 KB egzekwowany przez sam odczyt (5-ty argument = length),
// nie przez zaufanie nagłówkowi Content-Length.
$raw = file_get_contents('php://input', false, null, 0, 8192);
if ($raw === false || $raw === '') {
    http_response_code(204);
    exit;
}

$report = json_decode($raw, true);
if (!is_array($report)) {
    http_response_code(204);
    exit;
}

$entry = [
    'time'   => gmdate('Y-m-d\TH:i:s\Z'),
    'ip'     => $ip,
    'ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
    'report' => $report,
];

$logDir  = __DIR__ . '/../logs';
$logFile = $logDir . '/csp-violations.log';

if (is_dir($logDir) && is_writable($logDir)) {
    @file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

http_response_code(204);
