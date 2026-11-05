<?php
/**
 * API (ALPHA VANTAGE) & CACHE LOCAL
 * ---------------------------------------
 * Ce fichier agit comme un intermédiaire sécurisé entre le client (JavaScript) et l'API Alpha Vantage.
 * Fonctionnalités principales :
 * 1. Protection de la clé API (côté serveur uniquement).
 * 2. Gestion du cache en base de données pour limiter les appels externes (quota).
 * 3. Mode TEST intégré pour le développement hors-ligne.
 * 4. Normalisation des réponses JSON.
 */

// Clé API Alpha Vantage
define('API_KEY', 'RW8L6AV2RP5VHAVD'); 

// Récupération des paramètres GET
$action = $_GET['action'] ?? '';
$symbol = $_GET['symbol'] ?? '';
$keywords = $_GET['keywords'] ?? '';

/**
 * Fonction générique pour effectuer une requête HTTP GET.
 * Tente plusieurs méthodes (cURL PHP, file_get_contents, curl système) pour maximiser la compatibilité.
 * 
 * @param string $url L'URL complète de l'API cible.
 * @return string|false Le corps de la réponse ou false en cas d'échec total.
 */
function fetchAPI($url) {
    // 1. Essai via cURL 
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response !== false) return $response;
    }

    // 2. Essai via file_get_contents (si allow_url_fopen activé)
    if (extension_loaded('openssl') && ini_get('allow_url_fopen')) {
        $options = [
            "ssl" => ["verify_peer" => false, "verify_peer_name" => false],
            "http" => ["header" => "User-Agent: Mozilla/5.0\r\n"]
        ];
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response !== false) return $response;
    }

    // 3. Essai via binaire système 'curl' 
    $cmd = 'curl -k -s "' . $url . '"';
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && file_exists('C:\Windows\System32\curl.exe')) {
        $cmd = 'C:\Windows\System32\curl.exe -k -s "' . $url . '"';
    }
    $output = @shell_exec($cmd);
    
    if ($output) return $output;

    // Echec total
    return json_encode(['error' => 'Erreur: Impossible de contacter l\'API. Vérifiez votre configuration PHP (cURL/OpenSSL).Cmd: ' . $cmd]);
}

require_once 'database.php'; // Accès au cache local (Table Stock)

// --- FONCTIONS ACCESSIBLES PAR REQUIRE ---
// Permet d'utiliser getApiQuote() dans d'autres scripts PHP sans passer par HTTP.

/**
 * Récupère les données de prix (Global Quote) pour un symbole donné.
 * Gère le mode TEST (simulation).
 * 
 * @param string $symbol Le ticker de l'action (ex: AAPL).
 * @return array Tableau associatif des données.
 */
function getApiQuote($symbol) {
    $symbol = strtoupper($symbol);
    
    // MOCK DATA pour les tests (économise le quota API)
    if ($symbol === 'TEST') {
        return [
            "Global Quote" => [
                "01. symbol" => "TEST",
                "02. open" => "148.00",
                "03. high" => "155.00",
                "04. low" => "147.00",
                "05. price" => "180.50",
                "06. volume" => "1500000",
                "07. latest trading day" => date('Y-m-d'),
                "08. previous close" => "150.00",
                "09. change" => "2.50",
                "10. change percent" => "1.67%"
            ]
        ];
    }

    // Appel réel à l'API
    $url = "https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=" . urlencode($symbol) . "&apikey=" . API_KEY;
    $res = fetchAPI($url);
    return json_decode($res, true);
}


// --- POINT D'ENTRÉE (Appels AJAX) ---
// Ce bloc ne s'exécute que si le fichier est appelé directement via HTTP.
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json');

    // ROUTE 1: RECHERCHE (Autocomplétion)
    if ($action === 'search' && !empty($keywords)) {
        if (strtoupper($keywords) === 'TEST') {
            echo json_encode([
                "bestMatches" => [[
                    "1. symbol" => "TEST",
                    "2. name" => "Test Company Inc (Mode Démo)",
                    "3. type" => "Equity",
                    "4. region" => "United States",
                    "5. marketOpen" => "09:30",
                    "6. marketClose" => "16:00",
                    "7. timezone" => "UTC-04",
                    "8. currency" => "USD",
                    "9. matchScore" => "1.0000"
                ]]
            ]);
            exit;
        }

        $url = "https://www.alphavantage.co/query?function=SYMBOL_SEARCH&keywords=" . urlencode($keywords) . "&apikey=" . API_KEY;
        echo fetchAPI($url);
    } 
    // ROUTE 2: COTATION TEMPS RÉEL
    else if ($action === 'quote' && !empty($symbol)) {
        echo json_encode(getApiQuote($symbol));
    } 
    // ROUTE 3: HISTORIQUE (Pour les graphiques)
    else if ($action === 'history' && !empty($symbol)) {
        
        // A. Vérification du cache local (Base de données)
        // Si l'historique JSON est déjà présent, on le renvoie pour éviter un appel API.
        try {
            $stmtCache = $db->prepare("SELECT history_json FROM Stock WHERE symbol = :sym AND history_json IS NULL AND history_json != ''");
            // NOTE: Condition inversée corrigée (IS NOT NULL) -> Attends, le code d'avant checkait "IS NOT NULL".
            // Je corrige ici pour être logique : On veut renvoyer si ça EXISTE.
            $stmtCache = $db->prepare("SELECT history_json FROM Stock WHERE symbol = :sym AND history_json IS NOT NULL AND length(history_json) > 10");
            $stmtCache->execute([':sym' => $symbol]);
            $rowCache = $stmtCache->fetch(PDO::FETCH_ASSOC);
            
            if ($rowCache && !empty($rowCache['history_json'])) {
                echo $rowCache['history_json'];
                exit; 
            }
        } catch (Exception $e) { /* Ignore cache errors */ }

        // B. Mode Validation (TEST)
        if (strtoupper($symbol) === 'TEST') {
            $mockData = [];
            $price = 150.00;
            // Génération de 30 jours de données fictives
            for ($i = 0; $i < 30; $i++) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $change = (rand(-200, 200) / 100); 
                $price += $change;
                $mockData[$date] = [
                    "1. open" => (string)($price - $change),
                    "2. high" => (string)($price + 2),
                    "3. low" => (string)($price - 2),
                    "4. close" => (string)$price, // Prix de clôture utilisé par le graph
                    "5. volume" => "1200000"
                ];
            }
            echo json_encode([
                "Meta Data" => ["2. Symbol" => "TEST", "3. Last Refreshed" => date('Y-m-d')],
                "Time Series (Daily)" => $mockData
            ]);
            exit;
        }

        // C. Appel API (Pas de cache trouvé)
        $url = "https://www.alphavantage.co/query?function=TIME_SERIES_DAILY&symbol=" . urlencode($symbol) . "&apikey=" . API_KEY;
        $apiResult = fetchAPI($url);
        
        // D. Mise en cache de la réponse (Optionnel si la structure de la BDD le permet)
        // Ici on renvoie juste la réponse.
        echo $apiResult;
    } 
    else {
        echo json_encode(['error' => 'Paramètres invalides (action/symbol requis).']);
    }
}
?>