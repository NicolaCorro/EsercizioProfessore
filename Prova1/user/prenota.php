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

// Determina lo step corrente
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// Variabili per i diversi step
$stazione_partenza = $_GET['partenza'] ?? '';
$stazione_arrivo = $_GET['arrivo'] ?? '';
$data_viaggio = $_GET['data'] ?? date('Y-m-d', strtotime('+1 day'));
$id_treno = $_GET['treno'] ?? '';
$id_posto = $_POST['posto'] ?? '';

// Messaggi di errore
$error = '';
$success = '';

// STEP 3: Conferma prenotazione
if ($step == 3 && $_SERVER['REQUEST_METHOD'] == 'POST' && $id_posto) {
    
    // Verifica che il treno e le stazioni siano validi
    if (!$id_treno || !$stazione_partenza || !$stazione_arrivo) {
        $error = "Dati mancanti. Riprova.";
        $step = 1;
    } else {
        // Verifica che il posto sia ancora disponibile
        $stmt = $conn->prepare("
            SELECT COUNT(*) as occupato
            FROM PRENOTAZIONE
            WHERE id_treno = ? 
            AND id_posto = ?
            AND stato IN ('IN_ATTESA_PAGAMENTO', 'CONFERMATA')
        ");
        $stmt->bind_param("ii", $id_treno, $id_posto);
        $stmt->execute();
        $check = $stmt->get_result()->fetch_assoc();
        
        if ($check['occupato'] > 0) {
            $error = "Il posto selezionato è stato appena prenotato. Seleziona un altro posto.";
            $step = 2;
        } else {
            // Recupera informazioni complete per il pagamento
            $stmt = $conn->prepare("
                SELECT 
                    t.numero_treno,
                    t.data_partenza,
                    sp.nome as nome_partenza,
                    sa.nome as nome_arrivo,
                    sp.km_progressivo as km_partenza,
                    sa.km_progressivo as km_arrivo
                FROM TRENO t
                JOIN STAZIONE sp ON sp.id_stazione = ?
                JOIN STAZIONE sa ON sa.id_stazione = ?
                WHERE t.id_treno = ?
            ");
            $stmt->bind_param("iii", $stazione_partenza, $stazione_arrivo, $id_treno);
            $stmt->execute();
            $info_viaggio = $stmt->get_result()->fetch_assoc();
            
            if (!$info_viaggio) {
                $error = "Errore nel recupero delle informazioni del viaggio.";
                $step = 1;
            } else {
                // Calcola la distanza e il prezzo
                $km_percorsi = abs($info_viaggio['km_arrivo'] - $info_viaggio['km_partenza']);
                $importo = $km_percorsi * 0.50;
                
                // Genera codice prenotazione univoco
                $codice_prenotazione = 'SFT' . date('Ymd') . sprintf('%06d', rand(1, 999999));
                
                // Verifica unicità codice
                $stmt = $conn->prepare("SELECT COUNT(*) as esiste FROM PRENOTAZIONE WHERE codice_prenotazione = ?");
                $stmt->bind_param("s", $codice_prenotazione);
                $stmt->execute();
                $check_codice = $stmt->get_result()->fetch_assoc();
                
                while ($check_codice['esiste'] > 0) {
                    $codice_prenotazione = 'SFT' . date('Ymd') . sprintf('%06d', rand(1, 999999));
                    $stmt->bind_param("s", $codice_prenotazione);
                    $stmt->execute();
                    $check_codice = $stmt->get_result()->fetch_assoc();
                }
                
                // Inserisci prenotazione con stato IN_ATTESA_PAGAMENTO
                $stmt = $conn->prepare("
                    INSERT INTO PRENOTAZIONE 
                    (id_utente, id_treno, id_stazione_partenza, id_stazione_arrivo, id_posto, codice_prenotazione, stato)
                    VALUES (?, ?, ?, ?, ?, ?, 'IN_ATTESA_PAGAMENTO')
                ");
                $stmt->bind_param("iiiiss", $user_id, $id_treno, $stazione_partenza, $stazione_arrivo, $id_posto, $codice_prenotazione);
                
                if ($stmt->execute()) {
                    $id_prenotazione = $conn->insert_id;
                    
                    // Prepara descrizione per Pay Steam
                    $descrizione_pagamento = sprintf(
                        "Biglietto treno SFT - Treno %s del %s da %s a %s",
                        $info_viaggio['numero_treno'],
                        date('d/m/Y', strtotime($info_viaggio['data_partenza'])),
                        $info_viaggio['nome_partenza'],
                        $info_viaggio['nome_arrivo']
                    );
                    
                    // =====================================================
                    // INTEGRAZIONE PAY STEAM - Richiesta Pagamento
                    // =====================================================
                    
                    // Chiama l'API di Pay Steam
                    $risposta_paysteam = richiestaPagamentoPaySteam(
                        $importo, 
                        $descrizione_pagamento, 
                        $codice_prenotazione
                    );
                    
                    if ($risposta_paysteam['success']) {
                        // Pagamento richiesto con successo
                        $codice_transazione_paysteam = $risposta_paysteam['codice_transazione'];
                        $url_autorizzazione = $risposta_paysteam['url_autorizzazione'];
                        
                        // Crea il biglietto con stato IN_ATTESA_PAGAMENTO
                        $stmt = $conn->prepare("
                            INSERT INTO BIGLIETTO 
                            (id_prenotazione, importo, codice_pagamento, stato_pagamento)
                            VALUES (?, ?, ?, 'IN_ATTESA_PAGAMENTO')
                        ");
                        $stmt->bind_param("ids", $id_prenotazione, $importo, $codice_transazione_paysteam);
                        
                        if ($stmt->execute()) {
                            // Tutto OK - Reindirizza l'utente su Pay Steam per autorizzare il pagamento
                            header("Location: " . $url_autorizzazione);
                            exit();
                        } else {
                            $error = "Errore durante la creazione del biglietto.";
                        }
                        
                    } else {
                        // Errore nella richiesta a Pay Steam
                        $error = "Errore nel sistema di pagamento: " . ($risposta_paysteam['errore'] ?? 'Errore sconosciuto');
                        
                        // Annulla la prenotazione
                        $stmt = $conn->prepare("UPDATE PRENOTAZIONE SET stato = 'ANNULLATA' WHERE id_prenotazione = ?");
                        $stmt->bind_param("i", $id_prenotazione);
                        $stmt->execute();
                    }
                    
                } else {
                    $error = "Errore durante la creazione della prenotazione.";
                }
            }
        }
    }
}

// Recupera lista stazioni per lo step 1
$stazioni = $conn->query("SELECT * FROM STAZIONE ORDER BY km_progressivo");

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prenota Biglietto - SFT</title>
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
        
        .btn-success {
            background: #28a745;
            color: white;
            padding: 1rem 2rem;
            font-size: 1rem;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-back {
            background: #6c757d;
            color: white;
        }
        
        .btn-back:hover {
            background: #5a6268;
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
        
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .progress-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e0e0e0;
            z-index: 0;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #999;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: bold;
        }
        
        .step.active .step-circle {
            background: #667eea;
            color: white;
        }
        
        .step.completed .step-circle {
            background: #28a745;
            color: white;
        }
        
        .step-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        .step.active .step-label {
            color: #667eea;
            font-weight: bold;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 600;
        }
        
        select, input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        select:focus, input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .treni-grid {
            display: grid;
            gap: 1rem;
        }
        
        .treno-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 1.5rem;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .treno-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        }
        
        .treno-card.selected {
            border-color: #667eea;
            background: #f8f9ff;
        }
        
        .treno-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .treno-numero {
            font-size: 1.2rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .treno-convoglio {
            color: #666;
            font-size: 0.9rem;
        }
        
        .treno-orari {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .orario-box {
            text-align: center;
        }
        
        .orario-label {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 0.25rem;
        }
        
        .orario-time {
            font-size: 1.5rem;
            font-weight: bold;
            color: #333;
        }
        
        .orario-stazione {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.25rem;
        }
        
        .treno-arrow {
            color: #667eea;
            font-size: 2rem;
            align-self: center;
        }
        
        .treno-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: #666;
        }
        
        .posti-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .posto-btn {
            padding: 1rem;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        
        .posto-btn:hover:not(:disabled) {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(102, 126, 234, 0.2);
        }
        
        .posto-btn.selected {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .posto-btn:disabled {
            background: #f0f0f0;
            color: #999;
            cursor: not-allowed;
            opacity: 0.5;
        }
        
        .posto-numero {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }
        
        .posto-tipo {
            font-size: 0.8rem;
        }
        
        .posto-materiale {
            font-size: 0.75rem;
            color: #666;
            margin-top: 0.25rem;
        }
        
        .riepilogo {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 1.5rem;
        }
        
        .riepilogo h3 {
            color: #667eea;
            margin-bottom: 1rem;
        }
        
        .riepilogo-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .riepilogo-item:last-child {
            border-bottom: none;
            font-weight: bold;
            font-size: 1.2rem;
            color: #667eea;
            margin-top: 0.5rem;
            padding-top: 1rem;
            border-top: 2px solid #667eea;
        }
        
        .actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: flex-end;
        }
        
        .legenda {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .legenda-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .legenda-box {
            width: 30px;
            height: 30px;
            border-radius: 3px;
            border: 2px solid #e0e0e0;
        }
        
        .legenda-box.disponibile {
            background: white;
        }
        
        .legenda-box.occupato {
            background: #f0f0f0;
            opacity: 0.5;
        }
        
        .legenda-box.selezionato {
            background: #667eea;
            border-color: #667eea;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <h1>Prenota Biglietto</h1>
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
            <li><a href="prenota.php" class="active">Prenota Biglietto</a></li>
            <li><a href="prenotazioni.php">Le Mie Prenotazioni</a></li>
            <li><a href="profilo.php">Il Mio Profilo</a></li>
        </ul>
    </nav>

    <main>
        <div class="container">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <strong>Errore:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Successo!</strong> <?php echo htmlspecialchars($success); ?>
                    <br><br>
                    Verrai reindirizzato alla dashboard tra pochi secondi...
                </div>
            <?php endif; ?>

            <div class="section">
                <div class="progress-steps">
                    <div class="step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">
                        <div class="step-circle">1</div>
                        <div class="step-label">Seleziona Tratta</div>
                    </div>
                    <div class="step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">
                        <div class="step-circle">2</div>
                        <div class="step-label">Scegli Treno e Posto</div>
                    </div>
                    <div class="step <?php echo $step >= 3 ? 'active' : ''; ?>">
                        <div class="step-circle">3</div>
                        <div class="step-label">Conferma</div>
                    </div>
                </div>

                <?php if ($step == 1 && !$success): ?>
                    <!-- STEP 1: Selezione tratta e data -->
                    <h2>Seleziona la tua tratta</h2>
                    
                    <form method="GET" action="prenota.php">
                        <input type="hidden" name="step" value="2">
                        
                        <div class="form-group">
                            <label for="partenza">Stazione di Partenza</label>
                            <select name="partenza" id="partenza" required>
                                <option value="">-- Seleziona stazione --</option>
                                <?php 
                                $stazioni->data_seek(0);
                                while ($s = $stazioni->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $s['id_stazione']; ?>" 
                                            <?php echo $stazione_partenza == $s['id_stazione'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['nome']); ?> (km <?php echo $s['km_progressivo']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="arrivo">Stazione di Arrivo</label>
                            <select name="arrivo" id="arrivo" required>
                                <option value="">-- Seleziona stazione --</option>
                                <?php 
                                $stazioni->data_seek(0);
                                while ($s = $stazioni->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $s['id_stazione']; ?>"
                                            <?php echo $stazione_arrivo == $s['id_stazione'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['nome']); ?> (km <?php echo $s['km_progressivo']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="data">Data del Viaggio</label>
                            <input type="date" name="data" id="data" 
                                   value="<?php echo htmlspecialchars($data_viaggio); ?>" 
                                   min="<?php echo date('Y-m-d'); ?>" 
                                   required>
                        </div>
                        
                        <div class="actions">
                            <a href="index.php" class="btn btn-back">Annulla</a>
                            <button type="submit" class="btn btn-success">Continua →</button>
                        </div>
                    </form>

                <?php elseif ($step == 2 && !$success): ?>
                    <!-- STEP 2: Selezione treno e posto -->
                    <?php
                    // Recupera i treni che fermano in entrambe le stazioni nella data selezionata
                    $stmt = $conn->prepare("
                        SELECT DISTINCT
                            t.id_treno,
                            t.numero_treno,
                            t.data_partenza,
                            c.nome as convoglio_nome,
                            c.posti_totali,
                            fp.orario_partenza,
                            fa.orario_arrivo,
                            sp.nome as stazione_partenza_nome,
                            sa.nome as stazione_arrivo_nome,
                            sp.km_progressivo as km_partenza,
                            sa.km_progressivo as km_arrivo
                        FROM TRENO t
                        JOIN CONVOGLIO c ON t.id_convoglio = c.id_convoglio
                        JOIN FERMATA fp ON t.id_treno = fp.id_treno AND fp.id_stazione = ?
                        JOIN FERMATA fa ON t.id_treno = fa.id_treno AND fa.id_stazione = ?
                        JOIN STAZIONE sp ON fp.id_stazione = sp.id_stazione
                        JOIN STAZIONE sa ON fa.id_stazione = sa.id_stazione
                        WHERE DATE(t.data_partenza) = ?
                        AND fp.ordine_fermata < fa.ordine_fermata
                        ORDER BY fp.orario_partenza
                    ");
                    $stmt->bind_param("iis", $stazione_partenza, $stazione_arrivo, $data_viaggio);
                    $stmt->execute();
                    $treni_disponibili = $stmt->get_result();
                    
                    if ($treni_disponibili->num_rows == 0) {
                        echo '<div class="alert alert-danger">';
                        echo '<strong>Attenzione:</strong> Non ci sono treni disponibili per la tratta selezionata in questa data.';
                        echo '</div>';
                        echo '<div class="actions">';
                        echo '<a href="prenota.php?step=1" class="btn btn-back">← Torna indietro</a>';
                        echo '</div>';
                    } else {
                        
                        // Se è stato selezionato un treno, mostra i posti
                        if ($id_treno) {
                            // Recupera informazioni del treno selezionato
                            $stmt = $conn->prepare("
                                SELECT 
                                    t.*,
                                    c.nome as convoglio_nome,
                                    fp.orario_partenza,
                                    fa.orario_arrivo,
                                    sp.nome as stazione_partenza_nome,
                                    sa.nome as stazione_arrivo_nome,
                                    sp.km_progressivo as km_partenza,
                                    sa.km_progressivo as km_arrivo
                                FROM TRENO t
                                JOIN CONVOGLIO c ON t.id_convoglio = c.id_convoglio
                                JOIN FERMATA fp ON t.id_treno = fp.id_treno AND fp.id_stazione = ?
                                JOIN FERMATA fa ON t.id_treno = fa.id_treno AND fa.id_stazione = ?
                                JOIN STAZIONE sp ON fp.id_stazione = sp.id_stazione
                                JOIN STAZIONE sa ON fa.id_stazione = sa.id_stazione
                                WHERE t.id_treno = ?
                            ");
                            $stmt->bind_param("iii", $stazione_partenza, $stazione_arrivo, $id_treno);
                            $stmt->execute();
                            $treno_info = $stmt->get_result()->fetch_assoc();
                            
                            // Calcola prezzo
                            $km_percorsi = abs($treno_info['km_arrivo'] - $treno_info['km_partenza']);
                            $prezzo = $km_percorsi * 0.50;
                            
                            // Recupera tutti i posti del convoglio
                            $stmt = $conn->prepare("
                                SELECT 
                                    p.id_posto,
                                    p.numero_posto,
                                    p.tipo,
                                    m.sigla as materiale_sigla,
                                    m.nome as materiale_nome,
                                    CASE 
                                        WHEN EXISTS (
                                            SELECT 1 FROM PRENOTAZIONE pr 
                                            WHERE pr.id_posto = p.id_posto 
                                            AND pr.id_treno = ?
                                            AND pr.stato IN ('IN_ATTESA_PAGAMENTO', 'CONFERMATA')
                                        ) THEN 1
                                        ELSE 0
                                    END as occupato
                                FROM POSTO p
                                JOIN MATERIALE_ROTABILE m ON p.id_materiale = m.id_materiale
                                JOIN COMPOSIZIONE_CONVOGLIO cc ON m.id_materiale = cc.id_materiale
                                JOIN TRENO t ON cc.id_convoglio = t.id_convoglio
                                WHERE t.id_treno = ?
                                ORDER BY cc.posizione, p.numero_posto
                            ");
                            $stmt->bind_param("ii", $id_treno, $id_treno);
                            $stmt->execute();
                            $posti = $stmt->get_result();
                            ?>
                            
                            <h2>Seleziona il tuo posto</h2>
                            
                            <div class="riepilogo">
                                <h3>Riepilogo Viaggio</h3>
                                <div class="riepilogo-item">
                                    <span>Treno:</span>
                                    <span><strong><?php echo htmlspecialchars($treno_info['numero_treno']); ?></strong></span>
                                </div>
                                <div class="riepilogo-item">
                                    <span>Convoglio:</span>
                                    <span><?php echo htmlspecialchars($treno_info['convoglio_nome']); ?></span>
                                </div>
                                <div class="riepilogo-item">
                                    <span>Data:</span>
                                    <span><?php echo date('d/m/Y', strtotime($treno_info['data_partenza'])); ?></span>
                                </div>
                                <div class="riepilogo-item">
                                    <span>Partenza:</span>
                                    <span><?php echo htmlspecialchars($treno_info['stazione_partenza_nome']); ?> 
                                          (<?php echo date('H:i', strtotime($treno_info['orario_partenza'])); ?>)</span>
                                </div>
                                <div class="riepilogo-item">
                                    <span>Arrivo:</span>
                                    <span><?php echo htmlspecialchars($treno_info['stazione_arrivo_nome']); ?> 
                                          (<?php echo date('H:i', strtotime($treno_info['orario_arrivo'])); ?>)</span>
                                </div>
                                <div class="riepilogo-item">
                                    <span>Distanza:</span>
                                    <span><?php echo number_format($km_percorsi, 2); ?> km</span>
                                </div>
                                <div class="riepilogo-item">
                                    <span>TOTALE:</span>
                                    <span>€ <?php echo number_format($prezzo, 2); ?></span>
                                </div>
                            </div>
                            
                            <div class="legenda">
                                <div class="legenda-item">
                                    <div class="legenda-box disponibile"></div>
                                    <span>Disponibile</span>
                                </div>
                                <div class="legenda-item">
                                    <div class="legenda-box occupato"></div>
                                    <span>Occupato</span>
                                </div>
                                <div class="legenda-item">
                                    <div class="legenda-box selezionato"></div>
                                    <span>Selezionato</span>
                                </div>
                            </div>
                            
                            <form method="POST" action="prenota.php?step=3&treno=<?php echo $id_treno; ?>&partenza=<?php echo $stazione_partenza; ?>&arrivo=<?php echo $stazione_arrivo; ?>&data=<?php echo $data_viaggio; ?>" id="postoForm">
                                <div class="posti-grid">
                                    <?php while ($posto = $posti->fetch_assoc()): ?>
                                        <button type="button" 
                                                class="posto-btn <?php echo $posto['occupato'] ? '' : 'selectable'; ?>" 
                                                data-posto="<?php echo $posto['id_posto']; ?>"
                                                <?php echo $posto['occupato'] ? 'disabled' : ''; ?>>
                                            <div class="posto-numero">
                                                <?php echo $posto['numero_posto']; ?>
                                            </div>
                                            <div class="posto-tipo">
                                                <?php echo $posto['tipo'] == 'FINESTRINO' ? '🪟' : '🚶'; ?>
                                            </div>
                                            <div class="posto-materiale">
                                                <?php echo htmlspecialchars($posto['materiale_sigla']); ?>
                                            </div>
                                        </button>
                                    <?php endwhile; ?>
                                </div>
                                
                                <input type="hidden" name="posto" id="postoSelezionato" required>
                                
                                <div class="actions">
                                    <a href="prenota.php?step=2&partenza=<?php echo $stazione_partenza; ?>&arrivo=<?php echo $stazione_arrivo; ?>&data=<?php echo $data_viaggio; ?>" 
                                       class="btn btn-back">← Cambia treno</a>
                                    <button type="submit" class="btn btn-success" id="confermaBtn" disabled>
                                        Conferma Prenotazione →
                                    </button>
                                </div>
                            </form>
                            
                            <script>
                                // Gestione selezione posto
                                const posti = document.querySelectorAll('.posto-btn.selectable');
                                const postoInput = document.getElementById('postoSelezionato');
                                const confermaBtn = document.getElementById('confermaBtn');
                                
                                posti.forEach(posto => {
                                    posto.addEventListener('click', function() {
                                        // Deseleziona tutti
                                        posti.forEach(p => p.classList.remove('selected'));
                                        
                                        // Seleziona questo
                                        this.classList.add('selected');
                                        postoInput.value = this.dataset.posto;
                                        confermaBtn.disabled = false;
                                    });
                                });
                            </script>
                            
                            <?php
                        } else {
                            // Mostra lista treni disponibili
                            ?>
                            <h2>Treni Disponibili</h2>
                            <p style="color: #666; margin-bottom: 1.5rem;">
                                Seleziona un treno per visualizzare i posti disponibili
                            </p>
                            
                            <div class="treni-grid">
                                <?php while ($treno = $treni_disponibili->fetch_assoc()): 
                                    $km = abs($treno['km_arrivo'] - $treno['km_partenza']);
                                    $prezzo = $km * 0.50;
                                ?>
                                    <a href="prenota.php?step=2&treno=<?php echo $treno['id_treno']; ?>&partenza=<?php echo $stazione_partenza; ?>&arrivo=<?php echo $stazione_arrivo; ?>&data=<?php echo $data_viaggio; ?>" 
                                       style="text-decoration: none; color: inherit;">
                                        <div class="treno-card">
                                            <div class="treno-header">
                                                <div>
                                                    <div class="treno-numero">
                                                        🚂 Treno <?php echo htmlspecialchars($treno['numero_treno']); ?>
                                                    </div>
                                                    <div class="treno-convoglio">
                                                        <?php echo htmlspecialchars($treno['convoglio_nome']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="treno-orari">
                                                <div class="orario-box">
                                                    <div class="orario-label">Partenza</div>
                                                    <div class="orario-time">
                                                        <?php echo date('H:i', strtotime($treno['orario_partenza'])); ?>
                                                    </div>
                                                    <div class="orario-stazione">
                                                        <?php echo htmlspecialchars($treno['stazione_partenza_nome']); ?>
                                                    </div>
                                                </div>
                                                
                                                <div class="treno-arrow">→</div>
                                                
                                                <div class="orario-box">
                                                    <div class="orario-label">Arrivo</div>
                                                    <div class="orario-time">
                                                        <?php echo date('H:i', strtotime($treno['orario_arrivo'])); ?>
                                                    </div>
                                                    <div class="orario-stazione">
                                                        <?php echo htmlspecialchars($treno['stazione_arrivo_nome']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="treno-info">
                                                <span>💺 <?php echo $treno['posti_totali']; ?> posti</span>
                                                <span>📏 <?php echo number_format($km, 2); ?> km</span>
                                                <span><strong>€ <?php echo number_format($prezzo, 2); ?></strong></span>
                                            </div>
                                        </div>
                                    </a>
                                <?php endwhile; ?>
                            </div>
                            
                            <div class="actions">
                                <a href="prenota.php?step=1" class="btn btn-back">← Cambia tratta</a>
                            </div>
                            <?php
                        }
                    }
                    ?>

                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
<?php
closeDBConnection($conn);
?>