<?php
require_once '../config.php';

// Verifica login e profilo UTENTE
requireProfile('UTENTE');

$conn = getDBConnection();

// Recupera informazioni conto
$stmt = $conn->prepare("
    SELECT id_conto, saldo, data_creazione
    FROM conti
    WHERE id_utente = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$conto = $result->fetch_assoc();

// Recupera transazioni in attesa di autorizzazione
$stmt = $conn->prepare("
    SELECT t.*, u.nome as esercente_nome, u.cognome as esercente_cognome
    FROM transazioni t
    JOIN utenti u ON t.id_esercente = u.id_utente
    WHERE t.id_cliente = ? AND t.stato = 'AUTORIZZATA'
    ORDER BY t.data_richiesta DESC
    LIMIT 5
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$transazioni_attesa = $stmt->get_result();

// Recupera ultime transazioni completate
$stmt = $conn->prepare("
    SELECT t.*, u.nome as esercente_nome, u.cognome as esercente_cognome
    FROM transazioni t
    JOIN utenti u ON t.id_esercente = u.id_utente
    WHERE t.id_cliente = ? AND t.stato = 'COMPLETATA'
    ORDER BY t.data_completamento DESC
    LIMIT 10
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$transazioni_completate = $stmt->get_result();

// Recupera ultimi movimenti
$stmt = $conn->prepare("
    SELECT m.*, t.descrizione as trans_descrizione
    FROM movimenti m
    LEFT JOIN transazioni t ON m.id_transazione = t.id_transazione
    WHERE m.id_conto = ?
    ORDER BY m.data_movimento DESC
    LIMIT 5
");
$stmt->bind_param("i", $conto['id_conto']);
$stmt->execute();
$ultimi_movimenti = $stmt->get_result();

// Recupera carte di credito
$stmt = $conn->prepare("
    SELECT * FROM carte_credito
    WHERE id_utente = ?
    ORDER BY predefinita DESC, data_inserimento DESC
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$carte = $stmt->get_result();

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La Mia Dashboard - Pay Steam</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        header {
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            color: white;
            padding: 1.5rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        h1 {
            font-size: 1.8rem;
        }
        
        .user-menu {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .btn {
            padding: 0.5rem 1.5rem;
            border-radius: 5px;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }
        
        .btn-primary {
            background: white;
            color: #10b981;
            border: 2px solid white;
        }
        
        .btn-primary:hover {
            background: transparent;
            color: white;
        }
        
        .btn-secondary {
            background: transparent;
            color: white;
            border: 2px solid white;
        }
        
        .btn-secondary:hover {
            background: white;
            color: #10b981;
        }
        
        main {
            padding: 2rem 0;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card h2 {
            color: #10b981;
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }
        
        .balance-card {
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            color: white;
        }
        
        .balance-card h2 {
            color: white;
            opacity: 0.9;
        }
        
        .balance-amount {
            font-size: 3rem;
            font-weight: bold;
            margin: 1rem 0;
        }
        
        .balance-info {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .action-btn {
            padding: 0.75rem;
            background: white;
            color: #10b981;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            text-decoration: none;
            display: block;
        }
        
        .action-btn:hover {
            background: #f0f0f0;
        }
        
        .transaction-list {
            list-style: none;
        }
        
        .transaction-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .transaction-item:last-child {
            border-bottom: none;
        }
        
        .transaction-info {
            flex: 1;
        }
        
        .transaction-merchant {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .transaction-desc {
            font-size: 0.85rem;
            color: #666;
        }
        
        .transaction-date {
            font-size: 0.75rem;
            color: #999;
        }
        
        .transaction-amount {
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .amount-out {
            color: #ef4444;
        }
        
        .amount-in {
            color: #10b981;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-completata {
            background: #d1fae5;
            color: #065f46;
        }
        
        .card-item {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 0.75rem;
            border-left: 4px solid #10b981;
        }
        
        .card-number {
            font-family: monospace;
            font-size: 1.1rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .card-details {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #666;
        }
        
        .card-default {
            background: #d1fae5;
            border-left-color: #059669;
        }
        
        .link-btn {
            display: inline-block;
            margin-top: 1rem;
            color: #10b981;
            text-decoration: none;
            font-weight: 600;
        }
        
        .link-btn:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo-area">
                    <span style="font-size: 2rem;">💳</span>
                    <div>
                        <h1>Pay Steam</h1>
                        <p style="opacity: 0.9;">Benvenuto, <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    </div>
                </div>
                <div class="user-menu">
                    <a href="../index.php" class="btn btn-secondary">Home</a>
                    <a href="../logout.php" class="btn btn-primary">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="container">
            <div class="dashboard-grid">
                <!-- Saldo -->
                <div class="card balance-card">
                    <h2>Il Tuo Saldo</h2>
                    <div class="balance-amount"><?php echo formatAmount($conto['saldo']); ?></div>
                    <div class="balance-info">
                        Conto aperto il <?php echo date('d/m/Y', strtotime($conto['data_creazione'])); ?>
                    </div>
                    <div class="quick-actions">
                        <a href="movimenti.php" class="action-btn">📊 Movimenti</a>
                        <a href="carte.php" class="action-btn">💳 Le Mie Carte</a>
                    </div>
                </div>
                
                <!-- Carte di Credito -->
                <div class="card">
                    <h2>💳 Le Mie Carte</h2>
                    <?php if ($carte->num_rows > 0): ?>
                        <?php while ($carta = $carte->fetch_assoc()): ?>
                            <div class="card-item <?php echo $carta['predefinita'] ? 'card-default' : ''; ?>">
                                <div class="card-number">
                                    **** **** **** <?php echo substr($carta['numero_carta'], -4); ?>
                                </div>
                                <div class="card-details">
                                    <span><?php echo htmlspecialchars($carta['tipo_carta']); ?></span>
                                    <span><?php echo htmlspecialchars($carta['scadenza']); ?></span>
                                    <?php if ($carta['predefinita']): ?>
                                        <span style="color: #10b981; font-weight: 600;">✓ Predefinita</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        <a href="carte.php" class="link-btn">Gestisci carte →</a>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">💳</div>
                            <p>Nessuna carta salvata</p>
                            <a href="carte.php" class="link-btn">Aggiungi una carta →</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Ultime Transazioni -->
            <div class="card">
                <h2>📜 Ultime Transazioni</h2>
                <?php if ($transazioni_completate->num_rows > 0): ?>
                    <ul class="transaction-list">
                        <?php while ($trans = $transazioni_completate->fetch_assoc()): ?>
                            <li class="transaction-item">
                                <div class="transaction-info">
                                    <div class="transaction-merchant">
                                        <?php echo htmlspecialchars($trans['esercente_nome'] . ' ' . $trans['esercente_cognome']); ?>
                                    </div>
                                    <div class="transaction-desc">
                                        <?php echo htmlspecialchars($trans['descrizione']); ?>
                                    </div>
                                    <div class="transaction-date">
                                        <?php echo date('d/m/Y H:i', strtotime($trans['data_completamento'])); ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="transaction-amount amount-out">
                                        -<?php echo formatAmount($trans['importo']); ?>
                                    </div>
                                    <span class="status-badge status-completata">COMPLETATA</span>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                    <a href="movimenti.php" class="link-btn">Vedi tutti i movimenti →</a>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <p>Nessuna transazione ancora</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Ultimi Movimenti -->
            <div class="card">
                <h2>💸 Ultimi Movimenti</h2>
                <?php if ($ultimi_movimenti->num_rows > 0): ?>
                    <ul class="transaction-list">
                        <?php while ($mov = $ultimi_movimenti->fetch_assoc()): ?>
                            <li class="transaction-item">
                                <div class="transaction-info">
                                    <div class="transaction-merchant">
                                        <?php echo $mov['tipo'] == 'ENTRATA' ? '📥' : '📤'; ?>
                                        <?php echo htmlspecialchars($mov['causale']); ?>
                                    </div>
                                    <div class="transaction-date">
                                        <?php echo date('d/m/Y H:i', strtotime($mov['data_movimento'])); ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="transaction-amount <?php echo $mov['tipo'] == 'ENTRATA' ? 'amount-in' : 'amount-out'; ?>">
                                        <?php echo $mov['tipo'] == 'ENTRATA' ? '+' : '-'; ?><?php echo formatAmount($mov['importo']); ?>
                                    </div>
                                    <div style="text-align: right; font-size: 0.75rem; color: #999;">
                                        Saldo: <?php echo formatAmount($mov['saldo_nuovo']); ?>
                                    </div>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                    <a href="movimenti.php" class="link-btn">Vedi storico completo →</a>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">💸</div>
                        <p>Nessun movimento ancora</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>