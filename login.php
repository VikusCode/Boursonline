<?php
/**
 * PAGE DE CONNEXION (login.php)
 * -----------------------------
 * Gère l'authentification des utilisateurs.
 * Vérifie le couple (Pseudo / Mot de passe) et initialise la session.
 */

session_start();
require_once 'database.php';

// Traitement du formulaire de connexion
if(isset($_POST["connexion"])) {
    $name_user = $_POST["user"];
    $password  = $_POST["mdp"];

    // Recherche de l'utilisateur par son pseudo
    $stmt = $db->prepare("SELECT id, name_user, password FROM Utilisateur WHERE name_user = :name_user");
    $stmt->bindParam(':name_user', $name_user);
    $stmt->execute();

    $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

    // Vérification du mot de passe haché
    if($utilisateur && password_verify($password, $utilisateur["password"])) {
        // Connexion réussie : Initialisation de la session
        $_SESSION['user_id'] = $utilisateur['id'];
        $_SESSION['user_name'] = $utilisateur['name_user'];
        
        // Redirection vers le tableau de bord
        header("Location: portefeuille.php");
        exit(); 
    }
    else {
        // Echec de connexion
        $error_msg = "Nom d'utilisateur ou mot de passe incorrect.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — BoursoLine</title>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Outfit:wght@300;400;500&display=swap" rel="stylesheet">
    <link href="./css/login.css" rel="stylesheet">
    <script src="./js/theme.js"></script>
</head>
<body>
    <div class="card">
        <h1>Se <em>connecter</em></h1>

        <?php if(isset($error_msg)): ?>
            <div style="color:red; text-align:center; margin-bottom:15px; font-size:0.9em;"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <form action="" method="post" id="saisie_user">
            <div class="form-group">
                <label for="user">Nom d'utilisateur</label>
                <input type="text" name="user" id="user" placeholder="Votre pseudo" required />
            </div>
            
            <div class="form-group">
                <label for="mdp">Mot de passe</label>
                <input type="password" name="mdp" id="mdp" placeholder="Votre mot de passe" required />
            </div>
            
            <button type="submit" name="connexion" class="btn">Se connecter</button>
            <div class="divider"></div>
            <div class="signup-link">Pas encore de compte ? <a href="inscription.php">S'inscrire</a></div>
        </form>
    </div>
</body>
</html>
