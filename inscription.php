<?php
/**
 * PAGE D'INSCRIPTION (inscription.php)
 * ------------------------------------
 * Permet la création d'un nouveau compte utilisateur.
 * 
 * Fonctionnalités:
 * 1. Upload d'avatar (Fichier ou URL).
 * 2. Création de l'utilisateur en base de données (Utilisateur).
 * 3. Hachage sécurisé du mot de passe (PASSWORD_BCRYPT).
 * 4. Initialisation du porte-monnaie virtuel (Solde de départ configurable).
 * 5. Gestion transactionnelle (Commit/Rollback) pour garantir l'intégrité des données.
 */

require_once 'database.php';

// Création du répertoire avatars si inexistant
if(!file_exists('avatars')) { mkdir('avatars', 0777, true); }

$message = "";

// 1. Soumission du formulaire
if(isset($_POST["valider"])) {
    // A. Préparation de la requête d'insertion (Utilisateur)
    $stmt = $db->prepare("INSERT INTO Utilisateur (prenom, nom, age, sexe, date_naissance, pays, avatar_url, email, password, name_user) VALUES (:prenom, :nom, :age, :sexe, :naissance, :pays, :avatar, :email, :password, :name_user)");

    // B. Récupération des données POST
    $prenom    = $_POST['prenom'];
    $nom       = $_POST['nom'];
    $age       = $_POST['age'];
    $sexe      = $_POST['sexe'] ?? '';
    $naissance = $_POST['naissance'];
    $pays      = $_POST['pays'];
    $email     = $_POST['email'];
    $password  = password_hash($_POST['password'], PASSWORD_BCRYPT); // Sécurité : Hash du mdp
    $name_user = $_POST['name_user'];

    // 1. Vérification des champs obligatoires
    if (empty($prenom) || empty($nom) || empty($age) || empty($sexe) || empty($naissance) || empty($pays) || empty($email) || empty($_POST['password']) || empty($name_user)) {
        $message = "<div class='error-message'>Erreur : Veuillez remplir tous les champs obligatoires.</div>";
    } else {
        // 2. Vérification de l'unicité de l'email
        $stmt_check = $db->prepare("SELECT id FROM Utilisateur WHERE email = :email");
        $stmt_check->execute([':email' => $email]);
        
        if ($stmt_check->fetch()) {
            $message = "<div class='error-message'>Erreur : Cet email est déjà utilisé. Veuillez en choisir un autre.</div>";
        } else {
            // C. Gestion de l'Avatar
            $avatar = "";
    // Cas 1 : Fichier uploadé
    if(isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['avatar_file']['tmp_name'];
        $name = basename($_FILES['avatar_file']['name']);
        $new_name = uniqid('avatar_') . '.' . pathinfo($name, PATHINFO_EXTENSION);
        $destination = 'avatars/' . $new_name;
        
        if(move_uploaded_file($tmp_name, $destination)) { $avatar = $destination; }
    } 
    // Cas 2 : URL externe
    else if(!empty($_POST['avatar_url'])) {
        $avatar = $_POST['avatar_url'];
    }

    // D. Liaison des paramètres
    $stmt->bindParam(':prenom',    $prenom);
    $stmt->bindParam(':nom',       $nom);
    $stmt->bindParam(':age',       $age, PDO::PARAM_INT);
    $stmt->bindParam(':sexe',      $sexe);
    $stmt->bindParam(':naissance', $naissance);
    $stmt->bindParam(':pays',      $pays);
    $stmt->bindParam(':avatar',    $avatar);
    $stmt->bindParam(':email',     $email);
    $stmt->bindParam(':password',  $password);
    $stmt->bindParam(':name_user', $name_user);

    // E. Exécution Transactionnelle (Utilisateur + Porte-monnaie)
    try {
        $db->beginTransaction(); // Début transaction

        // Insertion Utilisateur
        $stmt->execute();
        $user_id = $db->lastInsertId();

        // Récupération du solde initial choisi (Défaut : 10 000€)
        $initial_balance = isset($_POST['starting_balance']) ? floatval($_POST['starting_balance']) : 10000.0;
        
        // Création du Porte-monnaie associé
        $stmt_pm = $db->prepare("INSERT INTO Porte_monnaie (id_user, somme_actuelle, date_maj) VALUES (:uid, :solde, datetime('now'))");
        $stmt_pm->execute([':uid' => $user_id, ':solde' => $initial_balance]);

        $db->commit(); // Validation transaction
        
        $message = "<div class='success-message'>Inscription réussie ! Vous pouvez maintenant vous <a href='login.php'>connecter</a>.</div>";
    
    } catch (PDOException $e) {
        if($db->inTransaction()) { $db->rollBack(); } // Annulation si erreur
        $message = "<div class='error-message'>Erreur : " . $e->getMessage() . "</div>";
    }
   } // fermeture isset email 
  } // fermeture de la vérification des champs vides
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription — BoursoLine</title>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Outfit:wght@300;400;500&display=swap" rel="stylesheet">
    <link href="./css/inscription.css" rel="stylesheet">
    <script src="./js/theme.js"></script>
</head>
<body>
    <div class="card">
        <h1>Créer un <em>compte</em></h1>
        <?php if(!empty($message)) echo $message; ?>

        <form action="" method="post" enctype="multipart/form-data">
            <!-- Identité -->
            <div class="row">
                <div class="form-group">
                    <label for="prenom">Prénom</label>
                    <input type="text" name="prenom" id="prenom" placeholder="Prénom" required />
                </div>
                <div class="form-group">
                    <label for="nom">Nom</label>
                    <input type="text" name="nom" id="nom" placeholder="Nom" required />
                </div>
            </div>

            <!-- Age & Sexe -->
            <div class="row">
                <div class="form-group">
                    <label for="age">Âge</label>
                    <input type="number" name="age" id="age" min="0" max="150" placeholder="Age" required />
                </div>
                <div class="form-group">
                    <label>Sexe</label>
                    <div class="radio-group">
                        <label class="radio-label" for="sexe_f"><input type="radio" name="sexe" id="sexe_f" value="F" required /> F</label>
                        <label class="radio-label" for="sexe_h"><input type="radio" name="sexe" id="sexe_h" value="H" required /> H</label>
                    </div>
                </div>
            </div>
            
            <!-- Détails Perso -->
            <div class="form-group">
                <label for="naissance">Date de naissance</label>
                <input type="date" name="naissance" id="naissance" required />
            </div>
            <div class="form-group">
                <label for="pays">Pays</label>
                <input type="text" name="pays" id="pays" placeholder="Pays" required />
            </div>

            <!-- Avatar -->
            <fieldset>
                <legend>Avatar (Optionnel)</legend>
                <input type="file" name="avatar_file" id="avatar_file" accept="image/*" />
                <div class="or-text">ou via URL</div>
                <input type="url" name="avatar_url" id="avatar_url" placeholder="https://..." />
            </fieldset>

            <!-- Configuration Financière -->
            <div class="form-group">
                <label for="starting_balance">Solde de départ (€)</label>
                <input type="number" name="starting_balance" id="starting_balance" min="100" step="100" value="10000" required placeholder="10000" />
                <small style="color:#666; font-size:0.85em; display:block; margin-top:5px;">Montant virtuel pour commencer à investir.</small>
            </div>
            
            <!-- Connexion -->
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" placeholder="Email" required />
            </div>
            <div class="form-group">
                <label for="name_user">Nom d'utilisateur</label>
                <input type="text" name="name_user" id="name_user" placeholder="Pseudo" required />
            </div>
            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" name="password" id="password" placeholder="Mot de passe" required />
            </div>
            
            <button type="submit" name="valider" class="btn">S'inscrire</button>
            <div class="divider"></div>
            <div class="signup-link">Déjà un compte ? <a href="login.php">Connexion</a></div>
        </form>
    </div>
</body>
</html>
