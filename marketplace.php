<?php
session_start();
require_once 'database.php';

if(empty($_SESSION['user_id'])){
    header('Location: login.php');
    exit();
}

$id = $_SESSION['user_id'];

// Récupération infos utilisateur
$stmt = $db->prepare("SELECT prenom, nom, avatar_url, name_user FROM Utilisateur WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$prenom = $user['prenom'] ?? '';
$nom = $user['nom'] ?? '';
$avatar = $user['avatar_url'] ?? '';
$pseudo = $user['name_user'] ?? '';

// Récupération Solde
$stmt = $db->prepare("SELECT somme_actuelle FROM Porte_monnaie WHERE id_user = ?");
$stmt->execute([$id]);
$budget = $stmt->fetchColumn() ?: 0;

// Pagination et Recherche
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$search = isset($_GET['q']) ? $_GET['q'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'symbol';
$order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

// Allow list for sorting
$allowed_sorts = ['symbol', 'nom'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'symbol';
}

$next_order = ($order === 'ASC') ? 'desc' : 'asc';

try {
    // Total count query
    if ($search) {
        $stmt_count = $db->prepare("SELECT COUNT(*) FROM Stock WHERE nom LIKE :search OR symbol LIKE :search");
        $stmt_count->bindValue(':search', "%$search%");
        $stmt_count->execute();
    } else {
        $stmt_count = $db->query("SELECT COUNT(*) FROM Stock");
    }
    $total_rows = $stmt_count->fetchColumn();
    $total_pages = max(1, ceil($total_rows / $limit));

    // List query
    $sql = "SELECT * FROM Stock";
    if ($search) {
        $sql .= " WHERE nom LIKE :search OR symbol LIKE :search";
        // Priorité : Si le symbole matche (ou commence par), il apparait avant une action dont seul le nom matche
        $sql .= " ORDER BY CASE 
            WHEN symbol LIKE :search_exact THEN 1 
            WHEN symbol LIKE :search_start THEN 2 
            WHEN symbol LIKE :search THEN 3 
            ELSE 4 END ASC, $sort $order LIMIT :limit OFFSET :offset";
    } else {
        $sql .= " ORDER BY $sort $order LIMIT :limit OFFSET :offset";
    }
    
    $stmt = $db->prepare($sql);
    if ($search) {
        $stmt->bindValue(':search', "%$search%");
        $stmt->bindValue(':search_start', "$search%");
        $stmt->bindValue(':search_exact', "$search");
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur bdd: " . $e->getMessage());
}

// Pagination logic limits display
$start_page = max(1, $page - 3);
$end_page = min($total_pages, $page + 3);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace — BoursoLine</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="./css/portefeuille.css"> 
    <link rel="stylesheet" href="./css/marketplace.css">
    <script src="./js/theme.js"></script>
    <style>
        .market-header { text-align: center; margin-bottom: 20px; }
        .table-container { overflow-x: auto; background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f9f9f9; font-weight: 600; font-size: 14px; position: relative; }
        th a { text-decoration: none; color: #333; display: block; width: 100%; height: 100%; }
        th a:hover { color: #d32f2f; }
        tr:hover { background: #fdfdfd; }
        .symbol { font-weight: 600; color: #0056b3; }
        
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; flex-wrap: wrap; }
        .page-item { padding: 6px 12px; border: 1px solid #ddd; text-decoration: none; color: #333; border-radius: 4px; }
        .page-item.active { background: #9b59b6; color: white; border-color: #9b59b6; }
        .page-item:hover:not(.active) { background: #eee; }
        
        .change-pos { color: #27ae60; font-weight: 500; }
        .change-neg { color: #e74c3c; font-weight: 500; }

        .search-form { display: flex; justify-content: center; gap: 10px; margin-bottom: 20px; }
        .search-form input { padding: 10px 15px; border-radius: 20px; border: 1px solid #ccc; width: 300px; outline: none; }
        .search-form button { padding: 10px 20px; border-radius: 20px; border: none; background: #9b59b6; color: white; cursor: pointer; }
        
        html.dark-mode .table-container { background: #2d2d35; }
        html.dark-mode th { background: #1e1e24; }
        html.dark-mode th a { color: #f1f2f6; }
        html.dark-mode td { border-color: #444; color: #ccc; }
        html.dark-mode tr:hover { background: #3d3d45; }
        html.dark-mode .page-item { border-color: #444; color: #ccc; }
        html.dark-mode .page-item.active { background: #9b59b6; color: white; }
        html.dark-mode .page-item:hover:not(.active) { background: #444; }
        html.dark-mode .search-form input { background: #1e1e24; color: #fff; border-color: #555; }
    </style>
</head>
<body>
    <header class="top-bar">
        <div class="user-section">
            <a href="mon_compte.php" title="Modifier mon profil" style="text-decoration:none;">
                <?php if (!empty($avatar)): ?>
                    <img src="<?= htmlspecialchars($avatar) ?>" alt="Avatar" class="user-avatar">
                <?php else: ?>
                    <div class="user-avatar" style="display: flex; align-items: center; justify-content: center; font-size: 20px; color:white; background:#3498db; border-radius:50%; width:40px; height:40px;">?</div>
                <?php endif; ?>
            </a>
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($prenom . ' ' . $nom) ?></span>
                <span class="user-pseudo">@<?= htmlspecialchars($pseudo) ?></span>
                <a href="logout.php" style="display:block; margin-top:5px; font-size:0.8rem; color:#ff6b6b; text-decoration:none;">Se déconnecter</a>
            </div>
        </div>

        <nav>
            <a href="profil.php" class="nav-link">Accueil</a>
            <span class="nav-separator">|</span>
            <a href="portefeuille.php" class="nav-link">Mon Portefeuille</a>
            <span class="nav-separator">|</span>
            <a href="marketplace.php" class="nav-link nav-link--active">Marketplace</a>
        </nav>

        <div class="budget-section">
            <span class="budget-display">Solde Espèces : <span class="budget-value"><?= number_format($budget, 2, ',', ' ') ?> €</span></span>
        </div>
    </header>

    <main class="main-container">
        <div class="market-header">
            <h2>Marketplace des Actions</h2>
            <p>Explorez la liste complète de nos <?= number_format($total_rows) ?> actions disponibles.</p>
            <br>
            <form method="GET" action="marketplace.php" class="search-form">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher par nom ou symbole...">
                <button type="submit">Rechercher</button>
                <?php if($search): ?>
                    <a href="marketplace.php" style="line-height:38px; color:#e74c3c; text-decoration:none;">Annuler</a>
                <?php endif; ?>
            </form>
            <?php if($total_rows == 0): ?>
                <p style="color:#e74c3c; margin-top:20px;">Aucune action dans la base. <a href="import_actions.php" style="color:#9b59b6; font-weight:bold;">Importer avec l'API (une seule fois)</a></p>
            <?php endif; ?>
        </div>

        <?php if($total_rows > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><a href="?sort=symbol&order=<?= $next_order ?>&q=<?= urlencode($search) ?>">Symbole <?= ($sort=='symbol') ? ($order=='ASC'?'&#9650;':'&#9660;') : '' ?></a></th>
                        <th><a href="?sort=nom&order=<?= $next_order ?>&q=<?= urlencode($search) ?>">Nom de l'entreprise <?= ($sort=='nom') ? ($order=='ASC'?'&#9650;':'&#9660;') : '' ?></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stocks as $s): ?>
                    <tr>
                        <td class="symbol"><?= htmlspecialchars($s['symbol']) ?></td>
                        <td><?= htmlspecialchars($s['nom']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination logic -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>&order=<?= $order ?>" class="page-item">&laquo;</a>
                <?php endif; ?>

                <?php
                if ($start_page > 1) echo '<span class="page-item">...</span>';

                for ($i = $start_page; $i <= $end_page; $i++) {
                    echo '<a href="?page=' . $i . '&q=' . urlencode($search) . '&sort=' . $sort . '&order=' . $order . '" class="page-item ' . ($i == $page ? 'active' : '') . '">' . $i . '</a>';
                }

                if ($end_page < $total_pages) echo '<span class="page-item">...</span>';
                ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>&order=<?= $order ?>" class="page-item">&raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </main>
</body>
</html>
