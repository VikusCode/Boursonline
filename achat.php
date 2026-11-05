<?php
    /**
     * TRAITEMENT DES ACHATS D'ACTIONS
     * -------------------------------
     * Ce script reçoit les données d'achat via une requête POST JSON.
     * Il effectue les opérations suivantes de manière transactionnelle :
     * 1. Vérification du solde utilisateur.
     * 2. Débit du montant total (Prix x Quantité).
     * 3. Mise à jour du portefeuille d'actions (Ajout ou update PRU).
     * 4. Enregistrement de la transaction dans l'historique.
     */
    session_start();
    require_once 'database.php';
    require_once 'api.php';

    // Vérification de la session
    if(empty($_SESSION['user_id'])){
        echo json_encode(['success'=>false,'message'=>'Non connecté']);
        exit();
    }

    // Récupération et décodage du JSON envoyé par le client (JS)
    $data=json_decode(file_get_contents('php://input'),true);
    $id_user=$_SESSION['user_id'];
    $symbol=$data['symbol'];
    $quantite=(int)$data['quantite'];
    $quoteData = getApiQuote($symbol);
    $prix = (float)$quoteData['Global Quote']['05. price'];
    $total=$prix*$quantite; // Montant total de la transaction

    try{
        // 1. Récupération du solde actuel de l'utilisateur
        $stmt=$db->prepare("SELECT somme_actuelle FROM Porte_monnaie WHERE id_user=:id");
        $stmt->execute([':id'=>$id_user]);
        $pm=$stmt->fetch();

        // Vérification de la solvabilité
        if(!$pm||$pm['somme_actuelle']<$total){
            echo json_encode(['success'=>false,'message'=>'Budget insuffisant']);
            exit();
        }

        // Calcul du nouveau solde
        $nouveau_budget=$pm['somme_actuelle']-$total;

        // 2. Mise à jour du solde en base de données
        $stmt=$db->prepare("UPDATE Porte_monnaie SET somme_actuelle=:budget WHERE id_user=:id");
        $stmt->execute([':budget'=>$nouveau_budget,':id'=>$id_user]);

        // 3. Gestion du portefeuille d'actions
        // On vérifie si l'utilisateur possède déjà cette action
        $stmt=$db->prepare("SELECT * FROM Actions WHERE id_user=:id AND nom=:nom");
        $stmt->execute([':id'=>$id_user,':nom'=>$symbol]);
        $action_existante=$stmt->fetch();

        if($action_existante){
            // CAS 1 : L'action est déjà possédée. On augmente la quantité et on recalcule le PRU.
            // PRU (Prix de Revient Unitaire) = (Ancien_Total + Nouveau_Total) / Nouvelle_Quantité
            $nouvelle_qte=$action_existante['quantité']+$quantite;
            $nouveau_pru=(($action_existante['coût']*$action_existante['quantité'])+$total)/$nouvelle_qte;

            $stmt=$db->prepare("UPDATE Actions SET quantité=:qte, coût=:pru, val_actuelle=:val, date_achat=:date WHERE id_user=:id AND nom=:nom");
            $stmt->execute([
                ':qte'=>$nouvelle_qte,
                ':pru'=>$nouveau_pru,
                ':val'=>$prix,                  // Le dernier prix connu est mis à jour
                ':date'=>date('Y-m-d H:i:s'),   // Mise à jour de la date d'achat (dernier mouvement)
                ':id'=>$id_user,
                ':nom'=>$symbol
            ]);
        }else{
            // CAS 2 : Première acquisition de cette action. Insertion simple.
            $stmt=$db->prepare("INSERT INTO Actions (id_user,nom,quantité,coût,val_actuelle,date_achat) VALUES (:id,:nom,:qte,:pru,:val,:date)");
            $stmt->execute([
                ':id'=>$id_user,
                ':nom'=>$symbol,
                ':qte'=>$quantite,
                ':pru'=>$prix, // PRU initial = Prix d'achat
                ':val'=>$prix,
                ':date'=>date('Y-m-d H:i:s')
            ]);
        }

        // 4. Enregistrement transactionnel (Historique)
        // Permet de garder une trace pour le graphique d'évolution et l'audit.
        $stmt=$db->prepare("INSERT INTO Transactions (id_user,nom_action,type_transac,quantité,total,date_transac) VALUES (:id,:nom,'Purchase',:qte,:total,datetime('now'))");
        $stmt->execute([
            ':id'=>$id_user,
            ':nom'=>$symbol,
            ':qte'=>$quantite,
            ':total'=>$total
        ]);

        // Réponse succès au client avec le nouveau solde formaté pour affichage
        echo json_encode(['success'=>true,'nouveau_budget'=>number_format($nouveau_budget,2,',',' ')]);

    }catch(PDOException $e){
        // En cas d'erreur SQL, on renvoie le message d'erreur
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
?>