<?php
require_once 'database.php';

// Function to fetch data
function fetchCSV($url) {
    set_time_limit(0);

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response !== false) return $response;
    }

    if (extension_loaded('openssl') && ini_get('allow_url_fopen')) {
        $options = [
            "ssl" => ["verify_peer" => false, "verify_peer_name" => false],
            "http" => ["header" => "User-Agent: Mozilla/5.0\r\n"]
        ];
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response !== false) return $response;
    }

    $cmd = 'curl -k -s "' . $url . '"';
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && file_exists('C:\Windows\System32\curl.exe')) {
        $cmd = 'C:\Windows\System32\curl.exe -k -s "' . $url . '"';
    }
    $output = @shell_exec($cmd);
    return $output;
}

$apiKey = 'demo'; 
$url = "https://www.alphavantage.co/query?function=LISTING_STATUS&apikey=" . $apiKey;

$message = "";
$count = 0;

try {
    $csvData = fetchCSV($url);

    if ($csvData && strlen($csvData) > 0) {
        $jsonCheck = json_decode($csvData, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($jsonCheck['Error Message'])) {
            throw new Exception("API Error: " . $jsonCheck['Error Message']);
        }
        if (json_last_error() === JSON_ERROR_NONE && (isset($jsonCheck['Note']) || isset($jsonCheck['Information']))) {
            throw new Exception("API Message: " . ($jsonCheck['Note'] ?? $jsonCheck['Information']));
        }

        $rows = preg_split("/\r\n|\n|\r/", $csvData);
        $rows = array_filter($rows, function($line) { return trim($line) !== ''; });

        if (empty($rows)) {
            throw new Exception("CSV vide (lignes vides uniquement).");
        }

        $header = str_getcsv(array_shift($rows), ",", '"', "\\");
        
        $db->beginTransaction();
        
        // Ensure table exists
        $db->exec("CREATE TABLE IF NOT EXISTS Stock (
            id_act INTEGER PRIMARY KEY AUTOINCREMENT,
            symbol TEXT UNIQUE,
            nom TEXT,
            price FLOAT DEFAULT 0,
            change_percent TEXT DEFAULT '0%',
            volume INTEGER DEFAULT 0,
            coût FLOAT DEFAULT 0,
            quantité INTEGER DEFAULT 0,
            history_json TEXT
        )");

        $insert = $db->prepare("INSERT OR IGNORE INTO Stock (symbol, nom) VALUES (:symbol, :nom)");

        foreach ($rows as $rowStr) {
            $row = str_getcsv($rowStr, ",", '"', "\\");
            
            if (count($row) < 1) continue; 

            $symbol = trim($row[0] ?? '');
            $name = trim($row[1] ?? '');
            $status = isset($row[6]) ? trim($row[6]) : 'Active';

            if ($status !== 'Active') continue;
            if (empty($symbol)) continue;

            $insert->execute([
                ':symbol' => $symbol,
                ':nom' => $name
            ]);
            if ($insert->rowCount() > 0) {
                $count++;
            }
        }
        
        $db->commit();
        $message = "Importation réussie : " . $count . " nouvelles actions ajoutées. (Total CSV: " . count($rows) . " lignes)";

    } else {
        $message = "Erreur : Aucune donnée récupérée (réponse vide). Vérifiez CURL.";
    }

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $message = "Erreur lors de l'importation : " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Importation Stock</title>
    <link href="css/accueil.css" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #fceceb; color: #1e1e1e; }
        .container { text-align: center; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 600px; }
        h1 { color: #d32f2f; margin-bottom: 20px; }
        .btn { display: inline-block; padding: 10px 20px; background-color: #d32f2f; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Mise à jour de la Marketplace</h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        <a href="marketplace.php" class="btn">Voir la Marketplace</a>
    </div>
</body>
</html>
