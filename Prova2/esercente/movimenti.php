<?php
require_once '../config.php';

// Verifica login e profilo ESERCENTE
requireProfile('ESERCENTE');

$conn = getDBConnection();

// Recupera informazioni conto
$stmt = $conn->prepare("
    SELECT id_conto, saldo, data_creazione
    FROM conto
    WHERE id_utente = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$conto = $result->fetch_assoc();

// Parametri per filtri e paginazione
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'TUTTI';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Costruisci query con filtri
$where_clause = "WHERE m.id_conto = ?";
$params = [$conto['id_conto']];
$types = "i";

if ($tipo_filtro == 'ENTRATA' || $tipo_filtro == 'USCITA') {
    $where_clause .= " AND m.tipo = ?";
    $params[] = $tipo_filtro;
    $types .= "s";
}

// Query per contare il totale
$count_query = "SELECT COUNT(*) as total FROM movimento m $where_clause";
$stmt = $conn->prepare($count_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total_movimenti = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_movimenti / $per_page);

// Query per recuperare i movimenti
$stmt = $conn->prepare("
    SELECT m.*, t.descrizione as trans_descrizione, t.codice_transazione
    FROM movimento m
    LEFT JOIN transazione t ON m.id_transazione = t.id_transazione
    $where_clause
    ORDER BY m.data_movimento DESC
    LIMIT ? OFFSET ?
");
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$movimenti = $stmt->get_result();

// Calcola totali per tipo
$stmt = $conn->prepare("
    SELECT 
        tipo,
        COUNT(*) as numero,
        SUM(importo) as totale
    FROM movimento
    WHERE id_conto = ?
    GROUP BY tipo
");
$stmt->bind_param("i", $conto['id_conto']);
$stmt->execute();
$result = $stmt->get_result();
$stats = ['ENTRATA' => ['numero' => 0, 'totale' => 0], 'USCITA' => ['numero' => 0, 'totale' => 0]];
while ($row = $result->fetch_assoc()) {
    $stats[$row['tipo']] = $row;
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storico Movimenti - Pay Steam</title>
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
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 2rem;
            color: #333;
        }
        
        .back-link {
            color: #10b981;
            text-decoration: none;
            font-weight: 600;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #10b981;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 0.5rem;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #333;
        }
        
        .stat-subvalue {
            font-size: 0.9rem;
            color: #999;
            margin-top: 0.25rem;
        }
        
        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #10b981;
            background: white;
            color: #10b981;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .filter-btn:hover {
            background: #10b981;
            color: white;
        }
        
        .filter-btn.active {
            background: #10b981;
            color: white;
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
            color: #333;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .movements-table td {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .movements-table tr:hover {
            background: #f8f9fa;
        }
        
        .amount-in {
            color: #10b981;
            font-weight: bold;
        }
        
        .amount-out {
            color: #ef4444;
            font-weight: bold;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-entrata {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-uscita {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            border: 2px solid #10b981;
            border-radius: 5px;
            text-decoration: none;
            color: #10b981;
            font-weight: 600;
        }
        
        .pagination a:hover {
            background: #10b981;
            color: white;
        }
        
        .pagination .current {
            background: #10b981;
            color: white;
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
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo-area">
                    <span style="font-size: 2rem;">📊</span>
                    <div>
                        <h1>Storico Movimenti</h1>
                        <p style="opacity: 0.9;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    </div>
                </div>
                <div class="user-menu">
                    <a href="index.php" class="btn btn-secondary">Dashboard</a>
                    <a href="../logout.php" class="btn btn-primary">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="container">
            <div class="page-header">
                <h2 class="page-title">I Tuoi Movimenti</h2>
                <a href="index.php" class="back-link">← Torna alla Dashboard</a>
            </div>
            
            <!-- Statistiche -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Saldo Attuale</div>
                    <div class="stat-value"><?php echo formatAmount($conto['saldo']); ?></div>
                </div>
                <div class="stat-card" style="border-left-color: #10b981;">
                    <div class="stat-label">Totale Entrate</div>
                    <div class="stat-value amount-in"><?php echo formatAmount($stats['ENTRATA']['totale']); ?></div>
                    <div class="stat-subvalue"><?php echo $stats['ENTRATA']['numero']; ?> movimenti</div>
                </div>
                <div class="stat-card" style="border-left-color: #ef4444;">
                    <div class="stat-label">Totale Uscite</div>
                    <div class="stat-value amount-out"><?php echo formatAmount($stats['USCITA']['totale']); ?></div>
                    <div class="stat-subvalue"><?php echo $stats['USCITA']['numero']; ?> movimenti</div>
                </div>
            </div>
            
            <!-- Filtri -->
            <div class="card">
                <div class="filters">
                    <a href="?tipo=TUTTI" class="filter-btn <?php echo $tipo_filtro == 'TUTTI' ? 'active' : ''; ?>">
                        📋 Tutti
                    </a>
                    <a href="?tipo=ENTRATA" class="filter-btn <?php echo $tipo_filtro == 'ENTRATA' ? 'active' : ''; ?>">
                        📥 Solo Entrate
                    </a>
                    <a href="?tipo=USCITA" class="filter-btn <?php echo $tipo_filtro == 'USCITA' ? 'active' : ''; ?>">
                        📤 Solo Uscite
                    </a>
                </div>
                
                <?php if ($movimenti->num_rows > 0): ?>
                    <table class="movements-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Causale</th>
                                <th>Importo</th>
                                <th>Saldo Dopo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($mov = $movimenti->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;">
                                            <?php echo date('d/m/Y', strtotime($mov['data_movimento'])); ?>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #666;">
                                            <?php echo date('H:i', strtotime($mov['data_movimento'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $mov['tipo'] == 'ENTRATA' ? 'badge-entrata' : 'badge-uscita'; ?>">
                                            <?php echo $mov['tipo'] == 'ENTRATA' ? '📥 ENTRATA' : '📤 USCITA'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; margin-bottom: 0.25rem;">
                                            <?php echo htmlspecialchars($mov['causale']); ?>
                                        </div>
                                        <?php if ($mov['codice_transazione']): ?>
                                            <div style="font-size: 0.75rem; color: #999; font-family: monospace;">
                                                <?php echo htmlspecialchars($mov['codice_transazione']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo $mov['tipo'] == 'ENTRATA' ? 'amount-in' : 'amount-out'; ?>">
                                            <?php echo $mov['tipo'] == 'ENTRATA' ? '+' : '-'; ?>
                                            <?php echo formatAmount($mov['importo']); ?>
                                        </span>
                                    </td>
                                    <td style="font-weight: 600;">
                                        <?php echo formatAmount($mov['saldo_nuovo']); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?tipo=<?php echo $tipo_filtro; ?>&page=<?php echo $page - 1; ?>">← Precedente</a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <?php if ($i == $page): ?>
                                    <span class="current"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?tipo=<?php echo $tipo_filtro; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?tipo=<?php echo $tipo_filtro; ?>&page=<?php echo $page + 1; ?>">Successiva →</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📊</div>
                        <p>Nessun movimento trovato con i filtri selezionati</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>