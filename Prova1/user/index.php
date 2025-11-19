<?php
require_once '../config.php';

// Verifica autenticazione
if (!isset($_SESSION['user_id']) || $_SESSION['user_profile'] != 'REGISTRATO') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

$conn = getDBConnection();

// Recupera statistiche utente
$stmt = $conn->prepare("
    SELECT COUNT(*) as tot_prenotazioni 
    FROM PRENOTAZIONI 
    WHERE id_utente = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Recupera prenotazioni recenti
$stmt = $conn->prepare("
    SELECT 
        p.id_prenotazione,
        p.codice_prenotazione,
        p.data_prenotazione,
        p.stato,
        t.numero_treno,
        t.data_partenza,
        sp.nome as stazione_partenza,
        sa.nome as stazione_arrivo,
        po.numero_posto,
        m.sigla as materiale,
        b.importo
    FROM PRENOTAZIONI p
    JOIN TRENI t ON p.id_treno = t.id_treno
    JOIN STAZIONI sp ON p.id_stazione_partenza = sp.id_stazione
    JOIN STAZIONI sa ON p.id_stazione_arrivo = sa.id_stazione
    JOIN POSTI po ON p.id_posto = po.id_posto
    JOIN MATERIALE_ROTABILE m ON po.id_materiale = m.id_materiale
    LEFT JOIN BIGLIETTI b ON p.id_prenotazione = b.id_prenotazione
    WHERE p.id_utente = ?
    ORDER BY p.data_prenotazione DESC
    LIMIT 10
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$prenotazioni = $stmt->get_result();

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Area Utente - SFT</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
        }
        
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
        
        h1 {
            font-size: 1.8rem;
        }
        
        .user-info {
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
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: white;
            color: #667eea;
        }
        
        .btn-primary:hover {
            background: #f0f0f0;
        }
        
        .btn-secondary {
            background: transparent;
            color: white;
            border: 2px solid white;
        }
        
        .btn-secondary:hover {
            background: white;
            color: #667eea;
        }
        
        nav {
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        nav ul {
            list-style: none;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        nav li {
            margin: 0;
        }
        
        nav a {
            display: block;
            padding: 1rem 1.5rem;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
        }
        
        nav a:hover, nav a.active {
            background: #667eea;
            color: white;
        }
        
        main {
            padding: 2rem 0;
        }
        
        .dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card h3 {
            color: #667eea;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-card p {
            color: #666;
        }
        
        .section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .section h2 {
            color: #667eea;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f0f0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        
        th {
            background: #f8f9fa;
            color: #333;
            font-weight: 600;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        
        .empty-state h3 {
            margin-bottom: 1rem;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .action-card {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s;
            text-decoration: none;
            color: #333;
        }
        
        .action-card:hover {
            background: #667eea;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .action-card h4 {
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <h1>Area Utente</h1>
                <div class="user-info">
                    <span>Ciao, <?php echo htmlspecialchars($user_name); ?>!</span>
                    <a href="../index.php" class="btn btn-primary">Homepage</a>
                    <a href="../logout.php" class="btn btn-secondary">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <nav>
        <ul>
            <li><a href="index.php" class="active">Dashboard</a></li>
            <li><a href="prenota.php">Prenota Biglietto</a></li>
            <li><a href="prenotazioni.php">Le Mie Prenotazioni</a></li>
            <li><a href="profilo.php">Il Mio Profilo</a></li>
        </ul>
    </nav>

    <main>
        <div class="container">
            <div class="dashboard">
                <div class="stat-card">
                    <h3><?php echo $stats['tot_prenotazioni']; ?></h3>
                    <p>Prenotazioni Totali</p>
                </div>
                
                <div class="stat-card">
                    <h3>€ 0,50</h3>
                    <p>Tariffa per km</p>
                </div>
                
                <div class="stat-card">
                    <h3>10</h3>
                    <p>Stazioni Disponibili</p>
                </div>
            </div>

            <div class="section">
                <h2>Azioni Rapide</h2>
                <div class="quick-actions">
                    <a href="prenota.php" class="action-card">
                        <h4>🎫 Prenota Biglietto</h4>
                        <p>Prenota il tuo viaggio</p>
                    </a>
                    <a href="../orari.php" class="action-card">
                        <h4>🕒 Consulta Orari</h4>
                        <p>Vedi gli orari dei treni</p>
                    </a>
                    <a href="../stazioni.php" class="action-card">
                        <h4>🚉 Le Stazioni</h4>
                        <p>Scopri le nostre stazioni</p>
                    </a>
                    <a href="../materiale.php" class="action-card">
                        <h4>🚂 Materiale Rotabile</h4>
                        <p>Scopri i nostri treni</p>
                    </a>
                </div>
            </div>

            <div class="section">
                <h2>Prenotazioni Recenti</h2>
                <?php if ($prenotazioni->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Codice</th>
                                <th>Data Viaggio</th>
                                <th>Tratta</th>
                                <th>Posto</th>
                                <th>Stato</th>
                                <th>Importo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($p = $prenotazioni->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($p['codice_prenotazione']); ?></strong></td>
                                    <td><?php echo date('d/m/Y', strtotime($p['data_partenza'])); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($p['stazione_partenza']); ?> → 
                                        <?php echo htmlspecialchars($p['stazione_arrivo']); ?>
                                    </td>
                                    <td>
                                        Posto <?php echo $p['numero_posto']; ?> 
                                        (<?php echo htmlspecialchars($p['materiale']); ?>)
                                    </td>
                                    <td>
                                        <?php
                                        $stato_class = 'badge-warning';
                                        if ($p['stato'] == 'CONFERMATA') $stato_class = 'badge-success';
                                        if ($p['stato'] == 'ANNULLATA') $stato_class = 'badge-danger';
                                        ?>
                                        <span class="badge <?php echo $stato_class; ?>">
                                            <?php echo htmlspecialchars($p['stato']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $p['importo'] ? '€ ' . number_format($p['importo'], 2) : '-'; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <h3>Nessuna prenotazione</h3>
                        <p>Non hai ancora effettuato prenotazioni.</p>
                        <br>
                        <a href="prenota.php" class="btn btn-primary">Prenota il tuo primo viaggio</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>