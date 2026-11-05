<?php
/**
 * PAGE PORTEFEUILLE (Tableau de bord principal)
 * ---------------------------------------------
 * Ce fichier gère l'affichage complet du portefeuille utilisateur.
 * Il combine :
 * 1. La visualisation graphique de l'évolution du solde (Chart.js + AJAX).
 * 2. La répartition des actifs (Liquidités vs Actions).
 * 3. La liste détaillée des actions détenues avec calcul de +/- value.
 * 4. L'historique des transactions (Achats/Ventes).
 * 5. Une interface complète de passage d'ordre (Recherche API + Achat).
 */

session_start();
require_once 'database.php'; // Inclusion de la connexion PDO à la base de données

// Vérification de la session utilisateur. Redirection si non connecté.
if(empty($_SESSION['user_id'])){header('Location:login.php');exit();}

$id=$_SESSION['user_id'];
$menu_active='portefeuille'; // Pour mettre en surbrillance le menu

// =================================================================================
// 1. API AJAX POUR LE GRAPHIQUE D'EVOLUTION
// =================================================================================
// Cette section intercepte les requêtes GET contenant 'ajax_graph'.
// Elle renvoie du JSON pur et arrête l'exécution du script (exit).
// Cela permet au JavaScript (fetch) de récupérer les données sans recharger la page.
if(isset($_GET['ajax_graph'])){
    header('Content-Type:application/json');
    
    // Récupération des paramètres de filtre
    $period=$_GET['period']??'ALL'; // Période : 1M, 3M, 6M, YTD, ALL
    $smoothing=isset($_GET['smoothing'])&&$_GET['smoothing']==='true'; // Lissage activé/désactivé

    // A. Définition de la date de début en fonction de la période choisie
    $startDate=null;
    if($period!=='ALL'){
        $interval='';
        switch($period){
            case '1M':$interval='P1M';break; // 1 Mois
            case '3M':$interval='P3M';break; // 3 Mois
            case '6M':$interval='P6M';break; // 6 Mois
            case 'YTD':$startDate=new DateTime('first day of january this year');break; // Year-to-Date
        }
        if(!$startDate&&$interval){
            $startDate=new DateTime();
            $startDate->sub(new DateInterval($interval));
        }
    }

    // B. Récupération du solde actuel "réel" en base
    $stmt_pm=$db->prepare("SELECT somme_actuelle FROM Porte_monnaie WHERE id_user=:id");
    $stmt_pm->execute([':id'=>$id]);
    $pm=$stmt_pm->fetch();
    $current_budget=($pm)?$pm['somme_actuelle']:0.00;

    // C. Calcul du solde initial théorique (Base Zéro)
    // On remonte le temps : Solde_Initial = Solde_Actuel - (Total_Ventes - Total_Achats)
    // Cela permet d'avoir un point de départ cohérent pour tracer la courbe.
    $stmt_calc=$db->prepare("SELECT SUM(CASE WHEN type_transac='Sell' THEN total ELSE 0 END) as total_ventes,SUM(CASE WHEN type_transac='Purchase' THEN total ELSE 0 END) as total_achats FROM Transactions WHERE id_user=:id");
    $stmt_calc->execute([':id'=>$id]);
    $row_calc=$stmt_calc->fetch();
    
    $total_ventes=$row_calc?($row_calc['total_ventes']??0):0;
    $total_achats=$row_calc?($row_calc['total_achats']??0):0;
    
    $base_zero=(float)$current_budget-(float)$total_ventes+(float)$total_achats;

    // D. Récupération de l'historique complet des transactions
    $sql_transacs="SELECT date_transac, type_transac, total, nom_action, quantité FROM Transactions WHERE id_user=:id ORDER BY date_transac ASC";
    $stmt=$db->prepare($sql_transacs);
    $stmt->execute([':id'=>$id]);
    $transactions=$stmt->fetchAll(PDO::FETCH_ASSOC);

    // E. Construction point par point de la courbe d'évolution
    $full_history=[];
    
    // Ajout du point de départ (avant la 1ère transaction ou maintenant)
    if(!empty($transactions)){
        $first_date=new DateTime($transactions[0]['date_transac']);
        $start_timestamp=$first_date->getTimestamp()-1;
        $full_history[]=['timestamp'=>$start_timestamp,'date_str'=>'Départ','value'=>$base_zero, 'total_val'=>$base_zero, 'stocks_val'=>0];
    }else{
         $full_history[]=['timestamp'=>time(),'date_str'=>'Départ','value'=>$base_zero, 'total_val'=>$base_zero, 'stocks_val'=>0];
    }

    // Boucle sur chaque transaction pour ajuster le solde
    $current_cash=$base_zero;
    $inventory=[];    // Suivi des quantités possédées : [ 'AAPL' => 10 ]
    $last_prices=[];  // Suivi des derniers prix connus : [ 'AAPL' => 150.00 ]

    foreach($transactions as $t){
        // Ajustement du Cash
        $change=($t['type_transac']==='Sell')?$t['total']:-$t['total'];
        $current_cash+=$change;
        
        // Déduction du prix de l'action à l'instant T (Prix = Total / Quantité)
        $action_name = $t['nom_action'] ?? 'Inconnu';
        $qty = floatval($t['quantité'] ?? 1);
        if($qty == 0) $qty = 1;
        $unit_price = $t['total'] / $qty;

        $last_prices[$action_name] = $unit_price;

        if(!isset($inventory[$action_name])) $inventory[$action_name] = 0;

        // Mise à jour de l'inventaire
        if($t['type_transac'] === 'Purchase'){
            $inventory[$action_name] += $qty;
        } else {
            $inventory[$action_name] -= $qty;
            if($inventory[$action_name] < 0) $inventory[$action_name] = 0;
        }

        // Calcul de la valorisation du portefeuille (Cash + Actions)
        $stocks_value = 0;
        foreach($inventory as $act => $q){
            if($q > 0 && isset($last_prices[$act])){
                $stocks_value += $q * $last_prices[$act];
            }
        }

        $total_portfolio = $current_cash + $stocks_value;

        $dt=new DateTime($t['date_transac']);
        $dt->setTimezone(new DateTimeZone('Europe/Paris'));
        $full_history[]=[
            'timestamp'=>$dt->getTimestamp(),
            'date_str'=>$dt->format('d/m H:i'),
            'value'=>$current_cash,
            'total_val'=>$total_portfolio,
            'stocks_val'=>$stocks_value
        ];
    }

    // F. Filtrage des données selon la date de début demandée
    $filtered_data=[];
    $start_ts=$startDate?$startDate->getTimestamp():0;
    
    // Si on filtre, on ajoute un point initial correspondant à la valeur du portefeuille juste avant la date de début
    if($period!=='ALL'){
        $last_val_before_start=$base_zero;
        $last_total_before_start=$base_zero;
        $last_stocks_before_start=0;
        foreach($full_history as $pt){
            if($pt['timestamp']<$start_ts){
                $last_val_before_start=$pt['value'];
                $last_total_before_start=$pt['total_val'];
                $last_stocks_before_start=$pt['stocks_val'];
            }else{break;}
        }
        $filtered_data[]=['t'=>date('d/m H:i',$start_ts),'y'=>$last_val_before_start,'y_total'=>$last_total_before_start,'y_stocks'=>$last_stocks_before_start];
    }

    // Ajout des points compris dans la période
    foreach($full_history as $pt){
        if($pt['timestamp']>=$start_ts){
            $filtered_data[]=['t'=>$pt['date_str'],'y'=>$pt['value'],'y_total'=>$pt['total_val'],'y_stocks'=>$pt['stocks_val']];
        }
    }

    // G. Lissage (Moyenne Mobile simple sur 3 points)
    if($smoothing&&count($filtered_data)>2){
        $smoothed_data=[];
        $window=3; 
        for($i=0;$i<count($filtered_data);$i++){
            $sumY=0; $sumYTotal=0; $sumYStocks=0; $count=0;
            // On prend les N précédents points disponibles
            for($j=max(0,$i-$window+1);$j<=$i;$j++){
                $sumY+=$filtered_data[$j]['y'];
                $sumYTotal+=$filtered_data[$j]['y_total'];
                $sumYStocks+=$filtered_data[$j]['y_stocks'];
                $count++;
            }
            $smoothed_data[]=['t'=>$filtered_data[$i]['t'],'y'=>$sumY/$count,'y_total'=>$sumYTotal/$count,'y_stocks'=>$sumYStocks/$count];
        }
        $filtered_data=$smoothed_data;
    }

    // H. Envoi de la réponse JSON au client
    echo json_encode([
        'labels'=>array_column($filtered_data,'t'),
        'data'=>array_column($filtered_data,'y'),
        'data_total'=>array_column($filtered_data,'y_total'),
        'data_stocks'=>array_column($filtered_data,'y_stocks')
    ]);
    exit(); 
}

