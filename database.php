<?php
/**
 * CONFIGURATION DE LA BASE DE DONNÉES (SQLite)
 * --------------------------------------------
 * Ce fichier initialise la connexion PDO et crée la structure des tables si nécessaire.
 * Le choix de SQLite permet une portabilité maximale sans serveur SGBD tiers.
 */

try{
    // Connexion à la base de données (fichier local 'database.db')
    $db = new PDO("sqlite:database.db");
    // Activation des exceptions pour la gestion des erreurs SQL
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Définition du schéma relationnel
    // Utilisateur : Comptes clients
    // Porte_monnaie : Solde courant du client
    // Actions : Portefeuille d'actifs détenus par l'utilisateur
    // Transactions : Historique des mouvements (Achats/Ventes)
    // Historique_Portfolio : Snapshots de la valeur globale (pour évolution future)
    // Stock : Cache des cotations boursières (évite les appels API redondants)
    // Préférences_fenêtres : Configuration UI persistante
    $base = "
    CREATE TABLE IF NOT EXISTS Utilisateur (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        prenom TEXT,
        nom TEXT,
        age INTEGER,
        sexe TEXT CHECK(sexe IN('H','F')),
        date_naissance DATE,
        pays TEXT,
        avatar_url TEXT,
        email TEXT,
        password TEXT,
        name_user TEXT
    );

    CREATE TABLE IF NOT EXISTS Porte_monnaie (
        id_pm INTEGER PRIMARY KEY AUTOINCREMENT,
        id_user INTEGER,
        plus_value FLOAT DEFAULT 0,
        date_maj DATE,
        somme_actuelle FLOAT DEFAULT 10000,
        FOREIGN KEY(id_user) REFERENCES Utilisateur(id)
    );

    CREATE TABLE IF NOT EXISTS Actions (
        id_action INTEGER PRIMARY KEY AUTOINCREMENT,
        id_user INTEGER,
        coût FLOAT,           -- Prix de revient unitaire (PRU)
        val_actuelle FLOAT,   -- Dernier cours connu (mis à jour lors des transactions/actualisations)
        quantité INTEGER,
        date_achat DATE,
        nom TEXT,             -- Symbole de l'action
        FOREIGN KEY(id_user) REFERENCES Utilisateur(id)
    );

    CREATE TABLE IF NOT EXISTS Transactions (
        id_transac INTEGER PRIMARY KEY AUTOINCREMENT,
        id_user INTEGER,
        type_transac TEXT CHECK(type_transac IN ('Sell', 'Purchase')),
        quantité INTEGER,
        prix FLOAT,           -- Prix unitaire à l'instant T
        total FLOAT,          -- Montant total de l'opération
        date_transac DATE,
        nom_action TEXT,
        FOREIGN KEY(id_user) REFERENCES Utilisateur(id)
    );

    CREATE TABLE IF NOT EXISTS Historique_Portfolio (
        id_hist INTEGER PRIMARY KEY AUTOINCREMENT,
        id_user INTEGER,
        date_heure DATETIME DEFAULT CURRENT_TIMESTAMP,
        valeur_totale FLOAT,
        FOREIGN KEY(id_user) REFERENCES Utilisateur(id)
    );

    CREATE TABLE IF NOT EXISTS Stock (
        id_act INTEGER PRIMARY KEY AUTOINCREMENT,
        symbol TEXT,
        nom TEXT,
        price FLOAT DEFAULT 0,
        change_percent TEXT DEFAULT '0%',
        volume INTEGER DEFAULT 0,
        coût FLOAT DEFAULT 0,         -- Champ legacy, conservé pour compatibilité
        quantité INTEGER DEFAULT 0,   -- Champ legacy, conservé pour compatibilité
        history_json TEXT             -- Cache JSON des données historiques (Graphiques)
    );

    CREATE TABLE IF NOT EXISTS Préférences_fenêtres (
        id_pref INTEGER PRIMARY KEY AUTOINCREMENT,
        pos_x INTEGER,
        pos_y INTEGER,
        hauteur INTEGER,
        largeur INTEGER
    );
    ";

    // Exécution de la création des tables (idempotent grâce à IF NOT EXISTS)
    $db->exec($base);

}catch(PDOException $e){
    die("Erreur critique de base de données : " . $e->getMessage());
}
?>