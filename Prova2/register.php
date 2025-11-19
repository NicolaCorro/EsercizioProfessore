<?php
require_once 'config.php';

// Se l'utente è già loggato, redirect
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// Gestione registrazione
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $tipo_profilo = $_POST['tipo_profilo'] ?? 'UTENTE';
    
    // Dati carta di credito (opzionali)
    $numero_carta = trim($_POST['numero_carta'] ?? '');
    $intestatario_carta = trim($_POST['intestatario_carta'] ?? '');
    $scadenza_carta = trim($_POST['scadenza_carta'] ?? '');
    $tipo_carta = $_POST['tipo_carta'] ?? 'VISA';
    
    // Validazione
    if (empty($nome) || empty($cognome) || empty($email) || empty($password)) {
        $error = 'Tutti i campi obbligatori devono essere compilati';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email non valida';
    } elseif (strlen($password) < 6) {
        $error = 'La password deve essere di almeno 6 caratteri';
    } elseif ($password !== $password_confirm) {
        $error = 'Le password non coincidono';
    } elseif (!in_array($tipo_profilo, ['UTENTE', 'ESERCENTE'])) {
        $error = 'Tipo di profilo non valido';
    } else {
        // Validazione carta di credito (se fornita)
        $has_carta = !empty($numero_carta);
        if ($has_carta) {
            if (empty($intestatario_carta) || empty($scadenza_carta)) {
                $error = 'Se inserisci una carta, devi compilare tutti i campi della carta';
            } elseif (strlen($numero_carta) < 13 || strlen($numero_carta) > 19) {
                $error = 'Numero carta non valido (deve essere tra 13 e 19 cifre)';
            } elseif (!preg_match('/^\d{2}\/\d{4}$/', $scadenza_carta)) {
                $error = 'Formato scadenza non valido (usa MM/YYYY)';
            }
        }
        
        if (empty($error)) {
            $conn = getDBConnection();
            
            // Verifica se email già esistente
            $stmt = $conn->prepare("SELECT id_utente FROM utenti WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Email già registrata';
            } else {
                // Inizia transazione
                $conn->begin_transaction();
                
                try {
                    // 1. Ottieni ID profilo
                    $stmt = $conn->prepare("SELECT id_profilo FROM profili WHERE nome = ?");
                    $stmt->bind_param("s", $tipo_profilo);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $profilo = $result->fetch_assoc();
                    $id_profilo = $profilo['id_profilo'];
                    
                    // 2. Inserisci nuovo utente
                    $password_hash = md5($password);
                    $stmt = $conn->prepare("
                        INSERT INTO utenti (email, password, nome, cognome, telefono, id_profilo, attivo)
                        VALUES (?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->bind_param("sssssi", $email, $password_hash, $nome, $cognome, $telefono, $id_profilo);
                    $stmt->execute();
                    $id_utente = $conn->insert_id;
                    
                    // 3. Crea conto con saldo iniziale di 100€
                    $saldo_iniziale = 100.00;
                    $stmt = $conn->prepare("
                        INSERT INTO conti (id_utente, saldo)
                        VALUES (?, ?)
                    ");
                    $stmt->bind_param("id", $id_utente, $saldo_iniziale);
                    $stmt->execute();
                    $id_conto = $conn->insert_id;
                    
                    // 4. Registra movimento iniziale
                    $causale = "Bonus registrazione Pay Steam";
                    $stmt = $conn->prepare("
                        INSERT INTO movimenti (id_conto, tipo, importo, causale, saldo_precedente, saldo_nuovo)
                        VALUES (?, 'ENTRATA', ?, ?, 0.00, ?)
                    ");
                    $stmt->bind_param("idsd", $id_conto, $saldo_iniziale, $causale, $saldo_iniziale);
                    $stmt->execute();
                    
                    // 5. Inserisci carta di credito (se fornita)
                    if ($has_carta) {
                        $predefinita = 1; // Prima carta = predefinita
                        $stmt = $conn->prepare("
                            INSERT INTO carta_credito (id_utente, numero_carta, intestatario, scadenza, tipo_carta, predefinita)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param("issssi", $id_utente, $numero_carta, $intestatario_carta, $scadenza_carta, $tipo_carta, $predefinita);
                        $stmt->execute();
                    }
                    
                    // Commit transazione
                    $conn->commit();
                    
                    $success = 'Registrazione completata! Hai ricevuto 100€ di bonus. Ora puoi effettuare il login.';
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = 'Errore durante la registrazione. Riprova.';
                }
            }
            
            $stmt->close();
            closeDBConnection($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione - Pay Steam</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .register-container {
            background: white;
            padding: 3rem;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo-icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }
        
        .logo h1 {
            color: #10b981;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .logo p {
            color: #666;
            font-size: 0.9rem;
        }
        
        h2 {
            color: #333;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .form-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .form-section h3 {
            color: #10b981;
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        .required {
            color: #e74c3c;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="password"],
        select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border 0.3s;
        }
        
        input:focus,
        select:focus {
            outline: none;
            border-color: #10b981;
        }
        
        .profile-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .profile-option {
            position: relative;
        }
        
        .profile-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }
        
        .profile-option label {
            display: block;
            padding: 1.5rem;
            background: white;
            border: 2px solid #ddd;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .profile-option input[type="radio"]:checked + label {
            border-color: #10b981;
            background: #f0fdf4;
        }
        
        .profile-option label:hover {
            border-color: #10b981;
        }
        
        .profile-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .profile-name {
            font-weight: 600;
            color: #333;
        }
        
        .profile-desc {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.25rem;
        }
        
        .btn {
            width: 100%;
            padding: 1rem;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #059669;
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #c33;
        }
        
        .success {
            background: #d1fae5;
            color: #065f46;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #10b981;
        }
        
        .links {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .links a {
            color: #10b981;
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .links a:hover {
            color: #059669;
            text-decoration: underline;
        }
        
        .divider {
            margin: 1.5rem 0;
            text-align: center;
            color: #999;
        }
        
        .bonus-info {
            background: #fef3c7;
            color: #92400e;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #f59e0b;
            font-size: 0.9rem;
        }
        
        .bonus-info strong {
            color: #78350f;
        }

        .card-info {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
            font-style: italic;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <div class="logo-icon">💳</div>
            <h1>Pay Steam</h1>
            <p>Sistema di Pagamento Online</p>
        </div>
        
        <h2>Crea il tuo account</h2>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                <?php echo htmlspecialchars($success); ?>
                <div style="margin-top: 1rem;">
                    <a href="login.php" style="color: #065f46; font-weight: 600;">Vai al Login →</a>
                </div>
            </div>
        <?php else: ?>
        
        <div class="bonus-info">
            🎁 <strong>Bonus di Benvenuto:</strong> Registrati ora e ricevi 100€ di credito gratuito sul tuo conto!
        </div>
        
        <form method="POST" action="">
            <div class="form-section">
                <h3>Tipo di Account</h3>
                <div class="profile-selector">
                    <div class="profile-option">
                        <input type="radio" id="utente" name="tipo_profilo" value="UTENTE" checked>
                        <label for="utente">
                            <div class="profile-icon">👤</div>
                            <div class="profile-name">Utente</div>
                            <div class="profile-desc">Per effettuare acquisti</div>
                        </label>
                    </div>
                    <div class="profile-option">
                        <input type="radio" id="esercente" name="tipo_profilo" value="ESERCENTE">
                        <label for="esercente">
                            <div class="profile-icon">🏪</div>
                            <div class="profile-name">Esercente</div>
                            <div class="profile-desc">Per ricevere pagamenti</div>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h3>Dati Personali</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="nome">Nome <span class="required">*</span></label>
                        <input type="text" id="nome" name="nome" required 
                               value="<?php echo isset($_POST['nome']) ? htmlspecialchars($_POST['nome']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="cognome">Cognome <span class="required">*</span></label>
                        <input type="text" id="cognome" name="cognome" required 
                               value="<?php echo isset($_POST['cognome']) ? htmlspecialchars($_POST['cognome']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email">Email <span class="required">*</span></label>
                    <input type="email" id="email" name="email" required 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="telefono">Telefono</label>
                    <input type="tel" id="telefono" name="telefono" placeholder="+39 333 1234567"
                           value="<?php echo isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : ''; ?>">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password <span class="required">*</span></label>
                        <input type="password" id="password" name="password" required minlength="6">
                        <div class="card-info">Minimo 6 caratteri</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password_confirm">Conferma Password <span class="required">*</span></label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="6">
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h3>Carta di Credito (Opzionale)</h3>
                <div class="card-info" style="margin-bottom: 1rem;">
                    Puoi aggiungere una carta di credito ora o farlo in seguito dal tuo profilo.
                </div>
                
                <div class="form-group">
                    <label for="numero_carta">Numero Carta</label>
                    <input type="text" id="numero_carta" name="numero_carta" 
                           placeholder="1234 5678 9012 3456" maxlength="19"
                           value="<?php echo isset($_POST['numero_carta']) ? htmlspecialchars($_POST['numero_carta']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="intestatario_carta">Intestatario Carta</label>
                    <input type="text" id="intestatario_carta" name="intestatario_carta" 
                           placeholder="MARIO ROSSI"
                           value="<?php echo isset($_POST['intestatario_carta']) ? htmlspecialchars($_POST['intestatario_carta']) : ''; ?>">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="scadenza_carta">Scadenza (MM/YYYY)</label>
                        <input type="text" id="scadenza_carta" name="scadenza_carta" 
                               placeholder="12/2027" pattern="\d{2}/\d{4}"
                               value="<?php echo isset($_POST['scadenza_carta']) ? htmlspecialchars($_POST['scadenza_carta']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="tipo_carta">Tipo Carta</label>
                        <select id="tipo_carta" name="tipo_carta">
                            <option value="VISA" <?php echo (isset($_POST['tipo_carta']) && $_POST['tipo_carta'] == 'VISA') ? 'selected' : ''; ?>>VISA</option>
                            <option value="MASTERCARD" <?php echo (isset($_POST['tipo_carta']) && $_POST['tipo_carta'] == 'MASTERCARD') ? 'selected' : ''; ?>>MASTERCARD</option>
                            <option value="AMEX" <?php echo (isset($_POST['tipo_carta']) && $_POST['tipo_carta'] == 'AMEX') ? 'selected' : ''; ?>>AMEX</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn">Registrati e Ricevi 100€</button>
        </form>
        
        <div class="divider">oppure</div>
        
        <div class="links">
            <a href="login.php">Hai già un account? Accedi</a>
            <br><br>
            <a href="index.php">← Torna alla homepage</a>
        </div>
        
        <?php endif; ?>
    </div>
</body>
</html>