// =================================================================================
// 2. LOGIQUE D'ACTUALISATION DES PRIX (REFRESH MANUEL)
// =================================================================================
if(isset($_GET['refresh'])){
    require_once 'api.php';
    
    // Récupération des symboles uniques possédés
    $stmt_syms=$db->prepare("SELECT DISTINCT nom FROM Actions WHERE id_user=:id");
    $stmt_syms->execute([':id'=>$id]);
    $symbols=$stmt_syms->fetchAll(PDO::FETCH_COLUMN);
    
    // Appel API pour chaque symbole et mise à jour de la table 'Stock'
    foreach($symbols as $sym){
        $quoteData=getApiQuote($sym);

        if(isset($quoteData['Global Quote']['05. price'])){
            $price=floatval($quoteData['Global Quote']['05. price']);
            $change=$quoteData['Global Quote']['10. change percent']??'0%';
            $volume=$quoteData['Global Quote']['06. volume']??0;
            
            // Upsert (Update sinon Insert)
            $check=$db->prepare("SELECT COUNT(*) FROM Stock WHERE symbol=:sym");
            $check->execute([':sym'=>$sym]);
            if($check->fetchColumn()>0){
                $upd=$db->prepare("UPDATE Stock SET price=:p,change_percent=:c,volume=:v WHERE symbol=:s");
                $upd->execute([':p'=>$price,':c'=>$change,':v'=>$volume,':s'=>$sym]);
            }else{
                $ins=$db->prepare("INSERT INTO Stock (symbol,nom,price,change_percent,volume) VALUES (:s,:n,:p,:c,:v)");
                $ins->execute([
                    ':s'=>$sym, 
                    ':n'=>$quoteData['Global Quote']['01. symbol']??$sym,
                    ':p'=>$price, 
                    ':c'=>$change, 
                    ':v'=>$volume
                ]);
            }
        }
    }
    // Redirection pour nettoyer l'URL et éviter le F5->re-excéution
    header('Location: portefeuille.php#mes-actions');
    exit();
}

