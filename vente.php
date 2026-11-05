<?php
    /**
     * TRAITEMENT DES VENTES D'ACTIONS
     * -------------------------------
     * Ce script reçoit les données de vente via une requête POST JSON.
     * Il assure la cohérence des opérations :
     * 1. Vérifie la possession des actions.
     * 2. Met à jour ou supprime la ligne d'action du portefeuille.
     * 3. Crédite le solde du montant de la vente.
     * 4. Enregistre la transaction.
     */
    session_start();
    require_once 'database.php';
    require_once 'api.php';

    // Vérification de la session
    if(empty($_SESSION['user_id'])){
        echo json_encode(['success'=>false,'message'=>'Non connecté']);
        exit();
    }

    // Lecture de l'input JSON
    $data=json_decode(file_get_contents('php://input'),true);
    $id_user=$_SESSION['user_id'];
    $symbol=$data['symbol'];
    $quantite=(int)$data['quantite'];
    $quoteData = getApiQuote($symbol);
    $prix = (float)$quoteData['Global Quote']['05. price'];
    $total=$prix*$quantite; // Valeur totale brute de la vente

    try{
        // 1. Vérification de la possession (Portefeuille Actions)
        $stmt=$db->prepare("SELECT * FROM Actions WHERE id_user=:id AND nom=:nom");
        $stmt->execute([':id'=>$id_user,':nom'=>$symbol]);
        $action=$stmt->fetch();

        if(!$action){
            echo json_encode(['success'=>false,'message'=>"Vous ne possédez pas cette action."]);
            exit();
        }

        if($action['quantité']<$quantite){
            echo json_encode(['success'=>false,'message'=>"Quantité insuffisante (possession: {$action['quantité']})."]);
            exit();
        }

        // 2. Mise à jour du stock d'actions de l'utilisateur
        $nouvelle_qte=$action['quantité']-$quantite;

        if($nouvelle_qte===0){
            // CAS 1 : Vente totale. On retire l'action de la liste des possessions.
            $stmt=$db->prepare("DELETE FROM Actions WHERE id_user=:id AND nom=:nom");
            $stmt->execute([':id'=>$id_user,':nom'=>$symbol]);
        }else{
            // CAS 2 : Vente partielle. On réduit la quantité.
            // On ne change pas le PRU (Prix de Revient Unitaire) lors d'une vente (FIFO/LIFO non géré ici, PRU moyen pondéré invariant à la vente).
            // On met à jour seulement la 'val_actuelle' pour refléter le dernier prix de marché connu.
            $stmt=$db->prepare("UPDATE Actions SET quantité=:qte, val_actuelle=:val WHERE id_user=:id AND nom=:nom");
            $stmt->execute([':qte'=>$nouvelle_qte,':val'=>$prix,':id'=>$id_user,':nom'=>$symbol]);
        }

        // 3. Crédit du solde en espèce (Cash)
        // On récupère d'abord l'ancien solde
        $stmt=$db->prepare("SELECT somme_actuelle FROM Porte_monnaie WHERE id_user=:id");
        $stmt->execute([':id'=>$id_user]);
        $pm=$stmt->fetch();

        $nouveau_budget=$pm['somme_actuelle']+$total;

        $stmt=$db->prepare("UPDATE Porte_monnaie SET somme_actuelle=:budget WHERE id_user=:id");
        $stmt->execute([':budget'=>$nouveau_budget,':id'=>$id_user]);

        // 4. Enregistrement transactionnel
        // Type 'Sell' pour vente
        $stmt=$db->prepare("INSERT INTO Transactions (id_user,nom_action,type_transac,quantité,total,date_transac) 
                            VALUES (:id,:nom,'Sell',:qte,:total,datetime('now'))");
        $stmt->execute([
            ':id'=>$id_user,
            ':nom'=>$symbol,
            ':qte'=>$quantite,
            ':total'=>$total
        ]);

        // Succès et retour du nouveau solde
        echo json_encode(['success'=>true,'nouveau_budget'=>number_format($nouveau_budget,2,',',' ')]);

    }catch(PDOException $e){
        // Gestion des erreurs BDD
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
?>