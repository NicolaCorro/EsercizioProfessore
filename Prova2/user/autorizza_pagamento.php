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
        FROM transazione t
        JOIN utente u ON t.id_esercente = u.id_utente
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
        
        // Verifica stato transazione
        if ($transazione['stato'] != 'IN_ATTESA') {
            $error = 'Questa transazione è già stata processata (stato: ' . $transazione['stato'] . ')';
        }
    }
}

// Recupera saldo utente corrente
$stmt = $conn->prepare("SELECT saldo FROM conto WHERE id_utente = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$conto = $result->fetch_assoc();
$saldo_disponibile = $conto ? $conto['saldo'] : 0;

$success = '';
$error_auth = '';

// Gestione autorizzazione/rifiuto
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $transazione) {
    $azione = $_POST['azione'] ?? '';
    
    if ($azione == 'autorizza') {
        // Verifica saldo sufficiente
        if ($saldo_disponibile < $transazione['importo']) {
            $error_auth = 'Saldo insufficiente. Disponibile: ' . formatAmount($saldo_disponibile);
        } else {
            // Aggiorna transazione con id_cliente e data autorizzazione
            $stmt = $conn->prepare("
                UPDATE transazione 
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
                    // Transazione completata con successo
                    $success = 'Pagamento autorizzato ed eseguito con successo!';
                    
                    // Invia risposta all'applicazione chiamante
                    $response_data = [
                        'codice_transazione' => $codice_transazione,
                        'id_transazione_esterna' => $transazione['id_transazione_esterna'],
                        'esito' => 'OK',
                        'importo' => $transazione['importo'],
                        'data_completamento' => date('Y-m-d H:i:s')
                    ];
                    
                    // Invia risposta (in background, non blocchiamo l'utente)
                    sendAPIResponse($transazione['url_risposta'], $response_data);
                    
                    // Aggiorna i dati della transazione per la visualizzazione
                    $transazione['stato'] = 'COMPLETATA';
                    $saldo_disponibile -= $transazione['importo'];
                    
                } else {
                    // Errore durante l'esecuzione
                    $error_auth = 'Errore durante l\'esecuzione del pagamento. Riprova.';
                    
                    // Ripristina stato
                    $stmt = $conn->prepare("
                        UPDATE transazione 
                        SET stato = 'IN_ATTESA', id_cliente = NULL, data_autorizzazione = NULL 
                        WHERE id_transazione = ?
                    ");
                    $stmt->bind_param("i", $transazione['id_transazione']);
                    $stmt->execute();
                }
            } else {
                $error_auth = 'Transazione già processata o errore di sistema';
            }
        }
        
    } elseif ($azione == 'rifiuta') {
        // Rifiuta la transazione
        $stmt = $conn->prepare("
            UPDATE transazione 
            SET stato = 'RIFIUTATA', note = 'Rifiutato dall\'utente' 
            WHERE id_transazione = ? AND stato = 'IN_ATTESA'
        ");
        $stmt->bind_param("i", $transazione['id_transazione']);
        
        if ($stmt->execute()) {
            // Invia risposta all'applicazione chiamante
            $response_data = [
                'codice_transazione' => $codice_transazione,
                'id_transazione_esterna' => $transazione['id_transazione_esterna'],
                'esito' => 'KO',
                'motivo' => 'Pagamento rifiutato dall\'utente'
            ];
            
            sendAPIResponse($transazione['url_risposta'], $response_data);
            
            $success = 'Pagamento rifiutato. L\'esercente è stato informato.';
            $transazione['stato'] = 'RIFIUTATA';
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        
        .header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .logo {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }
        
        h1 {
            color: #10b981;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        
        .user-info {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .user-info p {
            margin: 0.25rem 0;
            color: #666;
        }
        
        .user-info strong {
            color: #333;
        }
        
        .transaction-details {
            background: #f0fdf4;
            border: 2px solid #10b981;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .transaction-details h2 {
            color: #10b981;
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #d1fae5;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 600;
            color: #666;
        }
        
        .detail-value {
            color: #333;
            font-weight: 500;
            text-align: right;
        }
        
        .amount {
            font-size: 2rem;
            color: #10b981;
            font-weight: bold;
        }
        
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: #92400e;
        }
        
        .error {
            background: #fee;
            border-left: 4px solid #c33;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: #c33;
        }
        
        .success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: #065f46;
        }
        
        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .btn {
            padding: 1rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-authorize {
            background: #10b981;
            color: white;
        }
        
        .btn-authorize:hover {
            background: #059669;
        }
        
        .btn-refuse {
            background: #ef4444;
            color: white;
        }
        
        .btn-refuse:hover {
            background: #dc2626;
        }
        
        .btn-back {
            background: #6b7280;
            color: white;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-back:hover {
            background: #4b5563;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .status-completata {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-rifiutata {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-attesa {
            background: #fef3c7;
            color: #92400e;
        }
        
        .back-link {
            text-align: center;
            margin-top: 2rem;
        }
        
        .back-link a {
            color: #10b981;
            text-decoration: none;
            font-weight: 600;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">💳</div>
            <h1>Richiesta di Pagamento</h1>
            <p style="color: #666;">Pay Steam - Sistema di Pagamento Sicuro</p>
        </div>
        
        <div class="user-info">
            <p><strong>Utente:</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
            <p><strong>Saldo disponibile:</strong> <?php echo formatAmount($saldo_disponibile); ?></p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error">
                <strong>⚠️ Errore:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
            <div class="back-link">
                <a href="index.php">← Torna alla dashboard</a>
            </div>
        <?php elseif ($success): ?>
            <div class="success">
                <strong>✅ Operazione completata!</strong><br>
                <?php echo htmlspecialchars($success); ?>
            </div>
            
            <?php if ($transazione['stato'] == 'COMPLETATA'): ?>
                <div class="transaction-details">
                    <h2>Riepilogo Pagamento</h2>
                    <div class="detail-row">
                        <span class="detail-label">Stato:</span>
                        <span class="status-badge status-completata">COMPLETATA</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Esercente:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($transazione['esercente_nome'] . ' ' . $transazione['esercente_cognome']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Importo pagato:</span>
                        <span class="detail-value amount"><?php echo formatAmount($transazione['importo']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Nuovo saldo:</span>
                        <span class="detail-value" style="color: #10b981; font-weight: bold;"><?php echo formatAmount($saldo_disponibile); ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="back-link">
                <a href="index.php">← Torna alla dashboard</a>
            </div>
        <?php else: ?>
            <?php if ($error_auth): ?>
                <div class="error">
                    <strong>⚠️ Errore:</strong> <?php echo htmlspecialchars($error_auth); ?>
                </div>
            <?php endif; ?>
            
            <div class="transaction-details">
                <h2>Dettagli Pagamento</h2>
                
                <div class="detail-row">
                    <span class="detail-label">Codice Transazione:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($transazione['codice_transazione']); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Esercente:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($transazione['esercente_nome'] . ' ' . $transazione['esercente_cognome']); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Email Esercente:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($transazione['esercente_email']); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Descrizione:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($transazione['descrizione']); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Data richiesta:</span>
                    <span class="detail-value"><?php echo date('d/m/Y H:i', strtotime($transazione['data_richiesta'])); ?></span>
                </div>
                
                <div class="detail-row" style="border-top: 2px solid #10b981; margin-top: 1rem; padding-top: 1rem;">
                    <span class="detail-label" style="font-size: 1.2rem;">IMPORTO DA PAGARE:</span>
                    <span class="detail-value amount"><?php echo formatAmount($transazione['importo']); ?></span>
                </div>
                
                <?php if ($saldo_disponibile < $transazione['importo']): ?>
                    <div class="detail-row" style="background: #fee; padding: 0.75rem; margin-top: 1rem; border-radius: 5px;">
                        <span class="detail-label" style="color: #c33;">Saldo insufficiente</span>
                        <span class="detail-value" style="color: #c33;">Mancano: <?php echo formatAmount($transazione['importo'] - $saldo_disponibile); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($saldo_disponibile < $transazione['importo']): ?>
                <div class="warning">
                    <strong>⚠️ Attenzione:</strong> Il tuo saldo non è sufficiente per completare questo pagamento. 
                    Ricarica il tuo conto o rifiuta la transazione.
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="authForm">
                <div class="actions">
                    <button type="submit" name="azione" value="autorizza" class="btn btn-authorize" 
                            <?php echo ($saldo_disponibile < $transazione['importo']) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>
                            onclick="return confirm('Confermi di voler autorizzare questo pagamento di <?php echo formatAmount($transazione['importo']); ?>?');">
                        ✓ Autorizza Pagamento
                    </button>
                    
                    <button type="submit" name="azione" value="rifiuta" class="btn btn-refuse"
                            onclick="return confirm('Sei sicuro di voler rifiutare questo pagamento?');">
                        ✗ Rifiuta Pagamento
                    </button>
                </div>
            </form>
            
            <div class="back-link">
                <a href="index.php">← Torna alla dashboard</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>