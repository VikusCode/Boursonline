<?php
/**
 * PAGE MON COMPTE (Modification de profil)
 * ----------------------------------------
 * Permet à l'utilisateur connecté de modifier ses informations personnelles :
 * - Nom, Prénom, Age, Sexe...
 * - Avatar (Upload fichier ou lien URL)
 * - Mot de passe (Hachage sécurisé)
 */

session_start();
require_once 'database.php';

// Vérification de l'authentification
if(empty($_SESSION['user_id'])){ header('Location:login.php'); exit(); }

$id = $_SESSION['user_id'];
$message = "";

// 1. Récupération des données actuelles de l'utilisateur
$stmt = $db->prepare("SELECT * FROM Utilisateur WHERE id = :id");
$stmt->execute([':id' => $id]);
$user = $stmt->fetch();

// Si l'utilisateur n'existe plus en base, déconnexion forcée
if(!$user){ session_destroy(); header('Location:login.php'); exit(); }

// 2. Traitement du formulaire de mise à jour
if(isset($_POST["valider"])) {
    // Récupération et nettoyage des entrées
    $prenom    = $_POST['prenom'];
    $nom       = $_POST['nom'];
    $age       = $_POST['age'];
    $sexe      = $_POST['sexe'] ?? $user['sexe'];
    $naissance = $_POST['naissance'];
    $pays      = $_POST['pays'];
    $email     = $_POST['email'];
    
    // Gestion de l'Avatar
    $avatar = $user['avatar_url']; // Valeur par défaut : on garde l'actuel

    // Cas A : Upload de fichier
    if(isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
        if(!file_exists('avatars')) mkdir('avatars', 0777, true);
        
        $tmp_name = $_FILES['avatar_file']['tmp_name'];
        $name = basename($_FILES['avatar_file']['name']);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $new_name = uniqid('avatar_') . '.' . $extension; // Nom unique pour éviter les conflits
        $destination = 'avatars/' . $new_name;
        
        if(move_uploaded_file($tmp_name, $destination)) {
             $avatar = $destination;
        }
    } 
    // Cas B : URL directe fournie
    else if(!empty($_POST['avatar_url'])) {
        $avatar = $_POST['avatar_url'];
    }

    // Construction de la requête SQL dynamique
    $sql = "UPDATE Utilisateur SET prenom=:prenom, nom=:nom, age=:age, sexe=:sexe, date_naissance=:naissance, pays=:pays, avatar_url=:avatar, email=:email";
    
    $params = [
        ':prenom' => $prenom, ':nom' => $nom,
        ':age' => $age,       ':sexe' => $sexe,
        ':naissance' => $naissance, ':pays' => $pays,
        ':avatar' => $avatar, ':email' => $email,
        ':id' => $id
    ];

    // Mise à jour du mot de passe uniquement si renseigné
    if (!empty($_POST['password'])) {
        $sql .= ", password = :password";
        $params[':password'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
    }

    $sql .= " WHERE id = :id";

    try {
        $updateStmt = $db->prepare($sql);
        $updateStmt->execute($params);
        
        // Rafraîchissement des données affichées
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        
        $message = "<div class='success-message' style='color:green;text-align:center;margin-bottom:20px;'>Informations mises à jour avec succès !</div>";
    } catch (PDOException $e) {
        $message = "<div class='error-message' style='color:red;text-align:center;margin-bottom:20px;'>Erreur : " . $e->getMessage() . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Compte — BoursoLine</title>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Outfit:wght@300;400;500&display=swap" rel="stylesheet">
    <!-- Réutilisation du CSS d'inscription pour cohérence visuelle -->
    <link href="./css/inscription.css" rel="stylesheet">
    <link href="./css/mon_compte.css" rel="stylesheet">
    <script src="./js/theme.js"></script>
</head>
<body>
    <div class="card">
        <h1>Modifier mon <em>compte</em></h1>
        
        <!-- Affichage de l'avatar actuel -->
        <?php if(!empty($user['avatar_url'])): ?>
            <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="Avatar actuel" class="current-avatar">
        <?php endif; ?>

        <?= $message ?>

        <form action="" method="post" enctype="multipart/form-data">
            <div class="row">
                <div class="form-group">
                    <label for="prenom">Prénom</label>
                    <input type="text" name="prenom" id="prenom" value="<?= htmlspecialchars($user['prenom']) ?>" required />
                </div>
                <div class="form-group">
                    <label for="nom">Nom</label>
                    <input type="text" name="nom" id="nom" value="<?= htmlspecialchars($user['nom']) ?>" required />
                </div>
            </div>
            
            <div class="row">
                <div class="form-group">
                    <label for="age">Âge</label>
                    <input type="number" name="age" id="age" min="0" max="150" value="<?= htmlspecialchars($user['age']) ?>" />
                </div>
                <!-- Choix du Sexe -->
                <div class="form-group">
                    <label>Sexe</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="sexe" value="F" <?= ($user['sexe'] === 'F') ? 'checked' : '' ?> /> F</label>
                        <label class="radio-label"><input type="radio" name="sexe" value="H" <?= ($user['sexe'] === 'H') ? 'checked' : '' ?> /> H</label>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="naissance">Date de naissance</label>
                <input type="date" name="naissance" id="naissance" value="<?= htmlspecialchars($user['date_naissance']) ?>" required />
            </div>
            
            <div class="form-group">
                <label for="pays">Pays</label>
                <input type="text" name="pays" id="pays" value="<?= htmlspecialchars($user['pays']) ?>" required />
            </div>

            <!-- Section Avatar -->
            <fieldset>
                <legend>Changer d'Avatar</legend>
                <input type="file" name="avatar_file" id="avatar_file" accept="image/*" />
                <div class="or-text">ou via URL</div>
                <input type="url" name="avatar_url" id="avatar_url" placeholder="https://..." />
            </fieldset>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars($user['email']) ?>" required />
            </div>
            
            <div class="form-group">
                <label for="password">Nouveau mot de passe (laisser vide pour ne pas changer)</label>
                <input type="password" name="password" id="password" placeholder="••••••" />
            </div>

            <button type="submit" name="valider" class="btn-submit">Enregistrer les modifications</button>
        </form>
        <a href="profil.php" class="back-link">← Retour au Profil</a>
    </div>
</body>
</html>
