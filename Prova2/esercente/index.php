<?php
require_once '../config.php';

// Verifica login e profilo ESERCENTE
requireProfile('ESERCENTE');

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

// Recupera ultime transazioni ricevute (completate)
$stmt = $conn->prepare("
    SELECT t.*, u.nome as cliente_nome, u.cognome as cliente_cognome
    FROM transazioni t
    LEFT JOIN utenti u ON t.id_cliente = u.id_utente
    WHERE t.id_esercente = ? AND t.stato = 'COMPLETATA'
    ORDER BY t.data_completamento DESC
    LIMIT 10
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$transazioni_ricevute = $stmt->get_result();

// Recupera ultime transazioni IN_ATTESA (pagamenti richiesti ma non ancora autorizzati)
$stmt = $conn->prepare("
    SELECT t.*, u.nome as cliente_nome, u.cognome as cliente_cognome
    FROM transazioni t
    LEFT JOIN utenti u ON t.id_cliente = u.id_utente
    WHERE t.id_esercente = ? AND t.stato = 'IN_ATTESA'
    ORDER BY t.data_richiesta DESC
    LIMIT 5
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$transazioni_attesa = $stmt->get_result();

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

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Esercente - Pay Steam</title>
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
        
        .status-attesa {
            background: #fef3c7;
            color: #92400e;
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
        
        .badge-esercente {
            background: white;
            color: #10b981;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo-area">
                    <span style="font-size: 2rem;">🏪</span>
                    <div>
                        <h1>Pay Steam <span class="badge-esercente">ESERCENTE</span></h1>
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
                    <h2>Il Tuo Saldo Commerciale</h2>
                    <div class="balance-amount"><?php echo formatAmount($conto['saldo']); ?></div>
                    <div class="balance-info">
                        Conto aperto il <?php echo date('d/m/Y', strtotime($conto['data_creazione'])); ?>
                    </div>
                    <div class="quick-actions">
                        <a href="movimenti.php" class="action-btn">📊 Movimenti</a>
                        <a href="effettua_pagamento.php" class="action-btn">💸 Paga</a>
                    </div>
                </div>
                
                <!-- Riepilogo veloce -->
                <div class="card">
                    <h2>📈 Riepilogo Rapido</h2>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <div style="padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                            <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.5rem;">Transazioni Completate</div>
                            <div style="font-size: 1.5rem; font-weight: bold; color: #10b981;">
                                <?php echo $transazioni_ricevute->num_rows; ?>
                            </div>
                        </div>
                        <div style="padding: 1rem; background: #fef3c7; border-radius: 8px;">
                            <div style="font-size: 0.85rem; color: #92400e; margin-bottom: 0.5rem;">In Attesa</div>
                            <div style="font-size: 1.5rem; font-weight: bold; color: #92400e;">
                                <?php echo $transazioni_attesa->num_rows; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($transazioni_attesa->num_rows > 0): ?>
            <!-- Transazioni in attesa -->
            <div class="card" style="margin-bottom: 2rem;">
                <h2>⏳ Pagamenti in Attesa di Autorizzazione</h2>
                <ul class="transaction-list">
                    <?php while ($trans = $transazioni_attesa->fetch_assoc()): ?>
                        <li class="transaction-item">
                            <div class="transaction-info">
                                <div class="transaction-merchant">
                                    <?php 
                                    if ($trans['cliente_nome']) {
                                        echo htmlspecialchars($trans['cliente_nome'] . ' ' . $trans['cliente_cognome']);
                                    } else {
                                        echo "Cliente in attesa di login";
                                    }
                                    ?>
                                </div>
                                <div class="transaction-desc">
                                    <?php echo htmlspecialchars($trans['descrizione']); ?>
                                </div>
                                <div class="transaction-date">
                                    Richiesto il <?php echo date('d/m/Y H:i', strtotime($trans['data_richiesta'])); ?>
                                </div>
                            </div>
                            <div>
                                <div class="transaction-amount amount-in">
                                    +<?php echo formatAmount($trans['importo']); ?>
                                </div>
                                <span class="status-badge status-attesa">IN ATTESA</span>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Ultimi Incassi -->
            <div class="card">
                <h2>💰 Ultimi Incassi</h2>
                <?php if ($transazioni_ricevute->num_rows > 0): ?>
                    <ul class="transaction-list">
                        <?php while ($trans = $transazioni_ricevute->fetch_assoc()): ?>
                            <li class="transaction-item">
                                <div class="transaction-info">
                                    <div class="transaction-merchant">
                                        <?php 
                                        if ($trans['cliente_nome']) {
                                            echo htmlspecialchars($trans['cliente_nome'] . ' ' . $trans['cliente_cognome']);
                                        } else {
                                            echo "Cliente";
                                        }
                                        ?>
                                    </div>
                                    <div class="transaction-desc">
                                        <?php echo htmlspecialchars($trans['descrizione']); ?>
                                    </div>
                                    <div class="transaction-date">
                                        <?php echo date('d/m/Y H:i', strtotime($trans['data_completamento'])); ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="transaction-amount amount-in">
                                        +<?php echo formatAmount($trans['importo']); ?>
                                    </div>
                                    <span class="status-badge status-completata">INCASSATO</span>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                    <a href="movimenti.php" class="link-btn">Vedi tutti i movimenti →</a>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <p>Nessun incasso ancora</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Ultimi Movimenti -->
            <div class="card">
                <h2>💸 Ultimi Movimenti sul Conto</h2>
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