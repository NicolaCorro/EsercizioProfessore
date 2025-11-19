<?php
require_once '../config.php';

// Verifica che l'utente sia loggato e sia un UTENTE (non esercente)
requireLogin();

if ($_SESSION['user_profile'] != 'UTENTE') {
    header('Location: ../index.php');
    exit();
}

$conn = getDBConnection();

// Recupera codice transazione dall'URL
$codice_transazione = $_GET['codice'] ?? '';

if (empty($codice_transazione)) {
    $error = 'Codice transazione non specificato';
    $transazione = null;
} else {
    // Recupera dettagli transazione
    $stmt = $conn->prepare("
        SELECT t.*, 
               u.nome as esercente_nome, 
               u.cognome as esercente_cognome,
               u.email as esercente_email
        FROM transazioni t
        JOIN utenti u ON t.id_esercente = u.id_utente
        WHERE t.codice_transazione = ?
    ");
    $stmt->bind_param("s", $codice_transazione);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $error = 'Transazione non trovata';
        $transazione = null;
    } else {
        $transazione = $result->fetch_assoc();
    }
}

// Recupera saldo utente corrente
$stmt = $conn->prepare("SELECT saldo FROM conti WHERE id_utente = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$conto = $result->fetch_assoc();
$saldo_disponibile = $conto ? $conto['saldo'] : 0;

$error_auth = '';
$redirect_now = false;
$redirect_url = '';

// LOGICA DI GESTIONE STATO TRANSAZIONE
if ($transazione) {
    // CASO 1: Transazione già completata in precedenza
    // Se l'utente ricarica la pagina o torna indietro, lo rimandiamo subito all'esercente
    if ($transazione['stato'] == 'COMPLETATA') {
        $redirect_url = $transazione['url_chiamante'] . '/user/pagamento_completato.php?' . 
                       'esito=successo&' .
                       'codice=' . urlencode($transazione['id_transazione_esterna']);
        header("Location: " . $redirect_url);
        exit();
    }
    // CASO 2: Transazione rifiutata
    elseif ($transazione['stato'] == 'RIFIUTATA') {
        $error = 'Questa transazione è stata annullata.';
    }
    // CASO 3: Transazione in attesa -> Gestiamo il POST di conferma
    elseif ($transazione['stato'] == 'IN_ATTESA' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $azione = $_POST['azione'] ?? '';
        
        if ($azione == 'autorizza') {
            // Verifica saldo sufficiente
            if ($saldo_disponibile < $transazione['importo']) {
                $error_auth = 'Saldo insufficiente. Disponibile: ' . formatAmount($saldo_disponibile);
            } else {
                // Aggiorna transazione con id_cliente e data autorizzazione
                $stmt = $conn->prepare("
                    UPDATE transazioni 
                    SET id_cliente = ?, stato = 'AUTORIZZATA', data_autorizzazione = NOW() 
                    WHERE id_transazione = ? AND stato = 'IN_ATTESA'
                ");
                $stmt->bind_param("ii", $_SESSION['user_id'], $transazione['id_transazione']);
                
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    // Esegui la transazione (trasferimento fondi)
                    $esito = executeTransaction(
                        $conn,
                        $transazione['id_transazione'],
                        $_SESSION['user_id'],
                        $transazione['id_esercente'],
                        $transazione['importo']
                    );
                    
                    if ($esito) {
                        // Notifica API (in background)
                        $response_data = [
                            'codice_transazione' => $codice_transazione,
                            'id_transazione_esterna' => $transazione['id_transazione_esterna'],
                            'esito' => 'OK',
                            'importo' => $transazione['importo'],
                            'data_completamento' => date('Y-m-d H:i:s')
                        ];
                        sendAPIResponse($transazione['url_risposta'], $response_data);
                        
                        // Costruisci l'URL di ritorno
                        $redirect_url = $transazione['url_chiamante'] . '/user/pagamento_completato.php?' . 
                                       'esito=successo&' .
                                       'codice=' . urlencode($transazione['id_transazione_esterna']);
                        
                        // REDIRECT IMMEDIATO: Qui avviene la magia
                        // Non mostriamo HTML, ma inviamo l'header di reindirizzamento subito
                        header("Location: " . $redirect_url);
                        exit();
                        
                    } else {
                        $error_auth = 'Errore tecnico nel trasferimento fondi.';
                        // Rollback stato
                        $conn->query("UPDATE transazioni SET stato = 'IN_ATTESA' WHERE id_transazione = " . $transazione['id_transazione']);
                    }
                } else {
                    $error_auth = 'Errore: Transazione già elaborata da un\'altra richiesta.';
                }
            }
            
        } elseif ($azione == 'rifiuta') {
            $stmt = $conn->prepare("UPDATE transazioni SET stato = 'RIFIUTATA', note = 'Rifiuto Utente' WHERE id_transazione = ?");
            $stmt->bind_param("i", $transazione['id_transazione']);
            if ($stmt->execute()) {
                // Notifica rifiuto
                $response_data = [
                    'codice_transazione' => $codice_transazione,
                    'id_transazione_esterna' => $transazione['id_transazione_esterna'],
                    'esito' => 'KO',
                    'motivo' => 'Rifiutato dall\'utente'
                ];
                sendAPIResponse($transazione['url_risposta'], $response_data);
                
                // Anche in caso di rifiuto, torniamo subito indietro (opzionale, ma coerente)
                $redirect_url = $transazione['url_chiamante'] . '/user/pagamento_completato.php?' . 
                               'esito=annullato&' .
                               'codice=' . urlencode($transazione['id_transazione_esterna']);
                header("Location: " . $redirect_url);
                exit();
            }
        }
    }
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autorizza Pagamento - Pay Steam</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .header { text-align: center; margin-bottom: 2rem; border-bottom: 2px solid #f0f0f0; padding-bottom: 1rem; }
        .logo { font-size: 3rem; }
        h1 { color: #10b981; font-size: 1.5rem; }
        
        .user-info {
            background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 2rem;
        }
        .transaction-details {
            background: #f9fafb; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;
            border: 1px solid #eee;
        }
        .detail-row {
            display: flex; justify-content: space-between;
            padding: 0.5rem 0; border-bottom: 1px solid #e5e7eb;
        }
        .detail-row:last-child { border-bottom: none; }
        .amount { font-size: 1.5rem; color: #10b981; font-weight: bold; }
        
        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        button {
            padding: 1rem; border: none; border-radius: 8px; font-size: 1.1rem; font-weight: bold; cursor: pointer;
            transition: opacity 0.2s;
        }
        button:hover { opacity: 0.9; }
        .btn-pay { background: #10b981; color: white; }
        .btn-deny { background: #ef4444; color: white; }
        
        .error-msg { background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; text-align: center;}
        .back-link { display: block; text-align: center; margin-top: 1rem; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">💳</div>
            <h1>Richiesta di Pagamento</h1>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-msg">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <a href="index.php" class="back-link">Torna alla Dashboard</a>

        <?php else: ?>
            <div class="user-info">
                <p>Stai pagando con il conto di: <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong></p>
                <p>Saldo disponibile: <strong><?php echo formatAmount($saldo_disponibile); ?></strong></p>
            </div>

            <?php if ($error_auth): ?>
                <div class="error-msg">⚠️ <?php echo htmlspecialchars($error_auth); ?></div>
            <?php endif; ?>

            <div class="transaction-details">
                <div class="detail-row">
                    <span>Beneficiario:</span>
                    <strong><?php echo htmlspecialchars($transazione['esercente_nome']); ?></strong>
                </div>
                <div class="detail-row">
                    <span>Causale:</span>
                    <span><?php echo htmlspecialchars($transazione['descrizione']); ?></span>
                </div>
                <div class="detail-row" style="border-bottom: none; padding-top: 1rem;">
                    <span style="font-size: 1.2rem;">Totale da pagare:</span>
                    <span class="amount"><?php echo formatAmount($transazione['importo']); ?></span>
                </div>
            </div>

            <form method="POST">
                <div class="actions">
                    <button type="submit" name="azione" value="autorizza" class="btn-pay" 
                        onclick="return confirm('Confermi il pagamento di <?php echo formatAmount($transazione['importo']); ?>?');"
                        <?php echo ($saldo_disponibile < $transazione['importo']) ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
                        Paga Ora
                    </button>
                    <button type="submit" name="azione" value="rifiuta" class="btn-deny" formnovalidate
                        onclick="return confirm('Sei sicuro di voler annullare?');">
                        Annulla
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>