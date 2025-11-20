<?php
require_once '../config.php';

// Verifica autenticazione e profilo amministrativo
if (!isset($_SESSION['user_id']) || $_SESSION['user_profile'] != 'BACKOFFICE_AMM') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

$conn = getDBConnection();

$success = '';
$error = '';

// GESTIONE RICHIESTA CANCELLAZIONE TRENO
if (isset($_POST['richiedi_cancellazione'])) {
    $id_treno = (int)$_POST['id_treno'];
    $motivo = trim($_POST['motivo']);
    
    if (empty($motivo)) {
        $error = "Il motivo della richiesta è obbligatorio.";
    } else {
        // Verifica che il treno esista e abbia 0 prenotazioni
        $stmt = $conn->prepare("
            SELECT t.numero_treno, COUNT(p.id_prenotazione) as tot_prenotazioni
            FROM TRENI t
            LEFT JOIN PRENOTAZIONI p ON t.id_treno = p.id_treno AND p.stato != 'ANNULLATA'
            WHERE t.id_treno = ?
            GROUP BY t.id_treno
        ");
        $stmt->bind_param("i", $id_treno);
        $stmt->execute();
        $treno = $stmt->get_result()->fetch_assoc();
        
        if (!$treno) {
            $error = "Treno non trovato.";
        } elseif ($treno['tot_prenotazioni'] > 0) {
            $error = "Il treno ha prenotazioni attive. Impossibile richiedere la cancellazione.";
        } else {
            $descrizione = "Richiesta cancellazione treno " . $treno['numero_treno'] . ". Motivo: " . $motivo;
            
            $stmt = $conn->prepare("
                INSERT INTO RICHIESTA_ADMIN (id_utente, tipo_richiesta, id_treno, descrizione)
                VALUES (?, 'CANCELLAZIONE_TRENO', ?, ?)
            ");
            $stmt->bind_param("iis", $user_id, $id_treno, $descrizione);
            
            if ($stmt->execute()) {
                $success = "Richiesta di cancellazione inviata con successo al backoffice esercizio.";
            } else {
                $error = "Errore durante l'invio della richiesta.";
            }
        }
    }
}

// GESTIONE RICHIESTA TRENO STRAORDINARIO
if (isset($_POST['richiedi_straordinario'])) {
    $data_treno = $_POST['data_treno'];
    $ora_treno = $_POST['ora_treno'];
    $id_stazione_partenza = (int)$_POST['stazione_partenza'];
    $id_stazione_arrivo = (int)$_POST['stazione_arrivo'];
    $motivo = trim($_POST['motivo_straordinario']);
    
    if (empty($data_treno) || empty($ora_treno) || empty($motivo)) {
        $error = "Tutti i campi sono obbligatori per richiedere un treno straordinario.";
    } else {
        // Recupera nomi stazioni
        $stmt = $conn->prepare("SELECT nome FROM STAZIONE WHERE id_stazione = ?");
        $stmt->bind_param("i", $id_stazione_partenza);
        $stmt->execute();
        $stazione_p = $stmt->get_result()->fetch_assoc();
        
        $stmt->bind_param("i", $id_stazione_arrivo);
        $stmt->execute();
        $stazione_a = $stmt->get_result()->fetch_assoc();
        
        $descrizione = "Richiesta treno straordinario per il " . date('d/m/Y', strtotime($data_treno)) . 
                      " ore " . $ora_treno . " sulla tratta " . $stazione_p['nome'] . " - " . 
                      $stazione_a['nome'] . ". Motivo: " . $motivo;
        
        $stmt = $conn->prepare("
            INSERT INTO RICHIESTA_ADMIN (id_utente, tipo_richiesta, descrizione)
            VALUES (?, 'TRENO_STRAORDINARIO', ?)
        ");
        $stmt->bind_param("is", $user_id, $descrizione);
        
        if ($stmt->execute()) {
            $success = "Richiesta treno straordinario inviata con successo al backoffice esercizio.";
        } else {
            $error = "Errore durante l'invio della richiesta.";
        }
    }
}

// STATISTICHE GENERALI
$stats_query = "
    SELECT 
        COUNT(DISTINCT t.id_treno) as tot_treni,
        COUNT(DISTINCT p.id_prenotazione) as tot_prenotazioni,
        SUM(CASE WHEN p.stato = 'CONFERMATA' THEN b.importo ELSE 0 END) as ricavi_totali,
        AVG(occupazione.percentuale) as occupazione_media
    FROM TRENI t
    LEFT JOIN PRENOTAZIONI p ON t.id_treno = p.id_treno
    LEFT JOIN BIGLIETTI b ON p.id_prenotazione = b.id_prenotazione
    LEFT JOIN (
        SELECT 
            t.id_treno,
            (COUNT(DISTINCT CASE WHEN p.stato = 'CONFERMATA' THEN p.id_posto END) * 100.0 / 
            COUNT(DISTINCT po.id_posto)) as percentuale
        FROM TRENI t
        JOIN CONVOGLI c ON t.id_convoglio = c.id_convoglio
        JOIN COMPOSIZIONI cc ON c.id_convoglio = cc.id_convoglio
        JOIN POSTI po ON cc.id_materiale = po.id_materiale
        LEFT JOIN PRENOTAZIONI p ON t.id_treno = p.id_treno AND po.id_posto = p.id_posto
        GROUP BY t.id_treno
    ) as occupazione ON t.id_treno = occupazione.id_treno
";
$stats = $conn->query($stats_query)->fetch_assoc();

// CALCOLO REDDITIVITÀ PER TRENO
$redditività_query = "
    SELECT 
        t.id_treno,
        t.numero_treno,
        t.data_partenza,
        c.nome as convoglio_nome,
        COUNT(DISTINCT po.id_posto) as posti_totali,
        COUNT(DISTINCT CASE WHEN p.stato = 'CONFERMATA' THEN p.id_posto END) as posti_venduti,
        ROUND((COUNT(DISTINCT CASE WHEN p.stato = 'CONFERMATA' THEN p.id_posto END) * 100.0 / 
               COUNT(DISTINCT po.id_posto)), 1) as percentuale_occupazione,
        COALESCE(SUM(CASE WHEN p.stato = 'CONFERMATA' THEN b.importo END), 0) as ricavi_totali,
        sp.nome as stazione_partenza,
        sa.nome as stazione_arrivo,
        ABS(sa.km_progressivo - sp.km_progressivo) as km_totali,
        ROUND(COALESCE(SUM(CASE WHEN p.stato = 'CONFERMATA' THEN b.importo END), 0) / 
              ABS(sa.km_progressivo - sp.km_progressivo), 2) as ricavo_per_km
    FROM TRENI t
    JOIN CONVOGLI c ON t.id_convoglio = c.id_convoglio
    JOIN COMPOSIZIONI cc ON c.id_convoglio = cc.id_convoglio
    JOIN POSTI po ON cc.id_materiale = po.id_materiale
    LEFT JOIN PRENOTAZIONI p ON t.id_treno = p.id_treno AND po.id_posto = p.id_posto
    LEFT JOIN BIGLIETTI b ON p.id_prenotazione = b.id_prenotazione
    JOIN FERMATE fp ON t.id_treno = fp.id_treno
    JOIN FERMATE fa ON t.id_treno = fa.id_treno
    JOIN STAZIONI sp ON fp.id_stazione = sp.id_stazione
    JOIN STAZIONI sa ON fa.id_stazione = sa.id_stazione
    WHERE fp.ordine_fermata = 1 AND fa.ordine_fermata = (
        SELECT MAX(ordine_fermata) FROM FERMATE WHERE id_treno = t.id_treno
    )
    GROUP BY t.id_treno, t.numero_treno, t.data_partenza, c.nome, 
             sp.nome, sa.nome, sp.km_progressivo, sa.km_progressivo
    ORDER BY t.data_partenza, t.numero_treno
";
$treni_redditività = $conn->query($redditività_query);

// TRENI CON ZERO PRENOTAZIONI
$treni_zero_query = "
    SELECT 
        t.id_treno,
        t.numero_treno,
        t.data_partenza,
        c.nome as convoglio_nome
    FROM TRENI t
    JOIN CONVOGLI c ON t.id_convoglio = c.id_convoglio
    LEFT JOIN PRENOTAZIONI p ON t.id_treno = p.id_treno AND p.stato != 'ANNULLATA'
    WHERE t.data_partenza >= CURDATE()
    GROUP BY t.id_treno
    HAVING COUNT(p.id_prenotazione) = 0
    ORDER BY t.data_partenza
";
$treni_zero = $conn->query($treni_zero_query);

// RICHIESTE RECENTI
$richieste_query = "
    SELECT 
        r.*,
        u.nome as admin_nome,
        t.numero_treno
    FROM RICHIESTA_ADMIN r
    JOIN UTENTI u ON r.id_utente = u.id_utente
    LEFT JOIN TRENI t ON r.id_treno = t.id_treno
    ORDER BY r.data_richiesta DESC
    LIMIT 10
";
$richieste = $conn->query($richieste_query);

// LISTA STAZIONI (per form treno straordinario)
$stazioni = $conn->query("SELECT * FROM STAZIONI ORDER BY km_progressivo");

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backoffice Amministrativo - SFT</title>
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
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 1.5rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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
            color: #dc3545;
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
            color: #dc3545;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        
        .btn-warning:hover {
            background: #e0a800;
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
            background: #dc3545;
            color: white;
        }
        
        main {
            padding: 2rem 0;
            min-height: calc(100vh - 200px);
        }
        
        .page-title {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .page-title h2 {
            font-size: 2rem;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .page-title p {
            color: #666;
        }
        
        /* Cards Statistiche */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #dc3545;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
        
        /* Sezioni */
        .section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .section h3 {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 3px solid #dc3545;
        }
        
        /* Tabella Redditività */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        thead {
            background: #dc3545;
            color: white;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            font-weight: bold;
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: bold;
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
        
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-secondary {
            background: #e2e3e5;
            color: #383d41;
        }
        
        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #555;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #dc3545;
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .mb-2 {
            margin-bottom: 1rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 0.85rem;
            }
            
            th, td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <h1>🏢 Backoffice Amministrativo SFT</h1>
                <div class="user-info">
                    <span>👤 <?php echo htmlspecialchars($user_name); ?></span>
                    <a href="../logout.php" class="btn btn-secondary">Esci</a>
                </div>
            </div>
        </div>
    </header>

    <nav>
        <ul>
            <li><a href="../index.php">Home Pubblica</a></li>
            <li><a href="index.php" class="active">Dashboard Amministrativa</a></li>
        </ul>
    </nav>

    <main>
        <div class="container">
            <div class="page-title">
                <h2>Dashboard Amministrativa</h2>
                <p>Monitoraggio redditività e gestione operativa</p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    ✓ <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    ✗ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- STATISTICHE GENERALI -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">🚂</div>
                    <div class="stat-value"><?php echo $stats['tot_treni']; ?></div>
                    <div class="stat-label">Treni Programmati</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">🎫</div>
                    <div class="stat-value"><?php echo $stats['tot_prenotazioni']; ?></div>
                    <div class="stat-label">Prenotazioni Totali</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value">€ <?php echo number_format($stats['ricavi_totali'] ?? 0, 2); ?></div>
                    <div class="stat-label">Ricavi Totali</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-value"><?php echo number_format($stats['occupazione_media'] ?? 0, 1); ?>%</div>
                    <div class="stat-label">Occupazione Media</div>
                </div>
            </div>

            <!-- REDDITIVITÀ PER TRENO -->
            <div class="section">
                <h3>💰 Redditività per Treno</h3>
                <p class="mb-2">Analisi dettagliata di occupazione e ricavi per ogni treno programmato</p>
                
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Treno</th>
                                <th>Data</th>
                                <th>Tratta</th>
                                <th>Convoglio</th>
                                <th>Posti Tot.</th>
                                <th>Venduti</th>
                                <th>Occupazione</th>
                                <th>Km</th>
                                <th>Ricavi Tot.</th>
                                <th>€/km</th>
                                <th>Redditività</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($treni_redditività->num_rows > 0): ?>
                                <?php while ($treno = $treni_redditività->fetch_assoc()): 
                                    $occupazione = $treno['percentuale_occupazione'];
                                    $ricavo_km = $treno['ricavo_per_km'];
                                    
                                    // Calcola indicatore redditività
                                    if ($occupazione >= 70 && $ricavo_km >= 0.30) {
                                        $redditività = '🟢 Alta';
                                        $badge_class = 'badge-success';
                                    } elseif ($occupazione >= 40 || $ricavo_km >= 0.20) {
                                        $redditività = '🟡 Media';
                                        $badge_class = 'badge-warning';
                                    } else {
                                        $redditività = '🔴 Bassa';
                                        $badge_class = 'badge-danger';
                                    }
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($treno['numero_treno']); ?></strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($treno['data_partenza'])); ?></td>
                                        <td><?php echo htmlspecialchars($treno['stazione_partenza']); ?> → <?php echo htmlspecialchars($treno['stazione_arrivo']); ?></td>
                                        <td><?php echo htmlspecialchars($treno['convoglio_nome']); ?></td>
                                        <td><?php echo $treno['posti_totali']; ?></td>
                                        <td><strong><?php echo $treno['posti_venduti']; ?></strong></td>
                                        <td>
                                            <span class="badge <?php 
                                                echo $occupazione >= 70 ? 'badge-success' : 
                                                     ($occupazione >= 40 ? 'badge-warning' : 'badge-danger'); 
                                            ?>">
                                                <?php echo number_format($occupazione, 1); ?>%
                                            </span>
                                        </td>
                                        <td><?php echo number_format($treno['km_totali'], 1); ?></td>
                                        <td><strong>€ <?php echo number_format($treno['ricavi_totali'], 2); ?></strong></td>
                                        <td>€ <?php echo number_format($ricavo_km, 2); ?></td>
                                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo $redditività; ?></span></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center">Nessun treno programmato</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 5px;">
                    <p style="margin-bottom: 0.5rem;"><strong>📊 Legenda Redditività:</strong></p>
                    <p style="font-size: 0.9rem; color: #666;">
                        🟢 <strong>Alta:</strong> Occupazione ≥70% e Ricavo/km ≥€0.30 | 
                        🟡 <strong>Media:</strong> Occupazione ≥40% o Ricavo/km ≥€0.20 | 
                        🔴 <strong>Bassa:</strong> Occupazione &lt;40% e Ricavo/km &lt;€0.20
                    </p>
                </div>
            </div>

            <!-- TRENI CON ZERO PRENOTAZIONI -->
            <div class="section">
                <h3>⚠️ Treni con Zero Prenotazioni</h3>
                <p class="mb-2">Treni futuri senza prenotazioni - valuta la cancellazione</p>
                
                <?php if ($treni_zero->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Treno</th>
                                    <th>Data Partenza</th>
                                    <th>Convoglio</th>
                                    <th>Azione</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($treno = $treni_zero->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($treno['numero_treno']); ?></strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($treno['data_partenza'])); ?></td>
                                        <td><?php echo htmlspecialchars($treno['convoglio_nome']); ?></td>
                                        <td>
                                            <button class="btn btn-danger btn-sm" onclick="richiedeCancellazione(<?php echo $treno['id_treno']; ?>, '<?php echo htmlspecialchars($treno['numero_treno']); ?>')">
                                                🗑️ Richiedi Cancellazione
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">✅</div>
                        <p>Ottimo! Tutti i treni futuri hanno almeno una prenotazione.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- FORM RICHIESTE -->
            <div class="form-grid">
                <!-- RICHIESTA TRENO STRAORDINARIO -->
                <div class="section">
                    <h3>➕ Richiedi Treno Straordinario</h3>
                    <form method="POST">
                        <div class="form-group">
                            <label>Data Treno *</label>
                            <input type="date" name="data_treno" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Orario *</label>
                            <input type="time" name="ora_treno" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Stazione Partenza *</label>
                            <select name="stazione_partenza" required>
                                <option value="">Seleziona...</option>
                                <?php 
                                $stazioni->data_seek(0);
                                while ($st = $stazioni->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $st['id_stazione']; ?>">
                                        <?php echo htmlspecialchars($st['nome']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Stazione Arrivo *</label>
                            <select name="stazione_arrivo" required>
                                <option value="">Seleziona...</option>
                                <?php 
                                $stazioni->data_seek(0);
                                while ($st = $stazioni->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $st['id_stazione']; ?>">
                                        <?php echo htmlspecialchars($st['nome']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Motivo Richiesta *</label>
                            <textarea name="motivo_straordinario" required placeholder="Es: Previsto evento locale con picco di domanda"></textarea>
                        </div>
                        
                        <button type="submit" name="richiedi_straordinario" class="btn btn-success">
                            📤 Invia Richiesta
                        </button>
                    </form>
                </div>

                <!-- ULTIME RICHIESTE -->
                <div class="section">
                    <h3>📋 Ultime Richieste</h3>
                    
                    <?php if ($richieste->num_rows > 0): ?>
                        <div style="max-height: 500px; overflow-y: auto;">
                            <?php while ($richiesta = $richieste->fetch_assoc()): ?>
                                <div style="border: 1px solid #ddd; border-radius: 5px; padding: 1rem; margin-bottom: 1rem;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                        <strong>
                                            <?php echo $richiesta['tipo_richiesta'] == 'TRENO_STRAORDINARIO' ? '➕ Treno Straordinario' : '🗑️ Cancellazione Treno'; ?>
                                        </strong>
                                        <span class="badge <?php 
                                            echo $richiesta['stato'] == 'IN_ATTESA' ? 'badge-warning' : 
                                                 ($richiesta['stato'] == 'APPROVATA' ? 'badge-success' : 'badge-danger'); 
                                        ?>">
                                            <?php echo $richiesta['stato']; ?>
                                        </span>
                                    </div>
                                    <p style="font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">
                                        <?php echo htmlspecialchars($richiesta['descrizione']); ?>
                                    </p>
                                    <p style="font-size: 0.85rem; color: #999;">
                                        📅 <?php echo date('d/m/Y H:i', strtotime($richiesta['data_richiesta'])); ?>
                                    </p>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <p>Nessuna richiesta ancora inviata</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Form nascosto per cancellazione -->
    <form id="formCancellazione" method="POST" style="display: none;">
        <input type="hidden" name="id_treno" id="id_treno_cancella">
        <input type="hidden" name="motivo" id="motivo_cancella">
        <input type="hidden" name="richiedi_cancellazione" value="1">
    </form>

    <script>
        function richiedeCancellazione(idTreno, numeroTreno) {
            const motivo = prompt('Inserisci il motivo della richiesta di cancellazione per il treno ' + numeroTreno + ':');
            
            if (motivo && motivo.trim() !== '') {
                document.getElementById('id_treno_cancella').value = idTreno;
                document.getElementById('motivo_cancella').value = motivo;
                document.getElementById('formCancellazione').submit();
            }
        }
    </script>
</body>
</html>
<?php
closeDBConnection($conn);
?>