try{
    // =================================================================================
    // 3. RECUPERATION DONNEES UTILISATEUR & COMPTE
    // =================================================================================
    $stmt=$db->prepare("SELECT * FROM Utilisateur WHERE id=:id");
    $stmt->execute([':id'=>$id]);
    $user=$stmt->fetch();

    if(!$user){session_destroy();header('Location:login.php');exit();}

    $stmt_pm=$db->prepare("SELECT * FROM Porte_monnaie WHERE id_user=:id");
    $stmt_pm->execute([':id'=>$id]);
    $pm=$stmt_pm->fetch();
    $budget=($pm)?$pm['somme_actuelle']:0.00;

    // =================================================================================
    // 4. GESTION DU TABLEAU DES ACTIONS (TRI + PRIX LIVE)
    // =================================================================================
    // Récupération des paramètres de tri GET
    $sort_act=$_GET['sort_act']??'nom';
    $order_act=$_GET['order_act']??'ASC';
    $next_order_act=($order_act==='ASC')?'DESC':'ASC'; // Inversion pour le lien HTML
    $order_sql_act=($order_act==='DESC')?'DESC':'ASC'; 

    // Sécurisation des colonnes de tri
    $valid_sorts_act=['nom','quantite','pru','val_actuelle','performance','date'];
    if(!in_array($sort_act,$valid_sorts_act))$sort_act='nom';

    // Correspondance GET -> Colonne SQL
    $sql_order_col="nom";
    if($sort_act==='quantite')     $sql_order_col="quantité";
    if($sort_act==='pru')          $sql_order_col="coût";
    if($sort_act==='val_actuelle') $sql_order_col="live_price_safe"; // Voir alias SQL ci-dessous
    if($sort_act==='date')         $sql_order_col="date_achat";
    if($sort_act==='performance')  $sql_order_col="perf_calc";       
    
    // Requête principale : Jointure LEFT avec la table Stock pour avoir le prix en date
    // COALESCE permet d'utiliser 'val_actuelle' (prix d'achat ou ancienne maj) si 'Stock.price' est null.
    $sql_act="SELECT A.*,S.price as live_price,COALESCE(S.price,A.val_actuelle) as live_price_safe,(COALESCE(S.price,A.val_actuelle)-A.coût)*A.quantité as perf_calc FROM Actions A LEFT JOIN Stock S ON A.nom=S.symbol WHERE A.id_user=:id ORDER BY $sql_order_col $order_sql_act";
    $stmt_act=$db->prepare($sql_act);
    $stmt_act->execute([':id'=>$id]);
    $actions=$stmt_act->fetchAll();

    // =================================================================================
    // 5. GESTION DE L'HISTORIQUE (LIMITATION + TRI)
    // =================================================================================
    $limit_transac=isset($_GET['limit_transac'])?(int)$_GET['limit_transac']:10;
    $sort_trans=$_GET['sort_trans']??'date_transac';
    $order_trans=$_GET['order_trans']??'DESC';
    $next_order_trans=($order_trans==='ASC')?'DESC':'ASC';
    $order_sql_trans=($order_trans==='ASC')?'ASC':'DESC';

    $valid_sorts_trans=['date_transac','nom_action','type_transac','total'];
    if(!in_array($sort_trans,$valid_sorts_trans))$sort_trans='date_transac';
    
    $sql_hist="SELECT * FROM Transactions WHERE id_user=:id ORDER BY $sort_trans $order_sql_trans LIMIT :limit";
    $stmt_hist=$db->prepare($sql_hist);
    $stmt_hist->bindValue(':id',$id,PDO::PARAM_INT);
    $stmt_hist->bindValue(':limit',$limit_transac,PDO::PARAM_INT);
    $stmt_hist->execute();
    $historique=$stmt_hist->fetchAll();

    // =================================================================================
    // 6. CALCULS GLOBAUX POUR AFFICHAGE CAMEMBERT & HEADER
    // =================================================================================
    $total_actions_value=0;
    
    // Préparation des tableaux JS pour Chart.js (Répartition)
    $pie_labels=['Liquidités'];
    $pie_data=[(float)$budget];

    foreach($actions as $act){
        // Prix unitaire retenu
        $p=($act['live_price']>0)?$act['live_price']:$act['val_actuelle'];
        $val=$p*$act['quantité'];
        $total_actions_value+=$val;

        // Ajout au data du Pie Chart
        $pie_labels[]=$act['nom'];
        $pie_data[]=$val;
    }
    // Valorisation totale = Cash Dispo + Valeur Marché des Actions
    $total_portfolio_value=$budget+$total_actions_value;

}catch(PDOException $e){die("Erreur de base de données : ".$e->getMessage());}

