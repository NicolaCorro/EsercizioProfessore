<?php
require_once '../config.php';

// Verifica autenticazione
if (!isset($_SESSION['user_id']) || $_SESSION['user_profile'] != 'REGISTRATO') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Recupera parametri dall'URL
$esito = $_GET['esito'] ?? '';
$codice_prenotazione = $_GET['codice'] ?? '';

$conn = getDBConnection();

// Recupera i dettagli della prenotazione
$prenotazione = null;
$messaggio = '';
$tipo_messaggio = ''; // 'success' o 'error'

if (!empty($codice_prenotazione)) {
    $stmt = $conn->prepare("
        SELECT 
            p.id_prenotazione,
            p.codice_prenotazione,
            p.stato,
            p.data_prenotazione,
            t.numero_treno,
            t.data_partenza,
            sp.nome as stazione_partenza,
            sa.nome as stazione_arrivo,
            b.importo,
            b.stato_pagamento,
            b.codice_pagamento,
            po.numero_posto,
            m.sigla as materiale
        FROM PRENOTAZIONE p
        JOIN TRENO t ON p.id_treno = t.id_treno
        JOIN STAZIONE sp ON p.id_stazione_partenza = sp.id_stazione
        JOIN STAZIONE sa ON p.id_stazione_arrivo = sa.id_stazione
        LEFT JOIN BIGLIETTO b ON p.id_prenotazione = b.id_prenotazione
        LEFT JOIN POSTO po ON p.id_posto = po.id_posto
        LEFT JOIN MATERIALE_ROTABILE m ON po.id_materiale = m.id_materiale
        WHERE p.codice_prenotazione = ? AND p.id_utente = ?
    ");
    $stmt->bind_param("si", $codice_prenotazione, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $prenotazione = $result->fetch_assoc();
        
        // Determina il messaggio in base all'esito
        if ($esito === 'successo') {
            if ($prenotazione['stato'] === 'CONFERMATA' && $prenotazione['stato_pagamento'] === 'PAGATO') {
                $messaggio = 'Pagamento completato con successo! La tua prenotazione è confermata.';
                $tipo_messaggio = 'success';
            } else {
                $messaggio = 'Il pagamento è in elaborazione. Controlla lo stato della prenotazione tra qualche minuto.';
                $tipo_messaggio = 'warning';
            }
        } elseif ($esito === 'annullato') {
            if ($prenotazione['stato'] === 'ANNULLATA') {
                $messaggio = 'Pagamento annullato. La prenotazione è stata cancellata e il posto è di nuovo disponibile.';
                $tipo_messaggio = 'error';
            } else {
                $messaggio = 'Pagamento annullato.';
                $tipo_messaggio = 'error';
            }
        } else {
            $messaggio = 'Stato del pagamento sconosciuto. Verifica la tua prenotazione.';
            $tipo_messaggio = 'warning';
        }
    } else {
        $messaggio = 'Prenotazione non trovata.';
        $tipo_messaggio = 'error';
    }
} else {
    $messaggio = 'Codice prenotazione mancante.';
    $tipo_messaggio = 'error';
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esito Pagamento - SFT</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            max-width: 700px;
            width: 100%;
            background: white;
            padding: 2.5rem;
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
            color: #667eea;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        
        .subtitle {
            color: #666;
            font-size: 0.95rem;
        }
        
        .message-box {
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .message-box.success {
            background: #d1fae5;
            border: 2px solid #10b981;
            color: #065f46;
        }
        
        .message-box.error {
            background: #fee2e2;
            border: 2px solid #ef4444;
            color: #991b1b;
        }
        
        .message-box.warning {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            color: #92400e;
        }
        
        .message-box .icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .message-box h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .message-box p {
            font-size: 1.1rem;
            line-height: 1.6;
        }
        
        .details-box {
            background: #f9fafb;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        
        .details-box h3 {
            color: #667eea;
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e5e7eb;
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
        
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .status-badge.confermata {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-badge.annullata {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-badge.attesa {
            background: #fef3c7;
            color: #92400e;
        }
        
        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        
        .btn {
            padding: 0.8rem 2rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-secondary:hover {
            background: #667eea;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🚂</div>
            <h1>Società Ferrovie Turistiche</h1>
            <p class="subtitle">Esito Pagamento</p>
        </div>
        
        <?php if ($tipo_messaggio): ?>
        <div class="message-box <?php echo $tipo_messaggio; ?>">
            <div class="icon">
                <?php 
                if ($tipo_messaggio === 'success') echo '✅';
                elseif ($tipo_messaggio === 'error') echo '❌';
                else echo '⚠️';
                ?>
            </div>
            <h2>
                <?php 
                if ($tipo_messaggio === 'success') echo 'Prenotazione Confermata!';
                elseif ($tipo_messaggio === 'error') echo 'Pagamento Annullato';
                else echo 'Attenzione';
                ?>
            </h2>
            <p><?php echo htmlspecialchars($messaggio); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($prenotazione): ?>
        <div class="details-box">
            <h3>Dettagli Prenotazione</h3>
            
            <div class="detail-row">
                <span class="detail-label">Codice Prenotazione:</span>
                <span class="detail-value"><strong><?php echo htmlspecialchars($prenotazione['codice_prenotazione']); ?></strong></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Stato:</span>
                <span class="detail-value">
                    <span class="status-badge <?php echo strtolower($prenotazione['stato']); ?>">
                        <?php echo htmlspecialchars($prenotazione['stato']); ?>
                    </span>
                </span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Treno:</span>
                <span class="detail-value"><?php echo htmlspecialchars($prenotazione['numero_treno']); ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Data Partenza:</span>
                <span class="detail-value"><?php echo date('d/m/Y', strtotime($prenotazione['data_partenza'])); ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Da:</span>
                <span class="detail-value"><?php echo htmlspecialchars($prenotazione['stazione_partenza']); ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">A:</span>
                <span class="detail-value"><?php echo htmlspecialchars($prenotazione['stazione_arrivo']); ?></span>
            </div>
            
            <?php if ($prenotazione['materiale'] && $prenotazione['numero_posto']): ?>
            <div class="detail-row">
                <span class="detail-label">Posto:</span>
                <span class="detail-value">
                    Carrozza <?php echo htmlspecialchars($prenotazione['materiale']); ?> - 
                    Posto <?php echo htmlspecialchars($prenotazione['numero_posto']); ?>
                </span>
            </div>
            <?php endif; ?>
            
            <?php if ($prenotazione['importo']): ?>
            <div class="detail-row">
                <span class="detail-label">Importo:</span>
                <span class="detail-value"><strong>€ <?php echo number_format($prenotazione['importo'], 2, ',', '.'); ?></strong></span>
            </div>
            <?php endif; ?>
            
            <?php if ($prenotazione['stato_pagamento']): ?>
            <div class="detail-row">
                <span class="detail-label">Stato Pagamento:</span>
                <span class="detail-value">
                    <span class="status-badge <?php echo $prenotazione['stato_pagamento'] === 'PAGATO' ? 'confermata' : 'attesa'; ?>">
                        <?php echo htmlspecialchars($prenotazione['stato_pagamento']); ?>
                    </span>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="actions">
            <a href="index.php" class="btn btn-primary">Torna alla Dashboard</a>
            <?php if ($prenotazione && $prenotazione['stato'] === 'CONFERMATA'): ?>
            <a href="prenotazioni.php" class="btn btn-secondary">Vedi Prenotazioni</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>