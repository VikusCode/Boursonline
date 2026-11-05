<?php
    require_once "database.php";
    session_start();

    try {
        // Redirection vers login si non connecté
        if(empty($_SESSION['user_id'])) {
            header('Location: login.php');
            exit();
        }

        $id = $_SESSION['user_id'];
        
        // --- API INTEGRÉE POUR LE GRAPHIQUE (AJAX) ---
        if (isset($_GET['ajax_graph'])) {
            header('Content-Type: application/json');
            $period = $_GET['period'] ?? 'ALL'; // 1M, 3M, 6M, YTD, ALL
            $smoothing = isset($_GET['smoothing']) && $_GET['smoothing'] === 'true';

            // 1. Déterminer la date de début
            $startDate = null;
            if ($period !== 'ALL') {
                $interval = '';
                switch ($period) {
                    case '1M': $interval = 'P1M'; break;
                    case '3M': $interval = 'P3M'; break;
                    case '6M': $interval = 'P6M'; break;
                    case 'YTD': 
                        $startDate = new DateTime('first day of january this year');
                        break;
                }
                if (!$startDate && $interval) {
                    $startDate = new DateTime();
                    $startDate->sub(new DateInterval($interval));
                }
            }

            // 2. Calcul du budget initial "Zéro" (avant toute transaction)
            $stmt_pm = $db->prepare("SELECT somme_actuelle FROM Porte_monnaie WHERE id_user = :id");
            $stmt_pm->execute([':id' => $id]);
            $pm = $stmt_pm->fetch();
            $current_budget = ($pm) ? $pm['somme_actuelle'] : 0.00;

            $stmt_calc = $db->prepare("
                SELECT 
                    SUM(CASE WHEN type_transac = 'Sell' THEN total ELSE 0 END) as total_ventes,
                    SUM(CASE WHEN type_transac = 'Purchase' THEN total ELSE 0 END) as total_achats
                FROM Transactions WHERE id_user = :id
            ");
            $stmt_calc->execute([':id' => $id]);
            $row_calc = $stmt_calc->fetch();
            
            $total_ventes = $row_calc ? ($row_calc['total_ventes'] ?? 0) : 0;
            $total_achats = $row_calc ? ($row_calc['total_achats'] ?? 0) : 0;
            
            $base_zero = (float)$current_budget - (float)$total_ventes + (float)$total_achats;

            // 3. Récupérer les transactions
            $sql_transacs = "SELECT date_transac, type_transac, total, nom_action, quantité FROM Transactions WHERE id_user = :id ORDER BY date_transac ASC";
            $stmt = $db->prepare($sql_transacs);
            $stmt->execute([':id' => $id]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Construction de l'historique complet
            $full_history = [];
            
            // Point de départ
            if (!empty($transactions)) {
                $first_date = new DateTime($transactions[0]['date_transac']);
                $start_timestamp = $first_date->getTimestamp() - 1;
                $full_history[] = [
                    'timestamp' => $start_timestamp,
                    'date_str' => 'Départ',
                    'value' => $base_zero,
                    'total_val' => $base_zero,
                    'stocks_val' => 0
                ];
            } else {
                 $full_history[] = [
                    'timestamp' => time(),
                    'date_str' => 'Départ',
                    'value' => $base_zero,
                    'total_val' => $base_zero,
                    'stocks_val' => 0
                ];
            }

            $current_val = $base_zero;
            $inventory = []; 
            $last_prices = []; 

            foreach ($transactions as $t) {
                // Ajustement du cash
                $change = ($t['type_transac'] === 'Sell') ? $t['total'] : -$t['total'];
                $current_val += $change;
                
                // Déduction du prix de l'action à l'instant T (Prix = Total / Quantité)
                $action_name = $t['nom_action'] ?? 'Inconnu';
                $qty = floatval($t['quantité'] ?? 1);
                if($qty == 0) $qty = 1;
                $unit_price = $t['total'] / $qty;

                $last_prices[$action_name] = $unit_price;

                if(!isset($inventory[$action_name])) $inventory[$action_name] = 0;

                // Mise à jour de l'inventaire
                // Note: DEPOT et RETRAIT ne correspondent pas à de vraies actions, on les ignore pour l'inventaire réel
                if ($action_name !== 'DEPOT' && $action_name !== 'RETRAIT') {
                    if($t['type_transac'] === 'Purchase'){
                        $inventory[$action_name] += $qty;
                    } else {
                        $inventory[$action_name] -= $qty;
                        if($inventory[$action_name] < 0) $inventory[$action_name] = 0;
                    }
                }

                // Calcul de la valorisation du portefeuille (Cash + Actions)
                $stocks_value = 0;
                foreach($inventory as $act => $q){
                    if($q > 0 && isset($last_prices[$act])){
                        $stocks_value += $q * $last_prices[$act];
                    }
                }

                $total_portfolio = $current_val + $stocks_value;

                $dt = new DateTime($t['date_transac']);
                $dt->setTimezone(new DateTimeZone('Europe/Paris'));
                $ts = $dt->getTimestamp();
                
                $full_history[] = [
                    'timestamp' => $ts,
                    'date_str' => $dt->format('d/m H:i'),
                    'value' => $current_val,
                    'total_val' => $total_portfolio,
                    'stocks_val' => $stocks_value
                ];
            }

            // 4. Filtrage par Période
            $filtered_data = [];
            $start_ts = $startDate ? $startDate->getTimestamp() : 0;
            
            if ($period !== 'ALL') {
                $last_val_before_start = $base_zero;
                $last_total_before_start = $base_zero;
                $last_stocks_before_start = 0;
                foreach ($full_history as $pt) {
                    if ($pt['timestamp'] < $start_ts) {
                        $last_val_before_start = $pt['value'];
                        $last_total_before_start = $pt['total_val'];
                        $last_stocks_before_start = $pt['stocks_val'];
                    } else {
                        break; 
                    }
                }
                $filtered_data[] = [
                    't' => date('d/m H:i', $start_ts),
                    'y' => $last_val_before_start,
                    'y_total' => $last_total_before_start,
                    'y_stocks' => $last_stocks_before_start
                ];
            }

            foreach ($full_history as $pt) {
                if ($pt['timestamp'] >= $start_ts) {
                    $filtered_data[] = [
                        't' => $pt['date_str'],
                        'y' => $pt['value'],
                        'y_total' => $pt['total_val'],
                        'y_stocks' => $pt['stocks_val']
                    ];
                }
            }

            // 5. Lissage
            if ($smoothing && count($filtered_data) > 2) {
                $smoothed_data = [];
                $window = 3; 
                for ($i = 0; $i < count($filtered_data); $i++) {
                    $sum = 0;
                    $sumTotal = 0;
                    $sumStocks = 0;
                    $count = 0;
                    for ($j = max(0, $i - $window + 1); $j <= $i; $j++) {
                        $sum += $filtered_data[$j]['y'];
                        $sumTotal += $filtered_data[$j]['y_total'];
                        $sumStocks += $filtered_data[$j]['y_stocks'];
                        $count++;
                    }
                    $smoothed_data[] = [
                        't' => $filtered_data[$i]['t'],
                        'y' => $sum / $count,
                        'y_total' => $sumTotal / $count,
                        'y_stocks' => $sumStocks / $count
                    ];
                }
                $filtered_data = $smoothed_data;
            }

            echo json_encode([
                'labels' => array_column($filtered_data, 't'),
                'data' => array_column($filtered_data, 'y'),
                'data_total' => array_column($filtered_data, 'y_total'),
                'data_stocks' => array_column($filtered_data, 'y_stocks')
            ]);
            exit(); 
        }
        
        // --- Traitement des Dépôts / Retraits ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_money'])) {
            // Remplacement de la virgule par un point pour gérer les décimales correctement
            $amount = floatval(str_replace(',', '.', $_POST['amount']));
            
            if ($amount > 0) {
                if ($_POST['action_money'] === 'deposit') {
                    try {
                        $db->beginTransaction();
                        
                        // 1. Mise à jour du porte-monnaie
                        $stmt = $db->prepare("UPDATE Porte_monnaie SET somme_actuelle = somme_actuelle + :amount WHERE id_user = :id");
                        $stmt->execute([':amount' => $amount, ':id' => $id]);
                        
                        // 2. Enregistrement de la transaction
                        // ASTUCE : On utilise 'Sell' pour un Dépôt car on reçoit de l'argent (comme une vente), et nom_action = 'DEPOT'
                        $stmt = $db->prepare("INSERT INTO Transactions (id_user, nom_action, type_transac, quantité, total, date_transac) VALUES (:id, 'DEPOT', 'Sell', 1, :amount, datetime('now'))");
                        $stmt->execute([':id' => $id, ':amount' => $amount]);
                        
                        $db->commit();
                        header("Location: profil.php");
                        exit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error_money = "Erreur lors du dépôt.";
                    }
                } elseif ($_POST['action_money'] === 'withdraw') {
                    $stmt = $db->prepare("SELECT somme_actuelle FROM Porte_monnaie WHERE id_user = :id");
                    $stmt->execute([':id' => $id]);
                    $current_val = $stmt->fetchColumn();
                    
                    if ($current_val >= $amount) {
                        try {
                            $db->beginTransaction();
                            
                            // 1. Mise à jour du porte-monnaie
                            $stmt_upd = $db->prepare("UPDATE Porte_monnaie SET somme_actuelle = somme_actuelle - :amount WHERE id_user = :id");
                            $stmt_upd->execute([':amount' => $amount, ':id' => $id]);
                            
                            // 2. Enregistrement de la transaction
                            // ASTUCE : On utilise 'Purchase' pour un Retrait car on dépense de l'argent (comme un achat), et nom_action = 'RETRAIT'
                            $stmt = $db->prepare("INSERT INTO Transactions (id_user, nom_action, type_transac, quantité, total, date_transac) VALUES (:id, 'RETRAIT', 'Purchase', 1, :amount, datetime('now'))");
                            $stmt->execute([':id' => $id, ':amount' => $amount]);
                            
                            $db->commit();
                            header("Location: profil.php");
                            exit();
                        } catch (Exception $e) {
                            $db->rollBack();
                            $error_money = "Erreur lors du retrait.";
                        }
                    } else {
                        $error_money = "Fonds insuffisants.";
                    }
                }
            }
        }
        // ------------------------------------------

        $stmt = $db->prepare("SELECT * FROM Utilisateur WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        if(!$user) {
            session_destroy();
            die("Erreur : Utilisateur connecté introuvable.");
        }
        
        $stmt_pm = $db->prepare("SELECT somme_actuelle FROM Porte_monnaie WHERE id_user = :id");
        $stmt_pm->execute([':id' => $id]);
        $pm = $stmt_pm->fetch();
        $budget = ($pm) ? $pm['somme_actuelle'] : 0.00;

        $stmt_act = $db->prepare("SELECT * FROM Actions WHERE id_user = :id");
        $stmt_act->execute([':id' => $id]);
        $actions = $stmt_act->fetchAll();

        $total_actions_value = 0;
        foreach($actions as $act) {
            $total_actions_value += $act['val_actuelle'] * $act['quantité'];
        }
        $total_portfolio_value = $budget + $total_actions_value;

        // Calcul dynamique du budget initial (revert to using Sell/Purchase only)
        $stmt_calc = $db->prepare("
            SELECT 
                SUM(CASE WHEN type_transac = 'Sell' THEN total ELSE 0 END) as total_ventes,
                SUM(CASE WHEN type_transac = 'Purchase' THEN total ELSE 0 END) as total_achats
            FROM Transactions WHERE id_user = :id
        ");
        $stmt_calc->execute([':id' => $id]);
        $row_calc = $stmt_calc->fetch();
        
        $total_ventes = $row_calc ? ($row_calc['total_ventes'] ?? 0) : 0;
        $total_achats = $row_calc ? ($row_calc['total_achats'] ?? 0) : 0;
        
        // Formule standard : Budget Actuel - Ventes + Achats = Budget Initial
        // Les dépôts ('Sell') et retraits ('Purchase') sont inclus dans ces totaux, donc la formule reste valide.
        $budget_initial_calcule = (float)$budget - (float)$total_ventes + (float)$total_achats;

        // Le code de génération du graphique est maintenant géré dynamiquement par l'API intégrée (AJAX)

    } catch(PDOException $e) {
        die("Erreur de connexion à la base de données : " . $e->getMessage());
    }

    $nom    = !empty($user['nom'])    ? $user['nom']    : 'Inconnu';
    $prenom = !empty($user['prenom']) ? $user['prenom'] : 'Inconnu';
    $pseudo = !empty($user['name_user']) ? $user['name_user'] : 'Pseudo';
    $avatar = !empty($user['avatar_url']) ? $user['avatar_url'] : '';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accueil - BoursOnline</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="./css/profil.css" rel="stylesheet">
    <script src="./js/theme.js"></script>
</head>
<body>

    <header class="top-bar">
        <div class="user-section">
            <a href="mon_compte.php" title="Modifier mon profil" style="text-decoration:none;">
                <?php if (!empty($avatar)): ?>
                    <img src="<?= htmlspecialchars($avatar) ?>" alt="Avatar" class="user-avatar">
                <?php else: ?>
                    <div class="user-avatar" style="display: flex; align-items: center; justify-content: center; font-size: 20px; color:white;">?</div>
                <?php endif; ?>
            </a>
            
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($prenom . ' ' . $nom) ?></span>
                <span class="user-pseudo">@<?= htmlspecialchars($pseudo) ?></span>
                <a href="logout.php" style="display:block; margin-top:5px; font-size:0.8rem; color:#ff6b6b; text-decoration:none;">Se déconnecter</a>
            </div>
        </div>

        <nav>
            <a href="profil.php" class="nav-link nav-link--active">Accueil</a>
            <span class="nav-separator">|</span>
            <a href="portefeuille.php" class="nav-link">Mon Portefeuille</a>
                        <span class="nav-separator">|</span>
            <a href="marketplace.php" class="nav-link">Marketplace</a>
        </nav>

        <div class="budget-section">
            <span class="budget-display">Solde Espèces : <span class="budget-value"><?= number_format($budget, 2, ',', ' ') ?> €</span></span>
            <span class="nav-separator">|</span>
            <span class="budget-display">Valorisation Totale : <span class="budget-value" style="color:#0984e3;"><?= number_format($total_portfolio_value, 2, ',', ' ') ?> €</span></span>
        </div>
    </header>

    <main>
        <div class="dashboard-container">
            <!-- Section Graphique (haut) -->
            <div class="card chart-section">
                <div class="chart-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h2 style="margin:0;">Évolution du Portefeuille</h2>
                    <div class="chart-controls">
                        <div class="period-selector">
                            <button class="btn-period" onclick="loadChart('1M', this)">1M</button>
                            <button class="btn-period" onclick="loadChart('3M', this)">3M</button>
                            <button class="btn-period" onclick="loadChart('6M', this)">6M</button>
                            <button class="btn-period" onclick="loadChart('YTD', this)">YTD</button>
                            <button class="btn-period active" onclick="loadChart('ALL', this)">Tout</button>
                        </div>
                        <div class="chart-options">
                            <label>
                                <input type="checkbox" id="smoothingCheck" onchange="toggleSmoothing()"> Lissage
                            </label>
                        </div>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="evolutionChart"></canvas>
                </div>
            </div>

            <!-- Section Actions (bas) -->
            <div class="actions-grid">
                <!-- Cube Accès Portefeuille (Gauche) -->
                <a href="portefeuille.php" class="action-cube wallet-cube">
                    <div class="cube-icon">
                         <!-- Icone très simple en CSS (juste un rectangle) -->
                         <div style="width: 32px; height: 22px; border: 3px solid #fff; border-radius: 4px;"></div>
                    </div>
                    <div class="cube-text">Accéder au<br>Portefeuille</div>
                </a>

                <!-- Cube Marketplace (Milieu) -->
                <a href="marketplace.php" class="action-cube marketplace-cube">
                    <div class="cube-icon">
                         <span style="font-size: 36px; font-weight: bold;">🌍</span>
                    </div>
                    <div class="cube-text">Marketplace<br>Actions</div>
                </a>

                <!-- Cube Gestion Fonds (Droite) -->
                <div class="action-cube money-cube" onclick="document.getElementById('moneyModal').style.display='flex'">
                    <div class="cube-icon">
                        <span style="font-size: 36px; font-weight: bold;">€</span>
                    </div>
                    <div class="cube-text">Gérer mes<br>Fonds</div>
                    <div class="cube-balance"><?= number_format($budget, 2, ',', ' ') ?> €</div>
                </div>
            </div>
        </div>

        <!-- Modal de gestion des fonds -->
        <div id="moneyModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="document.getElementById('moneyModal').style.display='none'">&times;</span>
                <h3>Gérer mon Portefeuille</h3>
                <p class="current-balance">Solde actuel : <strong><?= number_format($budget, 2, ',', ' ') ?> €</strong></p>
                
                <?php if(isset($error_money)): ?>
                    <p class="error-msg" style="color:red; text-align:center;"><?= htmlspecialchars($error_money) ?></p>
                <?php endif; ?>

                <div class="money-actions">
                    <div class="action-column deposit">
                        <h4>Dépôt</h4>
                        <form method="post">
                            <input type="hidden" name="action_money" value="deposit">
                            <!-- step="any" pour accepter les décimales, JS gère les incréments de 10 -->
                            <input type="number" name="amount" class="money-input" step="any" min="0" placeholder="Montant (€)" required>
                            <button type="submit" class="btn-deposit">Ajouter +</button>
                        </form>
                    </div>
                    
                    <div class="divider"></div>

                    <div class="action-column withdraw">
                        <h4>Retrait</h4>
                        <form method="post">
                            <input type="hidden" name="action_money" value="withdraw">
                            <input type="number" name="amount" class="money-input" step="any" min="0" placeholder="Montant (€)" required>
                            <button type="submit" class="btn-withdraw">Retirer -</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Gestion personnalisée des incréments de 10
        document.querySelectorAll('.money-input').forEach(input => {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    let currentVal = parseFloat(this.value.replace(',','.'));
                    if (isNaN(currentVal)) currentVal = 0;
                    this.value = (currentVal + 10);
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    let currentVal = parseFloat(this.value.replace(',','.'));
                    if (isNaN(currentVal)) currentVal = 0;
                    this.value = Math.max(0, currentVal - 10);
                }
            });
        });

        const ctx = document.getElementById('evolutionChart').getContext('2d');
        let evolutionChart = null;
        let currentPeriod = 'ALL';
        
        // Charger le graphique au démarrage
        document.addEventListener('DOMContentLoaded', () => {
            loadChart('ALL', document.querySelector('.btn-period.active'));
        });

        function loadChart(period, btn) {
            currentPeriod = period;
            
            // Gestion de l'état actif des boutons
            if(btn) {
                document.querySelectorAll('.btn-period').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            }

            const isSpline = document.getElementById('smoothingCheck').checked;
            fetchData(period, isSpline);
        }

        function toggleSmoothing() {
            // Recharger avec la période actuelle
            loadChart(currentPeriod, null); 
        }

        async function fetchData(period, smoothing) {
            try {
                // On appelle la page elle-même avec le paramètre ajax_graph
                const response = await fetch(`profil.php?ajax_graph=1&period=${period}&smoothing=${smoothing}`);
                const data = await response.json();
                
                if (data.error) {
                    console.error("Erreur API:", data.error);
                    return;
                }

                updateChart(data.labels, data.data, data.data_total, data.data_stocks, smoothing);
            } catch (error) {
                console.error("Erreur chargement graphique:", error);
            }
        }

        function updateChart(labels, dataCash, dataTotal, dataStocks, isSmoothed) {
            if (evolutionChart) {
                evolutionChart.destroy();
            }

            evolutionChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Solde Espèces',
                            data: dataCash,
                            borderColor: '#00b894',
                            backgroundColor: 'rgba(0, 184, 148, 0.1)',
                            borderWidth: 2,
                            tension: isSmoothed ? 0.4 : 0.3,
                            fill: true,
                            pointRadius: 0,
                            hoverRadius: 6
                        },
                        {
                            label: 'Valeur des Actions',
                            data: dataStocks,
                            borderColor: '#6c5ce7', /* Violet */
                            backgroundColor: 'rgba(108, 92, 231, 0.1)',
                            borderWidth: 2,
                            tension: isSmoothed ? 0.4 : 0.3,
                            fill: true,
                            pointRadius: 0,
                            hoverRadius: 6
                        },
                        {
                            label: 'Valorisation Totale (Espèces + Actions)',
                            data: dataTotal,
                            borderColor: '#0984e3',
                            backgroundColor: 'rgba(9, 132, 227, 0.1)',
                            borderWidth: 2,
                            tension: isSmoothed ? 0.4 : 0.3,
                            fill: true,
                            pointRadius: 0,
                            hoverRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: false,
                            grid: { color: '#f0f0f0' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { maxTicksLimit: 8 }
                        }
                    },
                    plugins: {
                        legend: { display: true, position: 'top', labels: { boxWidth: 12, usePointStyle: true, font: {size: 11} } },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: { 
                                label: function(context) { 
                                    return context.dataset.label + ' : ' + context.parsed.y.toFixed(2) + ' €'; 
                                } 
                            }
                        }
                    },
                    interaction: { mode: 'nearest', intersect: false }
                }
            });
        }
    </script>

</body>
</html>

