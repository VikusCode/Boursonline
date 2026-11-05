<?php
/**
 * SCRIPT DE DÉCONNEXION
 * ---------------------
 * 1. Détruit toutes les données de session.
 * 2. Supprime le cookie de session côté client.
 * 3. Redirige vers la page d'accueil publique.
 */

session_start();

// 1. Réinitialisation du tableau de session
$_SESSION = array();

// 2. Suppression du cookie de session (S'il existe)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destruction finale de la session côté serveur
session_destroy();

// 4. Redirection vers l'accueil
header('Location: accueil.php');
exit();
?>