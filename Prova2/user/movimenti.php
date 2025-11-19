<?php
require_once '../config.php';

// Verifica login e profilo UTENTE
requireProfile('UTENTE');

$conn = getDBConnection();

// Recupera informazioni conto
$stmt = $conn->prepare("
    SELECT id_conto, saldo
    FROM conto
    WHERE id_utente = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$conto = $result->fetch_assoc();

// Recupera tutti i movimenti
$stmt = $conn->prepare("
    SELECT m.*, t.descrizione as trans_descrizione, t.codice_transazione
    FROM movimento m
    LEFT JOIN transazione t ON m.id_transazione = t.id_transazione
    WHERE m.id_conto = ?
    ORDER BY m.data_movimento DESC
");
$stmt->bind_param("i", $conto['id_conto']);
$stmt->execute();
$movimenti = $stmt->get_result();

// Calcola statistiche
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'ENTRATA' THEN importo ELSE 0 END) as totale_entrate,
        SUM(CASE WHEN tipo = 'USCITA' THEN importo ELSE 0 END) as totale_uscite,
        COUNT(*) as numero_movimenti
    FROM movimento
    WHERE id_conto = ?
");
$stmt->bind_param("i", $conto['id_conto']);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movimenti - Pay Steam</title>
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
        
        .back-btn {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: opacity 0.3s;
        }
        
        .back-btn:hover {
            opacity: 0.8;
        }
        
        h1 {
            font-size: 1.8rem;
        }
        
        main {
            padding: 2rem 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #10b981;
        }
        
        .stat-value.negative {
            color: #ef4444;
        }
        
        .movements-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .card-header {
            padding: 1.5rem;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .card-header h2 {
            color: #10b981;
            font-size: 1.3rem;
        }
        
        .movements-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .movements-table th {
            background: #f8f9fa;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #666;
            font-size: 0.9rem;
        }
        
        .movements-table td {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .movements-table tr:last-child td {
            border-bottom: none;
        }
        
        .movements-table tr:hover {
            background: #f8f9fa;
        }
        
        .movement-type {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
        }
        
        .movement-type.entrata {
            color: #10b981;
        }
        
        .movement-type.uscita {
            color: #ef4444;
        }
        
        .amount {
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .amount.positive {
            color: #10b981;
        }
        
        .amount.negative {
            color: #ef4444;
        }
        
        .movement-desc {
            color: #333;
            font-weight: 500;
        }
        
        .movement-detail {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.25rem;
        }
        
        .date-cell {
            color: #666;
            font-size: 0.9rem;
        }
        
        .saldo-cell {
            color: #333;
            font-weight: 500;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .movements-table {
                font-size: 0.85rem;
            }
            
            .movements-table th,
            .movements-table td {
                padding: 0.75rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="back-btn">
                    <span>←</span>
                    <span>Torna alla Dashboard</span>
                </a>
                <h1>📊 Storico Movimenti</h1>
            </div>
        </div>
    </header>

    <main>
        <div class="container">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Saldo Attuale</div>
                    <div class="stat-value"><?php echo formatAmount($conto['saldo']); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Totale Entrate</div>
                    <div class="stat-value"><?php echo formatAmount($stats['totale_entrate'] ?? 0); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Totale Uscite</div>
                    <div class="stat-value negative"><?php echo formatAmount($stats['totale_uscite'] ?? 0); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Numero Movimenti</div>
                    <div class="stat-value" style="color: #6b7280;"><?php echo $stats['numero_movimenti']; ?></div>
                </div>
            </div>
            
            <div class="movements-card">
                <div class="card-header">
                    <h2>Tutti i Movimenti</h2>
                </div>
                
                <?php if ($movimenti->num_rows > 0): ?>
                    <table class="movements-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Descrizione</th>
                                <th style="text-align: right;">Importo</th>
                                <th style="text-align: right;">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($mov = $movimenti->fetch_assoc()): ?>
                                <tr>
                                    <td class="date-cell">
                                        <?php echo date('d/m/Y', strtotime($mov['data_movimento'])); ?><br>
                                        <span style="font-size: 0.75rem; color: #999;">
                                            <?php echo date('H:i', strtotime($mov['data_movimento'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="movement-type <?php echo strtolower($mov['tipo']); ?>">
                                            <?php echo $mov['tipo'] == 'ENTRATA' ? '📥 Entrata' : '📤 Uscita'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="movement-desc">
                                            <?php echo htmlspecialchars($mov['causale']); ?>
                                        </div>
                                        <?php if ($mov['codice_transazione']): ?>
                                            <div class="movement-detail">
                                                Codice: <?php echo htmlspecialchars($mov['codice_transazione']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <span class="amount <?php echo $mov['tipo'] == 'ENTRATA' ? 'positive' : 'negative'; ?>">
                                            <?php echo $mov['tipo'] == 'ENTRATA' ? '+' : '-'; ?><?php echo formatAmount($mov['importo']); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;" class="saldo-cell">
                                        <?php echo formatAmount($mov['saldo_nuovo']); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📊</div>
                        <p>Nessun movimento registrato</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>