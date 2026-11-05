<?php
/**
 * PAGE D'ACCUEIL (Landing Page)
 * -----------------------------
 * Point d'entrée principal de l'application BoursoLine.
 * Propose les actions principales :
 * 1. Connexion (Pour les utilisateurs existants).
 * 2. Inscription (Pour les nouveaux).
 * 3. Lien vers l'affichage des utilisateurs (Debug/Admin).
 */

require_once 'database.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accueil — BoursoLine</title>
    <!-- Police 'Instrument Serif' pour l'élégance et 'Outfit' pour le moderne -->
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Outfit:wght@300;400;500&display=swap" rel="stylesheet">
    <link href="./css/accueil.css" rel="stylesheet">
    <script src="./js/theme.js"></script>
</head>
<body>
    <div class="card">
        <h1>Bienvenue sur <em>BoursOnline</em></h1>
        <div class="actions">
            <!-- Bouton de Connexion -->
            <form method="post" action="login.php">
                <button type="submit" class="btn btn-primary">Se connecter</button>
            </form>

            <div class="divider"></div>
            
            <!-- Bouton d'Inscription -->
            <form method="post" action="inscription.php">
                <button type="submit" class="btn btn-secondary">Créer un compte</button>
            </form>
        </div>
    </div>
</body>
</html>