// Variables d'agrément pour le template HTML
$nom=isset($user['nom'])?$user['nom']:'Inconnu';
$prenom=isset($user['prenom'])?$user['prenom']:'Inconnu';
$pseudo=isset($user['name_user'])?$user['name_user']:'Pseudo';
$avatar=isset($user['avatar_url'])?$user['avatar_url']:'';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <!-- MÉTADONNÉES ET RESSOURCES -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Portefeuille — BoursoLine</title>
    <!-- Police Google Fonts (Outfit) pour un style moderne -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- Feuille de style spécifique au portefeuille -->
    <link rel="stylesheet" href="./css/portefeuille.css">
    <!-- Librairie Chart.js pour les graphiques interactifs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="./js/theme.js"></script>
</head>
<body>
    <!-- EN-TÊTE SUPÉRIEUR (TOP BAR) -->
    <!-- Contient les infos utilisateur, la navigation et le résumé du budget -->
    <header class="top-bar">
        <!-- Section Utilisateur (Avatar + Nom + Logout) -->
        <div class="user-section">
            <a href="mon_compte.php" title="Modifier mon profil" style="text-decoration:none;">
                <?php if (!empty($avatar)): ?>
                    <img src="<?= htmlspecialchars($avatar) ?>" alt="Avatar" class="user-avatar">
                <?php else: ?>
                    <!-- Avatar par défaut si aucun n'est défini -->
                    <div class="user-avatar" style="display: flex; align-items: center; justify-content: center; font-size: 20px; color:white;">?</div>
                <?php endif; ?>
            </a>
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($prenom . ' ' . $nom) ?></span>
                <span class="user-pseudo">@<?= htmlspecialchars($pseudo) ?></span>
                <a href="logout.php" style="display:block; margin-top:5px; font-size:0.8rem; color:#ff6b6b; text-decoration:none;">Se déconnecter</a>
            </div>
        </div>

        <!-- Navigation Principale -->
        <nav>
            <a href="profil.php" class="nav-link">Accueil</a>
            <span class="nav-separator">|</span>
            <a href="portefeuille.php" class="nav-link nav-link--active">Mon Portefeuille</a>
            <span class="nav-separator">|</span>
            <a href="marketplace.php" class="nav-link">Marketplace</a>
        </nav>

        <!-- Affichage des Solpes (Espèces et Valorisation Totale) -->
        <div class="budget-section">
            <span class="budget-display">Solde Espèces : <span class="budget-value"><?= number_format($budget, 2, ',', ' ') ?> €</span></span>
            <span class="nav-separator">|</span>
            <span class="budget-display">Valorisation Totale : <span class="budget-value" style="color:#0984e3;"><?= number_format($total_portfolio_value, 2, ',', ' ') ?> €</span></span>
        </div>
    </header>

    <!-- CONTENU PRINCIPAL -->
    <main>
        <!-- Formulaire Global (Principalement utilisé pour encapsuler l'interface, les actions se font via JS/AJAX) -->
        <form method="post" action="">
            <h1>Mon Portefeuille</h1>
            <!-- GRILLE DE TABLEAU DE BORD (GRAPHIQUES) -->
            <div class="dashboard-grid">
                <!-- Colonne Gauche : Graphique d'évolution du solde -->
                <div class="left-col">
                    <div class="card">
                        <h2 class="card-title">Évolution du Portefeuille</h2>
                        <!-- Contrôles du Graphique d'historique -->
                        <div class="chart-header">
                            <div class="period-selector">
                                <button type="button" class="btn-period" onclick="updateChart('1M', this)">1M</button>
                                <button type="button" class="btn-period" onclick="updateChart('3M', this)">3M</button>
                                <button type="button" class="btn-period" onclick="updateChart('6M', this)">6M</button>
                                <button type="button" class="btn-period" onclick="updateChart('YTD', this)">YTD</button>
                                <button type="button" class="btn-period active" onclick="updateChart('ALL', this)">Tout</button>
                            </div>
                            <div class="chart-options">
                                <label><input type="checkbox" id="chkSmoothing" onchange="updateChart(currentPeriod)"> Lissage</label>
                            </div>
                        </div>
                        <!-- Canvas Chart.js -->
                        <div class="chart-container"><canvas id="portfolioChart"></canvas></div>
                    </div>
                </div>

                <!-- Colonne Droite : Répartition du portefeuille (Camembert) -->
                <div class="right-col">
                    <div class="card">
                        <h2 class="card-title">Répartition du Portefeuille</h2>
                        <div class="chart-container chart-container--allocation"><canvas id="allocationChart"></canvas></div>
                    </div>
                </div>
            </div>

            <!-- SECTION ACHAT / VENTE (INTERFACE DE MARCHÉ) -->
            <div class="card card--dashed market-card">
                <h2 class="card-title market-title"><span>Achat / Vente d'Actions</span></h2>
                
                <!-- Champ de Recherche d'Actions -->
                <div class="market-input-wrapper">
                    <input type="text" id="marketInput" class="market-input" placeholder="Rechercher une action (ex: Apple, TSLA)..." autocomplete="off">
                    <div id="searchResults" class="search-results-dropdown"></div>
                </div>
                <div id="marketIntro" class="market-intro">Saisissez le nom ou le symbole d'une société pour afficher ses données en temps réel.</div>

                <!-- PANNEAU DE DÉTAIL ACTION (Masqué par défaut, affiché après sélection) -->
                <div id="stockDetailsPanel" class="stock-details-panel">
                    <div class="stock-panel-grid">
                        <!-- Partie Gauche : Infos et Graphique de l'action sélectionnée -->
                        <div>
                            <div class="stock-header stock-header-flex">
                                <div>
                                    <h3 id="sdName" class="stock-header__name stock-header-title">Action</h3>
                                    <small id="sdSymbol" class="stock-header__symbol stock-header-symbol">SYMB</small>
                                </div>
                                <div class="stock-header__prices stock-prices-right">
                                    <span class="price-big" id="sdPrice">0.00 $</span>
                                    <div class="price-change-wrapper"><span class="price-change" id="sdChange">0.00%</span></div>
                                </div>
                            </div>
                            <!-- Graphique Intra-action -->
                            <div class="sd-chart-container chart-wrapper"><canvas id="stockChart"></canvas></div>
                        </div>

                        <!-- Partie Droite : Formulaire d'Achat -->
                        <div class="buy-form buy-form-container">
                            <h3 class="buy-form-title">Passer un ordre d'achat</h3>
                            <div class="price-summary-box">
                                <div class="price-row"><span class="price-label">Prix unitaire :</span><strong id="lblUnitPrice">--</strong></div>
                                <div class="total-row"><span>Total estimé :</span><strong id="lblTotalPrice" class="total-value-highlight">--</strong></div>
                            </div>
                            <label for="buyQty" class="qty-label">Quantité :</label>
                            <input type="number" id="buyQty" min="1" value="1" class="buy-form__qty-input qty-input" name="quantity_purchase">
                            <button type="button" class="btn-action btn-buy btn-buy-large" id="btnBuyAction" name="purchase">ACHETER MAINTENANT</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        
        <!-- TABLEAU: MES ACTIONS POSSÉDÉES -->
        <div class="card card--overflow" id="mes-actions">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2 class="card-title">Mes Actions Possédées</h2>
                <!-- Bouton Refresh pour forcer la mise à jour des prix API -->
                <a href="?refresh=1" class="btn-action" style="background:#555; color:#fff; text-decoration:none; padding:5px 10px; border-radius:4px; font-size:0.9em;">&#x21bb; Actualiser les cours</a>
            </div>
            <?php if (count($actions) > 0): ?>
                <table>
                    <thead>
                        <!-- En-têtes triables pour les actions -->
                        <tr>
                            <th><a href="?sort_act=nom&order_act=<?= $next_order_act ?>#mes-actions">Nom <?= ($sort_act=='nom')?($order_act=='ASC'?'&#9650;':'&#9660;'):'' ?></a></th>
                            <th><a href="?sort_act=date&order_act=<?= $next_order_act ?>#mes-actions">Date Achat <?= ($sort_act=='date')?($order_act=='ASC'?'&#9650;':'&#9660;'):'' ?></a></th>
                            <th><a href="?sort_act=quantite&order_act=<?= $next_order_act ?>#mes-actions">Quantité <?= ($sort_act=='quantite')?($order_act=='ASC'?'&#9650;':'&#9660;'):'' ?></a></th> 
                            <th><a href="?sort_act=pru&order_act=<?= $next_order_act ?>#mes-actions">Prix d'achat <?= ($sort_act=='pru')?($order_act=='ASC'?'&#9650;':'&#9660;'):'' ?></a></th> 
                            <th><a href="?sort_act=val_actuelle&order_act=<?= $next_order_act ?>#mes-actions">Cours actuel <?= ($sort_act=='val_actuelle')?($order_act=='ASC'?'&#9650;':'&#9660;'):'' ?></a></th>
                            <th><a href="?sort_act=performance&order_act=<?= $next_order_act ?>#mes-actions">Performance <?= ($sort_act=='performance')?($order_act=='ASC'?'&#9650;':'&#9660;'):'' ?></a></th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($actions as $act): ?>
                            <?php
                                // Calculs de performance pour chaque ligne
                                $current_price = ($act['live_price'] > 0) ? $act['live_price'] : $act['val_actuelle'];
                                $total_achat  = $act['coût'] * $act['quantité'];
                                $total_actuel = $current_price * $act['quantité'];
                                $pv      = $total_actuel - $total_achat; // Plus-value/Moins-value
                                $percent = ($total_achat > 0) ? ($pv / $total_achat) * 100 : 0;
                                $perfClass = ($pv >= 0) ? 'perf-positive' : 'perf-negative';
                                $date_achat = $act['date_achat'] ? date('d/m/Y', strtotime($act['date_achat'])) : 'N/A';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($act['nom']) ?></strong></td>
                                <td><small><?= $date_achat ?></small></td>
                                <td><?= $act['quantité'] ?></td>
                                <td><?= number_format($act['coût'], 2) ?> €</td>
                                <td><?= number_format($current_price, 2) ?> €</td>
                                <td class="<?= $perfClass ?>">
                                    <?= ($pv > 0 ? '+' : '') . number_format($pv, 2) ?> €<br>
                                    <small><?= number_format($percent, 1) ?> %</small>
                                </td>
                                <td>
                                    <!-- Bouton Vente Rapide -->
                                    <a href="#" class="btn-action btn-sell" onclick="vendreAction('<?= htmlspecialchars($act['nom']) ?>', <?= $current_price ?>, <?= $act['quantité'] ?>)">Vendre</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">Vous ne possédez aucune action. Rendez-vous sur le marché !</div>
            <?php endif; ?>
        </div>

        <!-- TABLEAU: HISTORIQUE DES TRANSACTIONS -->
        <div class="card card--mt" id="mes-transactions">
             <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h2 class="card-title" style="margin-bottom:0;">Dernières Transactions</h2>
                <!-- Filtre de limite (5, 10, 20...) -->
                <form method="GET" action="portefeuille.php#mes-transactions">
                    <?php 
                      if(isset($_GET['sort_act'])) echo '<input type="hidden" name="sort_act" value="'.htmlspecialchars($_GET['sort_act']).'">';
                      if(isset($_GET['order_act'])) echo '<input type="hidden" name="order_act" value="'.htmlspecialchars($_GET['order_act']).'">';
                    ?>
                    <label style="font-size:0.9em;">Afficher :</label>
                    <select name="limit_transac" onchange="this.form.submit()" style="padding:4px; border-radius:4px;">
                        <option value="5" <?= ($limit_transac==5)?'selected':'' ?>>5</option>
                        <option value="10" <?= ($limit_transac==10)?'selected':'' ?>>10</option>
                        <option value="20" <?= ($limit_transac==20)?'selected':'' ?>>20</option>
                        <option value="50" <?= ($limit_transac==50)?'selected':'' ?>>50</option>
                        <option value="999" <?= ($limit_transac==999)?'selected':'' ?>>Toutes</option>
                    </select>
                </form>
            </div>
            <?php if (count($historique) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th class="th--action-date"><a href="?limit_transac=<?=$limit_transac?>&sort_trans=date_transac&order_trans=<?=$next_order_trans?>#mes-transactions" style="color:inherit;text-decoration:none;">Action / Date <?= ($sort_trans=='date_transac')?($order_trans=='ASC'?'&#9650;':'&#9660;'):'' ?></a></th>
                            <th class="th--type-qty"><a href="?limit_transac=<?=$limit_transac?>&sort_trans=type_transac&order_trans=<?=$next_order_trans?>#mes-transactions" style="color:inherit;text-decoration:none;">Type / Quantité <?= ($sort_trans=='type_transac')?($order_trans=='ASC'?'&#9650;':'&#9660;'):'' ?></a></th>
                            <th class="th--amount"><a href="?limit_transac=<?=$limit_transac?>&sort_trans=total&order_trans=<?=$next_order_trans?>#mes-transactions" style="color:inherit;text-decoration:none;">Montant Total <?= ($sort_trans=='total')?($order_trans=='ASC'?'&#9650;':'&#9660;'):'' ?></a></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($historique as $hist): ?>
                            <tr>
                                <td>
                                    <div class="transaction-cell">
                                        <div class="transaction-icon"><?= substr($hist['nom_action'] ?? '?', 0, 1) ?></div>
                                        <div>
                                            <strong class="transaction-name"><?= htmlspecialchars($hist['nom_action'] ?? 'Inconnu') ?></strong><br>
                                            <span class="transaction-date"><?= date('d/m/Y H:i', strtotime($hist['date_transac'])) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="transaction-type-cell">
                                        <?php if ($hist['type_transac'] === 'Purchase'): ?>
                                            <span class="badge badge-buy badge--lg">Achat</span>
                                        <?php else: ?>
                                            <span class="badge badge-sell badge--lg">Vente</span>
                                        <?php endif; ?>
                                        <span class="transaction-qty"><?= $hist['quantité'] ?> unité(s)</span>
                                    </div>
                                </td>
                                <td><span class="transaction-amount"><?= number_format($hist['total'], 2, ',', ' ') ?> €</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">Aucune transaction récente.</div>
            <?php endif; ?>
        </div>
    </main>

    <!-- SCRIPTS JS POUR L'INTERACTIVITÉ -->
    <script>
        /*******************************************************
         * 1. GRAPHIQUE D'ÉVOLUTION DU SOLDE (Chart.js)
         * Gère l'affichage de l'historique du portefeuille
         *******************************************************/
        const ctx = document.getElementById('portfolioChart').getContext('2d');
        let portfolioChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'Solde Espèces',
                        data: [],
                        borderColor: '#00b894',
                        backgroundColor: 'rgba(0, 184, 148, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 0,
                        hoverRadius: 6
                    },
                    {
                        label: 'Valeur des Actions',
                        data: [],
                        borderColor: '#6c5ce7', /* Violet */
                        backgroundColor: 'rgba(108, 92, 231, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 0,
                        hoverRadius: 6
                    },
                    {
                        label: 'Valorisation Totale (Espèces + Actions)',
                        data: [],
                        borderColor: '#0984e3',
                        backgroundColor: 'rgba(9, 132, 227, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 0,
                        hoverRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top', labels: { boxWidth: 12, usePointStyle: true, font: {size: 11} } },
                    tooltip: { 
                        mode: 'index', 
                        intersect: false, 
                        callbacks: { label: function(context) { return context.dataset.label + ' : ' + context.parsed.y.toFixed(2) + ' €'; } } 
                    }
                },
                scales: {
                    y: { beginAtZero: false, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }
                },
                interaction: { mode: 'nearest', intersect: false }
            }
        });

        let currentPeriod = 'ALL';

        // Fonction de mise à jour du graphique (appel AJAX)
        function updateChart(period, btn = null) {
            currentPeriod = period;
            // Gestion de l'état "actif" des boutons
            if (btn) {
                document.querySelectorAll('.btn-period').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            } else {
                document.querySelectorAll('.btn-period').forEach(b => {
                    const txt = b.textContent.trim();
                    if(txt === period || (period === 'ALL' && txt === 'Tout')) {
                        document.querySelectorAll('.btn-period').forEach(x => x.classList.remove('active'));
                        b.classList.add('active');
                    }
                });
            }
            
            const smoothing = document.getElementById('chkSmoothing').checked;
            
            // Récupération des données JSON depuis PHP
            fetch(`portefeuille.php?ajax_graph=1&period=${period}&smoothing=${smoothing}`)
                .then(res => { if (!res.ok) throw new Error("Erreur réseau"); return res.json(); })
                .then(data => {
                    portfolioChart.data.labels = data.labels;
                    portfolioChart.data.datasets[0].data = data.data; // Cash
                    if(data.data_stocks) {
                        portfolioChart.data.datasets[1].data = data.data_stocks; // Actions
                    }
                    if(data.data_total) {
                        portfolioChart.data.datasets[2].data = data.data_total; // Total
                    }
                    portfolioChart.update();
                })
                .catch(err => console.error("Erreur chargement graph:", err));
        }

        // Chargement initial du graphique au démarrage
        document.addEventListener('DOMContentLoaded', () => {
             const btnAll = document.querySelector('.btn-period.active'); 
             if(btnAll) { updateChart('ALL', btnAll); } else { updateChart('ALL'); }
        });

        /*******************************************************
         * 2. GRAPHIQUE SECTORIEL (ALLOCATION)
         * Répartition des avoirs (Cash vs Actions)
         *******************************************************/
        const ctxPie = document.getElementById('allocationChart').getContext('2d');
        const pieLabels = <?= json_encode($pie_labels) ?>;
        const pieData   = <?= json_encode($pie_data) ?>;
        
        const baseColors = ['#00b894', '#0984e3', '#6c5ce7', '#fdcb6e', '#e17055', '#d63031', '#e84393', '#00cec9', '#2d3436', '#ffeaa7'];
        
        new Chart(ctxPie, {
            type: 'doughnut',
            data: {
                labels: pieLabels,
                datasets: [{
                    label: 'Montant (€)',
                    data: pieData,
                    backgroundColor: baseColors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'left', labels: { boxWidth: 10, padding: 10, font: { size: 10 } } }
                }
            }
        });

        /*******************************************************
         * 3. LOGIQUE D'ACHAT / RECHERCHE (API AlphaVantage)
         *******************************************************/
        const marketInput      = document.getElementById('marketInput');
        const searchResults    = document.getElementById('searchResults');
        const stockDetailsPanel = document.getElementById('stockDetailsPanel');
        const sdName   = document.getElementById('sdName'), sdSymbol = document.getElementById('sdSymbol');
        const sdPrice  = document.getElementById('sdPrice'), sdChange = document.getElementById('sdChange');
        const buyQtyInput   = document.getElementById('buyQty');
        const lblUnitPrice  = document.getElementById('lblUnitPrice'), lblTotalPrice = document.getElementById('lblTotalPrice');

        let stockChartInstance = null;
        let selectedSymbol     = null;
        let currentStockPrice  = 0.0;
        let timeoutId;

        // Mise à jour du prix total lors du changement de quantité
        buyQtyInput.addEventListener('input', updateBuyData);
        function updateBuyData() {
            const qty = parseInt(buyQtyInput.value);
            if (!isNaN(qty) && qty > 0 && currentStockPrice > 0) {
                lblUnitPrice.textContent  = currentStockPrice.toFixed(2) + " $";
                lblTotalPrice.textContent = (qty * currentStockPrice).toFixed(2) + " $";
            } else {
                lblUnitPrice.textContent  = "--";
                lblTotalPrice.textContent = "--";
            }
        }

        // Auto-complétion lors de la saisie (Debounce 500ms)
        marketInput.addEventListener('input', (e) => {
            clearTimeout(timeoutId);
            const query = e.target.value.trim();
            if (query.length < 2) { searchResults.style.display = 'none'; return; }
            timeoutId = setTimeout(() => searchStocks(query), 500);
        });

        // Fermer les résultats si clic externe
        document.addEventListener('click', (e) => {
            if (!marketInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.style.display = 'none';
            }
        });

        // Recherche d'actions via api.php
        async function searchStocks(query) {
            try {
                const response = await fetch(`api.php?action=search&keywords=${query}`);
                const data = await response.json();
                if (data.bestMatches) {
                    displaySearchResults(data.bestMatches);
                } else if (data['Information'] || data['Note']) {
                    // Gestion erreurs API
                    console.warn("API Msg:", data['Information'] || data['Note']);
                }
            } catch (error) { console.error('Error searching stocks:', error); }
        }

        // Affichage des résultats
        function displaySearchResults(matches) {
            searchResults.innerHTML = '';
            if (matches.length === 0) { searchResults.style.display = 'none'; return; }
            matches.forEach(match => {
                const div = document.createElement('div');
                div.className = 'search-item';
                div.innerHTML = `<span class="symbol">${match['1. symbol']}</span><span class="name">${match['2. name']}</span>`;
                div.addEventListener('click', () => selectStock(match['1. symbol'], match['2. name']));
                searchResults.appendChild(div);
            });
            searchResults.style.display = 'block';
        }

        // Sélection d'une action
        async function selectStock(symbol, name) {
            selectedSymbol = symbol;
            marketInput.value = symbol;
            searchResults.style.display = 'none';
            
            // UI Update
            document.getElementById('marketIntro').style.display = 'none';
            stockDetailsPanel.style.display = 'block';
            sdName.textContent  = name || symbol;
            sdSymbol.textContent = symbol;
            sdPrice.textContent  = "Chargement...";
            sdChange.textContent = "--";

            // Récupération du prix (Quote)
            fetch(`api.php?action=quote&symbol=${symbol}`)
                .then(r => r.json())
                .then(data => {
                    const quote = data['Global Quote'];
                    if (quote) {
                        const price  = parseFloat(quote['05. price']);
                        const change = parseFloat(quote['10. change percent']);
                        currentStockPrice = price;
                        sdPrice.textContent  = `${price.toFixed(2)}\u00A0$`;
                        sdChange.textContent = (change >= 0 ? '+' : '') + `${change.toFixed(2)}%`;
                        sdChange.className   = 'price-change ' + (change >= 0 ? 'positive' : 'negative');
                        updateBuyData();
                    } else { sdPrice.textContent = "N/A"; }
                }).catch(err => console.error(err));

            // Récupération Historique (Daily) pour le mini-graphe
            fetch(`api.php?action=history&symbol=${symbol}`)
                .then(r => r.json())
                .then(data => {
                    const timeSeries = data['Time Series (Daily)'];
                    if (timeSeries) {
                        const dates  = Object.keys(timeSeries).slice(0, 30).reverse();
                        const prices = dates.map(date => timeSeries[date]['4. close']);
                        renderStockChart(dates, prices, symbol);
                    }
                }).catch(err => console.error(err));
        }

        // Rendu du graphique stock
        function renderStockChart(labels, data, symbol) {
            const ctx = document.getElementById('stockChart').getContext('2d');
            if (stockChartInstance) stockChartInstance.destroy();
            stockChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: symbol + ' Prix ($)',
                        data,
                        borderColor: '#2980b9', backgroundColor: 'rgba(41, 128, 185, 0.1)',
                        borderWidth: 2, pointRadius: 0, fill: true, tension: 0.1
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
                    scales: { x: { display: false }, y: { position: 'right' } },
                    interaction: { mode: 'nearest', axis: 'x', intersect: false }
                }
            });
        }

        // Action ACHETER
        document.getElementById('btnBuyAction').addEventListener('click', async () => {
            const qty = parseInt(document.getElementById('buyQty').value);
            if (!selectedSymbol) { alert("Sélectionnez une action."); return; }
            if (qty < 1)         { alert("Quantité invalide."); return; }
            
            const response = await fetch('achat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ symbol: selectedSymbol, quantite: qty, prix: currentStockPrice })
            });
            const result = await response.json();
            if (result.success) {
                alert(`Achat effectué ! Nouveau solde : ${result.nouveau_budget} €`);
                location.reload();
            } else {
                alert("Erreur : " + result.message);
            }
        });

        /*******************************************************
         * 4. LOGIQUE DE VENTE (Actions détenues)
         *******************************************************/
        async function vendreAction(symbol, prix, qteMax) {
            const qty = parseInt(prompt(`Combien d'actions "${symbol}" voulez-vous vendre ? (max: ${qteMax})`));
            if (isNaN(qty) || qty < 1) { alert("Quantité invalide."); return; }
            if (qty > qteMax)          { alert(`Vous ne possédez que ${qteMax} action(s).`); return; }
            if (!confirm(`Vendre ${qty} x "${symbol}" à ${prix} $ = ${(qty * prix).toFixed(2)} $ ?`)) return;

            const response = await fetch('vente.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ symbol, quantite: qty, prix })
            });
            const result = await response.json();
            if (result.success) {
                alert(`Vente effectuée ! Nouveau solde : ${result.nouveau_budget} €`);
                location.reload();
            } else {
                alert("Erreur : " + result.message);
            }
        }
    </script>
</body>
</html>
