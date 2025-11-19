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

// Gestione cancellazione prenotazione (opzionale)
if (isset($_POST['cancella_prenotazione'])) {
    $id_prenotazione = (int)$_POST['id_prenotazione'];
    
    // Verifica che la prenotazione appartenga all'utente e sia cancellabile
    $stmt = $conn->prepare("
        SELECT p.*, t.data_partenza 
        FROM PRENOTAZIONI p
        JOIN TRENI t ON p.id_treno = t.id_treno
        WHERE p.id_prenotazione = ? AND p.id_utente = ?
    ");
    $stmt->bind_param("ii", $id_prenotazione, $user_id);
    $stmt->execute();
    $prenotazione = $stmt->get_result()->fetch_assoc();
    
    if ($prenotazione) {
        // Verifica che il viaggio non sia già passato
        if (strtotime($prenotazione['data_partenza']) > time()) {
            // Aggiorna lo stato a ANNULLATA
            $stmt = $conn->prepare("UPDATE PRENOTAZIONI SET stato = 'ANNULLATA' WHERE id_prenotazione = ?");
            $stmt->bind_param("i", $id_prenotazione);
            
            if ($stmt->execute()) {
                $success = "Prenotazione annullata con successo!";
            } else {
                $error = "Errore durante l'annullamento della prenotazione.";
            }
        } else {
            $error = "Non puoi cancellare una prenotazione per un viaggio già effettuato.";
        }
    } else {
        $error = "Prenotazione non trovata o non autorizzato.";
    }
}

// Filtro stato (opzionale)
$filtro_stato = isset($_GET['stato']) ? $_GET['stato'] : 'TUTTE';

// Recupera tutte le prenotazioni dell'utente
$query = "
    SELECT 
        p.id_prenotazione,
        p.codice_prenotazione,
        p.data_prenotazione,
        p.stato,
        t.numero_treno,
        t.data_partenza,
        c.nome as convoglio_nome,
        sp.nome as stazione_partenza,
        sa.nome as stazione_arrivo,
        sp.km_progressivo as km_partenza,
        sa.km_progressivo as km_arrivo,
        po.numero_posto,
        po.tipo as tipo_posto,
        m.sigla as materiale,
        b.importo,
        b.stato_pagamento,
        fp.orario_partenza,
        fa.orario_arrivo
    FROM PRENOTAZIONI p
    JOIN TRENI t ON p.id_treno = t.id_treno
    JOIN CONVOGLI c ON t.id_convoglio = c.id_convoglio
    JOIN STAZIONI sp ON p.id_stazione_partenza = sp.id_stazione
    JOIN STAZIONI sa ON p.id_stazione_arrivo = sa.id_stazione
    JOIN POSTI po ON p.id_posto = po.id_posto
    JOIN MATERIALE_ROTABILE m ON po.id_materiale = m.id_materiale
    LEFT JOIN BIGLIETTI b ON p.id_prenotazione = b.id_prenotazione
    LEFT JOIN FERMATE fp ON t.id_treno = fp.id_treno AND fp.id_stazione = sp.id_stazione
    LEFT JOIN FERMATE fa ON t.id_treno = fa.id_treno AND fa.id_stazione = sa.id_stazione
    WHERE p.id_utente = ?
";

// Aggiungi filtro stato se necessario
if ($filtro_stato != 'TUTTE') {
    $query .= " AND p.stato = ?";
}

$query .= " ORDER BY p.data_prenotazione DESC";

$stmt = $conn->prepare($query);

if ($filtro_stato != 'TUTTE') {
    $stmt->bind_param("is", $user_id, $filtro_stato);
} else {
    $stmt->bind_param("i", $user_id);
}

$stmt->execute();
$prenotazioni = $stmt->get_result();

// Calcola statistiche
$stmt_stats = $conn->prepare("
    SELECT 
        COUNT(*) as totale,
        SUM(CASE WHEN stato = 'CONFERMATA' THEN 1 ELSE 0 END) as confermate,
        SUM(CASE WHEN stato = 'IN_ATTESA_PAGAMENTO' THEN 1 ELSE 0 END) as in_attesa,
        SUM(CASE WHEN stato = 'ANNULLATA' THEN 1 ELSE 0 END) as annullate
    FROM PRENOTAZIONI
    WHERE id_utente = ?
");
$stmt_stats->bind_param("i", $user_id);
$stmt_stats->execute();
$stats = $stmt_stats->get_result()->fetch_assoc();

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Le Mie Prenotazioni - SFT</title>
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
        
        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 0.4rem 1rem;
            font-size: 0.85rem;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
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
        
        .stats-cards {
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
            text-align: center;
        }
        
        .stat-card h3 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-card.total h3 {
            color: #667eea;
        }
        
        .stat-card.confirmed h3 {
            color: #28a745;
        }
        
        .stat-card.pending h3 {
            color: #ffc107;
        }
        
        .stat-card.cancelled h3 {
            color: #dc3545;
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
        
        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #333;
        }
        
        .filter-btn:hover {
            border-color: #667eea;
            color: #667eea;
        }
        
        .filter-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
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
            display: inline-block;
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
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        
        .empty-state h3 {
            margin-bottom: 1rem;
            color: #666;
        }
        
        .prenotazione-details {
            font-size: 0.9rem;
            color: #666;
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
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .actions {
            display: flex;
            gap: 0.5rem;
        }
        
        @media (max-width: 768px) {
            table {
                font-size: 0.9rem;
            }
            
            th, td {
                padding: 0.5rem;
            }
            
            .btn-danger {
                padding: 0.3rem 0.7rem;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <h1>Le Mie Prenotazioni</h1>
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
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="prenota.php">Prenota Biglietto</a></li>
            <li><a href="prenotazioni.php" class="active">Le Mie Prenotazioni</a></li>
            <li><a href="profilo.php">Il Mio Profilo</a></li>
        </ul>
    </nav>

    <main>
        <div class="container">
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <strong>Successo!</strong> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <strong>Errore:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="stats-cards">
                <div class="stat-card total">
                    <h3><?php echo $stats['totale']; ?></h3>
                    <p>Prenotazioni Totali</p>
                </div>
                
                <div class="stat-card confirmed">
                    <h3><?php echo $stats['confermate']; ?></h3>
                    <p>Confermate</p>
                </div>
                
                <div class="stat-card pending">
                    <h3><?php echo $stats['in_attesa']; ?></h3>
                    <p>In Attesa</p>
                </div>
                
                <div class="stat-card cancelled">
                    <h3><?php echo $stats['annullate']; ?></h3>
                    <p>Annullate</p>
                </div>
            </div>

            <div class="section">
                <h2>Storico Prenotazioni</h2>
                
                <div class="filters">
                    <a href="prenotazioni.php?stato=TUTTE" 
                       class="filter-btn <?php echo $filtro_stato == 'TUTTE' ? 'active' : ''; ?>">
                        Tutte
                    </a>
                    <a href="prenotazioni.php?stato=CONFERMATA" 
                       class="filter-btn <?php echo $filtro_stato == 'CONFERMATA' ? 'active' : ''; ?>">
                        Confermate
                    </a>
                    <a href="prenotazioni.php?stato=IN_ATTESA_PAGAMENTO" 
                       class="filter-btn <?php echo $filtro_stato == 'IN_ATTESA_PAGAMENTO' ? 'active' : ''; ?>">
                        In Attesa
                    </a>
                    <a href="prenotazioni.php?stato=ANNULLATA" 
                       class="filter-btn <?php echo $filtro_stato == 'ANNULLATA' ? 'active' : ''; ?>">
                        Annullate
                    </a>
                </div>
                
                <?php if ($prenotazioni->num_rows > 0): ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Data Viaggio</th>
                                    <th>Tratta</th>
                                    <th>Treno</th>
                                    <th>Posto</th>
                                    <th>Importo</th>
                                    <th>Stato</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($p = $prenotazioni->fetch_assoc()): ?>
                                    <?php
                                    $data_viaggio = strtotime($p['data_partenza']);
                                    $oggi = time();
                                    $puo_cancellare = ($data_viaggio > $oggi) && ($p['stato'] != 'ANNULLATA');
                                    $km_percorsi = abs($p['km_arrivo'] - $p['km_partenza']);
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($p['codice_prenotazione']); ?></strong>
                                            <div class="prenotazione-details">
                                                Prenotato il <?php echo date('d/m/Y H:i', strtotime($p['data_prenotazione'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo date('d/m/Y', $data_viaggio); ?></strong>
                                            <div class="prenotazione-details">
                                                <?php echo date('H:i', strtotime($p['orario_partenza'])); ?> - 
                                                <?php echo date('H:i', strtotime($p['orario_arrivo'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($p['stazione_partenza']); ?></strong>
                                            <div class="prenotazione-details">↓</div>
                                            <strong><?php echo htmlspecialchars($p['stazione_arrivo']); ?></strong>
                                            <div class="prenotazione-details">
                                                <?php echo number_format($km_percorsi, 2); ?> km
                                            </div>
                                        </td>
                                        <td>
                                            <strong>Treno <?php echo htmlspecialchars($p['numero_treno']); ?></strong>
                                            <div class="prenotazione-details">
                                                <?php echo htmlspecialchars($p['convoglio_nome']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong>Posto <?php echo $p['numero_posto']; ?></strong>
                                            <div class="prenotazione-details">
                                                <?php echo $p['tipo_posto'] == 'FINESTRINO' ? '🪟 Finestrino' : '🚶 Corridoio'; ?>
                                            </div>
                                            <div class="prenotazione-details">
                                                <?php echo htmlspecialchars($p['materiale']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong>€ <?php echo number_format($p['importo'], 2); ?></strong>
                                            <?php if ($p['stato_pagamento']): ?>
                                                <div class="prenotazione-details">
                                                    <span class="badge badge-success" style="font-size: 0.7rem;">
                                                        <?php echo htmlspecialchars($p['stato_pagamento']); ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $stato_class = 'badge-warning';
                                            if ($p['stato'] == 'CONFERMATA') $stato_class = 'badge-success';
                                            if ($p['stato'] == 'ANNULLATA') $stato_class = 'badge-danger';
                                            ?>
                                            <span class="badge <?php echo $stato_class; ?>">
                                                <?php echo str_replace('_', ' ', $p['stato']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <?php if ($puo_cancellare): ?>
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('Sei sicuro di voler cancellare questa prenotazione?');">
                                                        <input type="hidden" name="id_prenotazione" 
                                                               value="<?php echo $p['id_prenotazione']; ?>">
                                                        <button type="submit" name="cancella_prenotazione" 
                                                                class="btn btn-danger">
                                                            Annulla
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <?php if ($data_viaggio < $oggi): ?>
                                                        <span class="badge badge-info">Viaggio effettuato</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <h3>Nessuna prenotazione trovata</h3>
                        <?php if ($filtro_stato != 'TUTTE'): ?>
                            <p>Non hai prenotazioni con stato "<?php echo htmlspecialchars($filtro_stato); ?>".</p>
                            <br>
                            <a href="prenotazioni.php?stato=TUTTE" class="btn btn-primary">Vedi tutte</a>
                        <?php else: ?>
                            <p>Non hai ancora effettuato prenotazioni.</p>
                            <br>
                            <a href="prenota.php" class="btn btn-success">Prenota il tuo primo viaggio</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>