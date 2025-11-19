<?php
require_once '../config.php';

// Verifica autenticazione e profilo esercizio
if (!isset($_SESSION['user_id']) || $_SESSION['user_profile'] != 'BACKOFFICE_ESE') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

$conn = getDBConnection();

$success = '';
$error = '';

// Determina la sezione attiva
$sezione = isset($_GET['sezione']) ? $_GET['sezione'] : 'dashboard';

// ============================================
// GESTIONE CREAZIONE CONVOGLIO
// ============================================
if (isset($_POST['crea_convoglio'])) {
    $nome_convoglio = trim($_POST['nome_convoglio']);
    $materiali = isset($_POST['materiale']) ? $_POST['materiale'] : [];
    
    if (empty($nome_convoglio)) {
        $error = "Il nome del convoglio è obbligatorio.";
    } elseif (empty($materiali)) {
        $error = "Seleziona almeno un materiale rotabile per il convoglio.";
    } else {
        // Verifica che i materiali siano disponibili (non già in altri convogli attivi)
        $materiali_str = implode(',', array_map('intval', $materiali));
        $check = $conn->query("
            SELECT m.sigla 
            FROM MATERIALE_ROTABILE m
            JOIN COMPOSIZIONE_CONVOGLIO cc ON m.id_materiale = cc.id_materiale
            JOIN CONVOGLIO c ON cc.id_convoglio = c.id_convoglio
            WHERE m.id_materiale IN ($materiali_str) AND c.attivo = 1
        ");
        
        if ($check->num_rows > 0) {
            $materiali_occupati = [];
            while ($row = $check->fetch_assoc()) {
                $materiali_occupati[] = $row['sigla'];
            }
            $error = "Materiale già in uso in altri convogli attivi: " . implode(', ', $materiali_occupati);
        } else {
            // Calcola posti totali
            $posti_query = $conn->query("
                SELECT SUM(posti_sedere) as tot_posti
                FROM MATERIALE_ROTABILE
                WHERE id_materiale IN ($materiali_str)
            ");
            $posti_totali = $posti_query->fetch_assoc()['tot_posti'];
            
            // Inserisci convoglio
            $stmt = $conn->prepare("INSERT INTO CONVOGLIO (nome, posti_totali, data_creazione) VALUES (?, ?, CURDATE())");
            $stmt->bind_param("si", $nome_convoglio, $posti_totali);
            
            if ($stmt->execute()) {
                $id_convoglio = $conn->insert_id;
                
                // Inserisci composizione
                $posizione = 1;
                foreach ($materiali as $id_materiale) {
                    $stmt = $conn->prepare("INSERT INTO COMPOSIZIONE_CONVOGLIO (id_convoglio, id_materiale, posizione) VALUES (?, ?, ?)");
                    $stmt->bind_param("iii", $id_convoglio, $id_materiale, $posizione);
                    $stmt->execute();
                    $posizione++;
                }
                
                $success = "Convoglio '$nome_convoglio' creato con successo! ($posti_totali posti totali)";
            } else {
                $error = "Errore durante la creazione del convoglio.";
            }
        }
    }
}

// ============================================
// GESTIONE CREAZIONE TRENO
// ============================================
if (isset($_POST['crea_treno'])) {
    $numero_treno = trim($_POST['numero_treno']);
    $id_convoglio = (int)$_POST['id_convoglio'];
    $data_partenza = $_POST['data_partenza'];
    $id_stazione_partenza = (int)$_POST['id_stazione_partenza'];
    $id_stazione_arrivo = (int)$_POST['id_stazione_arrivo'];
    $ora_partenza = $_POST['ora_partenza'];
    
    if (empty($numero_treno) || empty($data_partenza) || empty($ora_partenza)) {
        $error = "Tutti i campi sono obbligatori.";
    } elseif ($id_stazione_partenza == $id_stazione_arrivo) {
        $error = "Stazione di partenza e arrivo devono essere diverse.";
    } else {
        // Verifica disponibilità convoglio per quella data
        $check = $conn->prepare("
            SELECT numero_treno FROM TRENO 
            WHERE id_convoglio = ? AND data_partenza = ? AND stato != 'CANCELLATO'
        ");
        $check->bind_param("is", $id_convoglio, $data_partenza);
        $check->execute();
        $convoglio_check = $check->get_result();
        
        if ($convoglio_check->num_rows > 0) {
            $treno_esistente = $convoglio_check->fetch_assoc();
            $error = "Convoglio già utilizzato dal treno " . $treno_esistente['numero_treno'] . " in quella data.";
        } else {
            // Recupera info stazioni
            $stazioni_info = $conn->query("
                SELECT id_stazione, nome, km_progressivo 
                FROM STAZIONE 
                WHERE id_stazione IN ($id_stazione_partenza, $id_stazione_arrivo)
            ");
            
            $stazioni = [];
            while ($st = $stazioni_info->fetch_assoc()) {
                $stazioni[$st['id_stazione']] = $st;
            }
            
            $km_partenza = $stazioni[$id_stazione_partenza]['km_progressivo'];
            $km_arrivo = $stazioni[$id_stazione_arrivo]['km_progressivo'];
            $direzione = $km_arrivo > $km_partenza ? 'SUD' : 'NORD';
            
            // Inserisci treno
            $stmt = $conn->prepare("
                INSERT INTO TRENO (numero_treno, id_convoglio, data_partenza, id_stazione_partenza, id_stazione_arrivo, direzione)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sissis", $numero_treno, $id_convoglio, $data_partenza, $id_stazione_partenza, $id_stazione_arrivo, $direzione);
            
            if ($stmt->execute()) {
                $id_treno = $conn->insert_id;
                
                // GENERAZIONE AUTOMATICA FERMATE
                // Recupera tutte le stazioni intermedie
                if ($direzione == 'SUD') {
                    $stazioni_percorso = $conn->query("
                        SELECT * FROM STAZIONE 
                        WHERE km_progressivo >= $km_partenza AND km_progressivo <= $km_arrivo
                        ORDER BY km_progressivo
                    ");
                } else {
                    $stazioni_percorso = $conn->query("
                        SELECT * FROM STAZIONE 
                        WHERE km_progressivo <= $km_partenza AND km_progressivo >= $km_arrivo
                        ORDER BY km_progressivo DESC
                    ");
                }
                
                $ordine = 1;
                $ora_corrente = strtotime($data_partenza . ' ' . $ora_partenza);
                $km_precedente = $km_partenza;
                
                while ($stazione = $stazioni_percorso->fetch_assoc()) {
                    $km_stazione = $stazione['km_progressivo'];
                    
                    // Calcola tempo di viaggio dalla stazione precedente
                    $km_percorsi = abs($km_stazione - $km_precedente);
                    $ore_viaggio = $km_percorsi / 50; // Velocità 50 km/h
                    $minuti_viaggio = $ore_viaggio * 60;
                    
                    if ($ordine == 1) {
                        // Prima stazione: solo partenza
                        $orario_arrivo = date('Y-m-d H:i:s', $ora_corrente);
                        $orario_partenza = date('Y-m-d H:i:s', $ora_corrente);
                    } else {
                        // Stazioni intermedie: arrivo + sosta 1 min + partenza
                        $ora_corrente += ($minuti_viaggio * 60); // Aggiungi tempo viaggio
                        $orario_arrivo = date('Y-m-d H:i:s', $ora_corrente);
                        
                        if ($stazione['id_stazione'] == $id_stazione_arrivo) {
                            // Ultima stazione: solo arrivo
                            $orario_partenza = $orario_arrivo;
                        } else {
                            // Stazione intermedia: sosta 1 minuto
                            $ora_corrente += 60; // 1 minuto di sosta
                            $orario_partenza = date('Y-m-d H:i:s', $ora_corrente);
                        }
                    }
                    
                    // Inserisci fermata
                    $stmt = $conn->prepare("
                        INSERT INTO FERMATA (id_treno, id_stazione, orario_arrivo, orario_partenza, ordine_fermata)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("iissi", $id_treno, $stazione['id_stazione'], $orario_arrivo, $orario_partenza, $ordine);
                    $stmt->execute();
                    
                    $km_precedente = $km_stazione;
                    $ordine++;
                }
                
                $success = "Treno $numero_treno creato con successo! Fermate generate automaticamente.";
            } else {
                $error = "Errore durante la creazione del treno.";
            }
        }
    }
}

// ============================================
// GESTIONE CANCELLAZIONE TRENO
// ============================================
if (isset($_POST['cancella_treno'])) {
    $id_treno = (int)$_POST['id_treno'];
    
    // Verifica che non ci siano prenotazioni
    $check = $conn->prepare("
        SELECT COUNT(*) as tot FROM PRENOTAZIONE 
        WHERE id_treno = ? AND stato != 'ANNULLATA'
    ");
    $check->bind_param("i", $id_treno);
    $check->execute();
    $result = $check->get_result()->fetch_assoc();
    
    if ($result['tot'] > 0) {
        $error = "Impossibile cancellare: il treno ha prenotazioni attive.";
    } else {
        $stmt = $conn->prepare("UPDATE TRENO SET stato = 'CANCELLATO' WHERE id_treno = ?");
        $stmt->bind_param("i", $id_treno);
        
        if ($stmt->execute()) {
            $success = "Treno cancellato con successo.";
        } else {
            $error = "Errore durante la cancellazione.";
        }
    }
}

// ============================================
// GESTIONE RICHIESTE ADMIN
// ============================================
if (isset($_POST['gestisci_richiesta'])) {
    $id_richiesta = (int)$_POST['id_richiesta'];
    $azione = $_POST['azione']; // 'APPROVATA' o 'RIFIUTATA'
    $note_risposta = trim($_POST['note_risposta']);
    
    $stmt = $conn->prepare("
        UPDATE RICHIESTA_ADMIN 
        SET stato = ?, note_risposta = ?, data_gestione = NOW()
        WHERE id_richiesta = ?
    ");
    $stmt->bind_param("ssi", $azione, $note_risposta, $id_richiesta);
    
    if ($stmt->execute()) {
        $success = "Richiesta " . strtolower($azione) . " con successo.";
    } else {
        $error = "Errore durante la gestione della richiesta.";
    }
}

// ============================================
// RECUPERO DATI PER DASHBOARD
// ============================================

// Statistiche generali
$stats = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM TRENO WHERE stato = 'PROGRAMMATO') as treni_programmati,
        (SELECT COUNT(*) FROM CONVOGLIO WHERE attivo = 1) as convogli_disponibili,
        (SELECT COUNT(*) FROM MATERIALE_ROTABILE) as materiale_totale,
        (SELECT COUNT(*) FROM RICHIESTA_ADMIN WHERE stato = 'IN_ATTESA') as richieste_attesa
")->fetch_assoc();

// Lista convogli
$convogli = $conn->query("
    SELECT 
        c.*,
        GROUP_CONCAT(m.sigla ORDER BY cc.posizione SEPARATOR ' + ') as composizione,
        COUNT(DISTINCT t.id_treno) as treni_utilizzanti
    FROM CONVOGLIO c
    LEFT JOIN COMPOSIZIONE_CONVOGLIO cc ON c.id_convoglio = cc.id_convoglio
    LEFT JOIN MATERIALE_ROTABILE m ON cc.id_materiale = m.id_materiale
    LEFT JOIN TRENO t ON c.id_convoglio = t.id_convoglio AND t.stato != 'CANCELLATO'
    WHERE c.attivo = 1
    GROUP BY c.id_convoglio
    ORDER BY c.data_creazione DESC
");

// Materiale rotabile disponibile
$materiale_disponibile = $conn->query("
    SELECT 
        m.*,
        t.nome as tipo_nome,
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM COMPOSIZIONE_CONVOGLIO cc
                JOIN CONVOGLIO c ON cc.id_convoglio = c.id_convoglio
                WHERE cc.id_materiale = m.id_materiale AND c.attivo = 1
            ) THEN 'IN_USO'
            ELSE 'DISPONIBILE'
        END as stato_utilizzo
    FROM MATERIALE_ROTABILE m
    JOIN TIPO_MATERIALE t ON m.id_tipo = t.id_tipo
    ORDER BY t.nome, m.sigla
");

// Lista treni
$treni = $conn->query("
    SELECT 
        t.*,
        c.nome as convoglio_nome,
        sp.nome as stazione_partenza,
        sa.nome as stazione_arrivo,
        COUNT(DISTINCT p.id_prenotazione) as tot_prenotazioni
    FROM TRENO t
    JOIN CONVOGLIO c ON t.id_convoglio = c.id_convoglio
    JOIN STAZIONE sp ON t.id_stazione_partenza = sp.id_stazione
    JOIN STAZIONE sa ON t.id_stazione_arrivo = sa.id_stazione
    LEFT JOIN PRENOTAZIONE p ON t.id_treno = p.id_treno AND p.stato != 'ANNULLATA'
    WHERE t.stato != 'CANCELLATO'
    GROUP BY t.id_treno
    ORDER BY t.data_partenza DESC, t.numero_treno
");

// Richieste admin
$richieste = $conn->query("
    SELECT 
        r.*,
        u.nome as admin_nome,
        t.numero_treno
    FROM RICHIESTA_ADMIN r
    JOIN UTENTE u ON r.id_utente = u.id_utente
    LEFT JOIN TRENO t ON r.id_treno = t.id_treno
    ORDER BY 
        CASE WHEN r.stato = 'IN_ATTESA' THEN 0 ELSE 1 END,
        r.data_richiesta DESC
    LIMIT 20
");

// Stazioni per form
$stazioni = $conn->query("SELECT * FROM STAZIONE ORDER BY km_progressivo");
$stazioni_form = $conn->query("SELECT * FROM STAZIONE ORDER BY km_progressivo");

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backoffice Esercizio - SFT</title>
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
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
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
            color: #28a745;
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
            color: #28a745;
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
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        .btn-sm {
            padding: 0.3rem 1rem;
            font-size: 0.85rem;
        }
        
        /* Navigazione Tab */
        .tabs {
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .tab-link {
            padding: 1rem 2rem;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
        }
        
        .tab-link:hover {
            background: #f8f9fa;
        }
        
        .tab-link.active {
            background: #28a745;
            color: white;
            border-bottom-color: #1e7e34;
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
            color: #28a745;
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
            border-bottom: 3px solid #28a745;
        }
        
        /* Tabelle */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        thead {
            background: #28a745;
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
            gap: 1.5rem;
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
            border-color: #28a745;
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
        
        /* Materiale Selector */
        .materiale-selector {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 1rem;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .materiale-item {
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 3px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .materiale-item input[type="checkbox"] {
            width: auto;
        }
        
        .materiale-item.disabled {
            opacity: 0.5;
            background: #f5f5f5;
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
            
            .tabs {
                flex-direction: column;
            }
            
            .tab-link {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <h1>🔧 Backoffice Esercizio SFT</h1>
                <div class="user-info">
                    <span>👤 <?php echo htmlspecialchars($user_name); ?></span>
                    <a href="../logout.php" class="btn btn-secondary">Esci</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Navigazione Tab -->
    <div class="tabs">
        <a href="?sezione=dashboard" class="tab-link <?php echo $sezione == 'dashboard' ? 'active' : ''; ?>">
            Dashboard
        </a>
        <a href="?sezione=convogli" class="tab-link <?php echo $sezione == 'convogli' ? 'active' : ''; ?>">
            Gestione Convogli
        </a>
        <a href="?sezione=treni" class="tab-link <?php echo $sezione == 'treni' ? 'active' : ''; ?>">
            Gestione Treni
        </a>
        <a href="?sezione=richieste" class="tab-link <?php echo $sezione == 'richieste' ? 'active' : ''; ?>">
            Richieste Admin <?php if($stats['richieste_attesa'] > 0): ?><span class="badge badge-warning"><?php echo $stats['richieste_attesa']; ?></span><?php endif; ?>
        </a>
    </div>

    <main>
        <div class="container">
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

            <?php if ($sezione == 'dashboard'): ?>
                <!-- ========================================== -->
                <!-- DASHBOARD -->
                <!-- ========================================== -->
                <div class="page-title">
                    <h2>📊 Dashboard Operativa</h2>
                    <p>Panoramica generale del sistema</p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">🚂</div>
                        <div class="stat-value"><?php echo $stats['treni_programmati']; ?></div>
                        <div class="stat-label">Treni Programmati</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">🔧</div>
                        <div class="stat-value"><?php echo $stats['convogli_disponibili']; ?></div>
                        <div class="stat-label">Convogli Attivi</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">🚃</div>
                        <div class="stat-value"><?php echo $stats['materiale_totale']; ?></div>
                        <div class="stat-label">Materiale Rotabile</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">📬</div>
                        <div class="stat-value"><?php echo $stats['richieste_attesa']; ?></div>
                        <div class="stat-label">Richieste in Attesa</div>
                    </div>
                </div>

                <div class="section">
                    <h3>🔧 Convogli Attivi</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Composizione</th>
                                    <th>Posti Totali</th>
                                    <th>Treni che lo utilizzano</th>
                                    <th>Data Creazione</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $convogli->data_seek(0);
                                if ($convogli->num_rows > 0): 
                                    while ($conv = $convogli->fetch_assoc()): 
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($conv['nome']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($conv['composizione']); ?></td>
                                        <td><?php echo $conv['posti_totali']; ?> posti</td>
                                        <td><?php echo $conv['treni_utilizzanti']; ?> treni</td>
                                        <td><?php echo date('d/m/Y', strtotime($conv['data_creazione'])); ?></td>
                                    </tr>
                                <?php 
                                    endwhile;
                                else: 
                                ?>
                                    <tr>
                                        <td colspan="5" class="text-center">Nessun convoglio creato</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($sezione == 'convogli'): ?>
                <!-- ========================================== -->
                <!-- GESTIONE CONVOGLI -->
                <!-- ========================================== -->
                <div class="page-title">
                    <h2>🔧 Gestione Convogli</h2>
                    <p>Crea e gestisci i convogli assemblando materiale rotabile</p>
                </div>

                <div class="section">
                    <h3>➕ Crea Nuovo Convoglio</h3>
                    <form method="POST">
                        <div class="form-group">
                            <label>Nome Convoglio *</label>
                            <input type="text" name="nome_convoglio" required placeholder="es. Garibaldi Express">
                        </div>
                        
                        <div class="form-group">
                            <label>Seleziona Materiale Rotabile *</label>
                            <p style="font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">
                                Seleziona almeno un materiale. Il materiale in grigio è già utilizzato in altri convogli.
                            </p>
                            <div class="materiale-selector">
                                <?php 
                                $materiale_disponibile->data_seek(0);
                                while ($mat = $materiale_disponibile->fetch_assoc()): 
                                    $in_uso = $mat['stato_utilizzo'] == 'IN_USO';
                                ?>
                                    <div class="materiale-item <?php echo $in_uso ? 'disabled' : ''; ?>">
                                        <input 
                                            type="checkbox" 
                                            name="materiale[]" 
                                            value="<?php echo $mat['id_materiale']; ?>"
                                            <?php echo $in_uso ? 'disabled' : ''; ?>
                                        >
                                        <strong><?php echo htmlspecialchars($mat['sigla']); ?></strong> - 
                                        <?php echo htmlspecialchars($mat['nome'] ?? $mat['tipo_nome']); ?>
                                        (<?php echo $mat['posti_sedere']; ?> posti)
                                        <?php if ($in_uso): ?>
                                            <span class="badge badge-warning">IN USO</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                        
                        <button type="submit" name="crea_convoglio" class="btn btn-success">
                            🔧 Crea Convoglio
                        </button>
                    </form>
                </div>

                <div class="section">
                    <h3>📋 Convogli Esistenti</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Composizione</th>
                                    <th>Posti</th>
                                    <th>Treni</th>
                                    <th>Stato</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $convogli->data_seek(0);
                                if ($convogli->num_rows > 0): 
                                    while ($conv = $convogli->fetch_assoc()): 
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($conv['nome']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($conv['composizione']); ?></td>
                                        <td><?php echo $conv['posti_totali']; ?></td>
                                        <td><?php echo $conv['treni_utilizzanti']; ?></td>
                                        <td>
                                            <span class="badge badge-success">ATTIVO</span>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($conv['data_creazione'])); ?></td>
                                    </tr>
                                <?php 
                                    endwhile;
                                else: 
                                ?>
                                    <tr>
                                        <td colspan="6" class="text-center">Nessun convoglio disponibile</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($sezione == 'treni'): ?>
                <!-- ========================================== -->
                <!-- GESTIONE TRENI -->
                <!-- ========================================== -->
                <div class="page-title">
                    <h2>🚂 Gestione Treni e Orari</h2>
                    <p>Programma nuove corse con generazione automatica degli orari</p>
                </div>

                <div class="section">
                    <h3>➕ Crea Nuovo Treno</h3>
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Numero Treno *</label>
                                <input type="text" name="numero_treno" required placeholder="es. 101">
                            </div>
                            
                            <div class="form-group">
                                <label>Convoglio *</label>
                                <select name="id_convoglio" required>
                                    <option value="">Seleziona...</option>
                                    <?php 
                                    $convogli->data_seek(0);
                                    while ($conv = $convogli->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $conv['id_convoglio']; ?>">
                                            <?php echo htmlspecialchars($conv['nome']); ?> (<?php echo $conv['posti_totali']; ?> posti)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Data Partenza *</label>
                                <input type="date" name="data_partenza" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Orario Partenza *</label>
                                <input type="time" name="ora_partenza" required>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Stazione Partenza *</label>
                                <select name="id_stazione_partenza" required>
                                    <option value="">Seleziona...</option>
                                    <?php while ($st = $stazioni->fetch_assoc()): ?>
                                        <option value="<?php echo $st['id_stazione']; ?>">
                                            <?php echo htmlspecialchars($st['nome']); ?> (km <?php echo $st['km_progressivo']; ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Stazione Arrivo *</label>
                                <select name="id_stazione_arrivo" required>
                                    <option value="">Seleziona...</option>
                                    <?php 
                                    $stazioni_form->data_seek(0);
                                    while ($st = $stazioni_form->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $st['id_stazione']; ?>">
                                            <?php echo htmlspecialchars($st['nome']); ?> (km <?php echo $st['km_progressivo']; ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div style="background: #e7f3ff; padding: 1rem; border-radius: 5px; margin-bottom: 1rem;">
                            <p style="margin: 0; font-size: 0.9rem;">
                                ℹ️ <strong>Nota:</strong> Gli orari delle fermate intermedie verranno calcolati automaticamente 
                                in base alla velocità di 50 km/h e alle distanze tra le stazioni.
                            </p>
                        </div>
                        
                        <button type="submit" name="crea_treno" class="btn btn-success">
                            🚂 Crea Treno
                        </button>
                    </form>
                </div>

                <div class="section">
                    <h3>📋 Treni Programmati</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Treno</th>
                                    <th>Data</th>
                                    <th>Tratta</th>
                                    <th>Convoglio</th>
                                    <th>Direzione</th>
                                    <th>Prenotazioni</th>
                                    <th>Stato</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($treni->num_rows > 0): 
                                    while ($treno = $treni->fetch_assoc()): 
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($treno['numero_treno']); ?></strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($treno['data_partenza'])); ?></td>
                                        <td><?php echo htmlspecialchars($treno['stazione_partenza']); ?> → <?php echo htmlspecialchars($treno['stazione_arrivo']); ?></td>
                                        <td><?php echo htmlspecialchars($treno['convoglio_nome']); ?></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?php echo $treno['direzione'] == 'NORD' ? '⬆️ NORD' : '⬇️ SUD'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $treno['tot_prenotazioni']; ?></td>
                                        <td>
                                            <span class="badge badge-success"><?php echo $treno['stato']; ?></span>
                                        </td>
                                        <td>
                                            <?php if ($treno['tot_prenotazioni'] == 0): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Confermi la cancellazione del treno <?php echo htmlspecialchars($treno['numero_treno']); ?>?');">
                                                    <input type="hidden" name="id_treno" value="<?php echo $treno['id_treno']; ?>">
                                                    <button type="submit" name="cancella_treno" class="btn btn-danger btn-sm">
                                                        🗑️ Cancella
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #999; font-size: 0.85rem;">Ha prenotazioni</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php 
                                    endwhile;
                                else: 
                                ?>
                                    <tr>
                                        <td colspan="8" class="text-center">Nessun treno programmato</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($sezione == 'richieste'): ?>
                <!-- ========================================== -->
                <!-- GESTIONE RICHIESTE ADMIN -->
                <!-- ========================================== -->
                <div class="page-title">
                    <h2>📬 Gestione Richieste Amministrative</h2>
                    <p>Approva o rifiuta le richieste del backoffice amministrativo</p>
                </div>

                <div class="section">
                    <h3>📋 Richieste</h3>
                    
                    <?php if ($richieste->num_rows > 0): ?>
                        <?php while ($richiesta = $richieste->fetch_assoc()): ?>
                            <div style="border: 1px solid #ddd; border-radius: 5px; padding: 1.5rem; margin-bottom: 1.5rem; 
                                        <?php echo $richiesta['stato'] == 'IN_ATTESA' ? 'border-left: 5px solid #ffc107;' : ''; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
                                    <div>
                                        <h4 style="margin-bottom: 0.5rem;">
                                            <?php echo $richiesta['tipo_richiesta'] == 'TRENO_STRAORDINARIO' ? '➕ Treno Straordinario' : '🗑️ Cancellazione Treno'; ?>
                                        </h4>
                                        <p style="color: #666; font-size: 0.9rem; margin: 0;">
                                            Richiesta da: <strong><?php echo htmlspecialchars($richiesta['admin_nome']); ?></strong> | 
                                            <?php echo date('d/m/Y H:i', strtotime($richiesta['data_richiesta'])); ?>
                                        </p>
                                    </div>
                                    <span class="badge <?php 
                                        echo $richiesta['stato'] == 'IN_ATTESA' ? 'badge-warning' : 
                                             ($richiesta['stato'] == 'APPROVATA' ? 'badge-success' : 'badge-danger'); 
                                    ?>">
                                        <?php echo $richiesta['stato']; ?>
                                    </span>
                                </div>
                                
                                <div style="background: #f8f9fa; padding: 1rem; border-radius: 5px; margin-bottom: 1rem;">
                                    <p style="margin: 0;"><?php echo htmlspecialchars($richiesta['descrizione']); ?></p>
                                </div>
                                
                                <?php if ($richiesta['stato'] == 'IN_ATTESA'): ?>
                                    <form method="POST" style="display: flex; gap: 1rem; align-items: end; flex-wrap: wrap;">
                                        <input type="hidden" name="id_richiesta" value="<?php echo $richiesta['id_richiesta']; ?>">
                                        
                                        <div class="form-group" style="flex: 1; min-width: 300px; margin-bottom: 0;">
                                            <label>Note Risposta</label>
                                            <textarea name="note_risposta" placeholder="Inserisci eventuali note..." style="min-height: 60px;"></textarea>
                                        </div>
                                        
                                        <div style="display: flex; gap: 0.5rem;">
                                            <button type="submit" name="gestisci_richiesta" value="submit" onclick="this.form.azione.value='APPROVATA'" class="btn btn-success btn-sm">
                                                ✅ Approva
                                            </button>
                                            <button type="submit" name="gestisci_richiesta" value="submit" onclick="this.form.azione.value='RIFIUTATA'" class="btn btn-danger btn-sm">
                                                ❌ Rifiuta
                                            </button>
                                            <input type="hidden" name="azione" value="">
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <?php if ($richiesta['note_risposta']): ?>
                                        <div style="border-top: 1px solid #ddd; padding-top: 1rem; margin-top: 1rem;">
                                            <p style="margin: 0; font-size: 0.9rem; color: #666;">
                                                <strong>Risposta:</strong> <?php echo htmlspecialchars($richiesta['note_risposta']); ?>
                                            </p>
                                            <p style="margin: 0.5rem 0 0 0; font-size: 0.85rem; color: #999;">
                                                Gestita il <?php echo date('d/m/Y H:i', strtotime($richiesta['data_gestione'])); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <p>Nessuna richiesta presente</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
    </main>
</body>
</html>
<?php
closeDBConnection($conn);
?>