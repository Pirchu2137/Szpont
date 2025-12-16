<?php
/**
 * API Endpoint dla Minecraft Stats Modu
 * Odbiera dane z NeoForge modu i aktualizuje bazę danych
 */

// ===== KONFIGURACJA =====
define('API_KEY', 'TWOJ_SECRET_KEY'); // Musi być taka sama jak w Javie
define('DEBUG', true);

// Załaduj główny skrypt
require_once 'Szpotnt.php';

// ===== LOGOWANIE =====
function log_api($message) {
    if (DEBUG) {
        error_log("[Minecraft Stats API] " . $message);
    }
}

// ===== WERYFIKACJA API KEY =====
function verify_api_key($key) {
    return $key === API_KEY;
}

// ===== KONFIGURACJA BAZY DANYCH =====
$dbConfig = [
    'host' => 's6.crafthost.pl',
    'user' => 'svr102872',
    'password' => 'Kasztelan1',  // Zmień na rzeczywiste hasło
    'database' => 'svr102872',
    'port' => 3306
];

// Inicjalizuj plugin
$statsPlugin = new MinecraftStatsPlugin(true, $dbConfig);

// ===== OBSŁUGA ŻĄDAŃ =====
header('Content-Type: application/json');

// Sprawdź czy to POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Tylko POST']);
    exit;
}

// Pobierz JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Nieważny JSON']);
    exit;
}

// Weryfikuj API key
if (!isset($input['key']) || !verify_api_key($input['key'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nieautoryzowany']);
    log_api("Nieudana próba bez API key");
    exit;
}

$action = $input['action'] ?? null;
$player = $input['player'] ?? null;

if (!$action || !$player) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak action lub player']);
    exit;
}

try {
    switch ($action) {
        case 'death':
            $killer = $input['killer'] ?? null;
            $statsPlugin->recordDeath($player, $killer);
            log_api("Śmierć: $player (zabił: $killer)");
            echo json_encode(['success' => true, 'message' => 'Śmierć zanotowana']);
            break;
            
        case 'login':
            $statsPlugin->recordLogin($player);
            log_api("Login: $player");
            echo json_encode(['success' => true, 'message' => 'Login zanotowany']);
            break;
            
        case 'logout':
            $statsPlugin->recordLogout($player);
            log_api("Logout: $player");
            echo json_encode(['success' => true, 'message' => 'Logout zanotowany']);
            break;
            
        case 'getStats':
            $stats = $statsPlugin->getPlayerStats($player);
            if ($stats) {
                echo json_encode(['success' => true, 'stats' => $stats]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Brak danych gracza']);
            }
            log_api("Pobrano statystyki: $player");
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Nieznana akcja: ' . $action]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Błąd serwera: ' . $e->getMessage()]);
    log_api("Błąd: " . $e->getMessage());
}

?>
