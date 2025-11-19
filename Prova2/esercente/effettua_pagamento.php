<?php
require_once '../config.php';

// Verifica login e profilo ESERCENTE
requireProfile('ESERCENTE');

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

$errors = [];
$success = false;

// Gestione invio form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_destinatario = sanitize($_POST['email_destinatario'] ?? '');
    $importo = floatval($_POST['importo'] ?? 0);
    $descrizione = sanitize($_POST['descrizione'] ?? '');
    
    // Validazioni
    if (empty($email_destinatario)) {
        $errors[] = "Inserisci l'email del destinatario";
    }
    
    if ($importo <= 0) {
        $errors[] = "L'importo deve essere maggiore di zero";
    }
    
    if ($importo > $conto['saldo']) {
        $errors[] = "Saldo insufficiente. Disponibile: " . formatAmount($conto['saldo']);
    }
    
    if (empty($descrizione)) {
        $errors[] = "Inserisci una descrizione del pagamento";
    }
    
    // Verifica che il destinatario esista
    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT u.id_utente, u.nome, u.cognome, c.id_conto, c.saldo
            FROM utenti u
            JOIN conti c ON u.id_utente = c.id_utente
            WHERE u.email = ? AND u.attivo = 1
        ");
        $stmt->bind_param("s", $email_destinatario);
        $stmt->execute();
        $destinatario = $stmt->get_result()->fetch_assoc();
        
        if (!$destinatario) {
            $errors[] = "Destinatario non trovato o account non attivo";
        } elseif ($destinatario['id_utente'] == $_SESSION['user_id']) {
            $errors[] = "Non puoi inviare denaro a te stesso";
        }
    }
    
    // Se non ci sono errori, esegui il pagamento
    if (empty($errors)) {
        // Inizia transazione SQL
        $conn->begin_transaction();
        
        try {
            // 1. Crea la transazione
            $codice_transazione = generateTransactionCode();
            $stmt = $conn->prepare("
                INSERT INTO transazioni 
                (codice_transazione, id_esercente, id_cliente, importo, descrizione, 
                 url_chiamante, url_risposta, stato, data_richiesta, data_autorizzazione, data_completamento)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETATA', NOW(), NOW(), NOW())
            ");
            
            $url_interno = SITE_URL . '/esercente/effettua_pagamento.php';
            $stmt->bind_param("siidsss", 
                $codice_transazione,
                $_SESSION['user_id'],  // L'esercente è il mittente (diventa "esercente" nella transazione)
                $destinatario['id_utente'],  // Il destinatario
                $importo,
                $descrizione,
                $url_interno,
                $url_interno
            );
            $stmt->execute();
            $id_transazione = $conn->insert_id;
            
            // 2. Aggiorna saldi
            $nuovo_saldo_mittente = $conto['saldo'] - $importo;
            $nuovo_saldo_destinatario = $destinatario['saldo'] + $importo;
            
            $stmt = $conn->prepare("UPDATE conti SET saldo = ? WHERE id_conto = ?");
            
            // Aggiorna saldo mittente (esercente)
            $stmt->bind_param("di", $nuovo_saldo_mittente, $conto['id_conto']);
            $stmt->execute();
            
            // Aggiorna saldo destinatario
            $stmt->bind_param("di", $nuovo_saldo_destinatario, $destinatario['id_conto']);
            $stmt->execute();
            
            // 3. Registra movimenti
            $causale_uscita = "Pagamento a " . $destinatario['nome'] . " " . $destinatario['cognome'];
            $causale_entrata = "Pagamento ricevuto da " . $_SESSION['user_name'];
            
            $stmt = $conn->prepare("
                INSERT INTO movimenti 
                (id_conto, id_transazione, tipo, importo, causale, saldo_precedente, saldo_nuovo)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Movimento USCITA per mittente (esercente)
            $tipo_uscita = 'USCITA';
            $stmt->bind_param("iisdsdd", 
                $conto['id_conto'],
                $id_transazione,
                $tipo_uscita,
                $importo,
                $causale_uscita,
                $conto['saldo'],
                $nuovo_saldo_mittente
            );
            $stmt->execute();
            
            // Movimento ENTRATA per destinatario
            $tipo_entrata = 'ENTRATA';
            $stmt->bind_param("iisdsdd", 
                $destinatario['id_conto'],
                $id_transazione,
                $tipo_entrata,
                $importo,
                $causale_entrata,
                $destinatario['saldo'],
                $nuovo_saldo_destinatario
            );
            $stmt->execute();
            
            // Commit transazione
            $conn->commit();
            
            $success = true;
            $success_message = "Pagamento di " . formatAmount($importo) . " inviato con successo a " . 
                              htmlspecialchars($destinatario['nome'] . " " . $destinatario['cognome']);
            
            // Aggiorna il saldo locale
            $conto['saldo'] = $nuovo_saldo_mittente;
            
        } catch (Exception $e) {
            // Rollback in caso di errore
            $conn->rollback();
            $errors[] = "Errore durante l'esecuzione del pagamento: " . $e->getMessage();
        }
    }
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Effettua Pagamento - Pay Steam</title>
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
            max-width: 800px;
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
            border: none;
            cursor: pointer;
            font-size: 1rem;
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
        
        .btn-success {
            background: #10b981;
            color: white;
            border: 2px solid #10b981;
            width: 100%;
            padding: 1rem;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .btn-success:hover {
            background: #059669;
            border-color: #059669;
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
        
        .saldo-box {
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .saldo-label {
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }
        
        .saldo-amount {
            font-size: 2rem;
            font-weight: bold;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #10b981;
        }
        
        .form-help {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.25rem;
        }
        
        .info-box {
            background: #f0f9ff;
            border-left: 4px solid #0891b2;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .info-box-title {
            font-weight: 600;
            color: #0891b2;
            margin-bottom: 0.5rem;
        }
        
        .info-box ul {
            margin-left: 1.5rem;
            color: #666;
        }
        
        .info-box li {
            margin-bottom: 0.25rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo-area">
                    <span style="font-size: 2rem;">💸</span>
                    <div>
                        <h1>Effettua Pagamento</h1>
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
                <h2 class="page-title">Invia Denaro</h2>
                <a href="index.php" class="back-link">← Torna alla Dashboard</a>
            </div>
            
            <!-- Saldo disponibile -->
            <div class="saldo-box">
                <div class="saldo-label">Saldo Disponibile</div>
                <div class="saldo-amount"><?php echo formatAmount($conto['saldo']); ?></div>
            </div>
            
            <!-- Messaggi di errore -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>⚠️ Attenzione:</strong>
                    <ul style="margin-top: 0.5rem; margin-left: 1.5rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <!-- Messaggio di successo -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>✅ Successo!</strong>
                    <p style="margin-top: 0.5rem;"><?php echo $success_message; ?></p>
                    <p style="margin-top: 1rem;">
                        <a href="movimenti.php" style="color: #065f46; font-weight: 600;">
                            Visualizza nei movimenti →
                        </a>
                    </p>
                </div>
            <?php endif; ?>
            
            <!-- Info box -->
            <div class="info-box">
                <div class="info-box-title">ℹ️ Come funziona</div>
                <ul>
                    <li>Inserisci l'email del destinatario registrato su Pay Steam</li>
                    <li>Specifica l'importo da inviare</li>
                    <li>Aggiungi una descrizione per identificare il pagamento</li>
                    <li>Il pagamento verrà eseguito immediatamente se hai saldo sufficiente</li>
                </ul>
            </div>
            
            <!-- Form pagamento -->
            <div class="card">
                <form method="POST">
                    <div class="form-group">
                        <label for="email_destinatario" class="form-label">
                            📧 Email Destinatario *
                        </label>
                        <input 
                            type="email" 
                            id="email_destinatario" 
                            name="email_destinatario" 
                            class="form-input"
                            required
                            value="<?php echo isset($_POST['email_destinatario']) ? htmlspecialchars($_POST['email_destinatario']) : ''; ?>"
                            placeholder="esempio@email.it"
                        >
                        <div class="form-help">
                            Inserisci l'email dell'utente o esercente registrato su Pay Steam
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="importo" class="form-label">
                            💰 Importo (€) *
                        </label>
                        <input 
                            type="number" 
                            id="importo" 
                            name="importo" 
                            class="form-input"
                            required
                            min="0.01"
                            step="0.01"
                            value="<?php echo isset($_POST['importo']) ? htmlspecialchars($_POST['importo']) : ''; ?>"
                            placeholder="0.00"
                        >
                        <div class="form-help">
                            Disponibile: <?php echo formatAmount($conto['saldo']); ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="descrizione" class="form-label">
                            📝 Descrizione *
                        </label>
                        <textarea 
                            id="descrizione" 
                            name="descrizione" 
                            class="form-input"
                            required
                            rows="3"
                            placeholder="Es: Pagamento fattura n. 123, Rimborso spese, ecc."
                        ><?php echo isset($_POST['descrizione']) ? htmlspecialchars($_POST['descrizione']) : ''; ?></textarea>
                        <div class="form-help">
                            Descrizione che comparirà nei movimenti di entrambi gli utenti
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        💸 Invia Pagamento
                    </button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>