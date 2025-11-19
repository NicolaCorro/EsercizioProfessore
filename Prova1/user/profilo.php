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

$success = '';
$error = '';
$password_success = '';
$password_error = '';

// GESTIONE MODIFICA DATI PERSONALI
if (isset($_POST['aggiorna_dati'])) {
    $nome = trim($_POST['nome']);
    $cognome = trim($_POST['cognome']);
    $telefono = trim($_POST['telefono']);
    
    // Validazione
    if (empty($nome) || empty($cognome)) {
        $error = "Nome e cognome sono obbligatori.";
    } else {
        $stmt = $conn->prepare("UPDATE UTENTE SET nome = ?, cognome = ?, telefono = ? WHERE id_utente = ?");
        $stmt->bind_param("sssi", $nome, $cognome, $telefono, $user_id);
        
        if ($stmt->execute()) {
            $success = "Dati aggiornati con successo!";
            // Aggiorna anche la sessione
            $_SESSION['user_name'] = $nome . ' ' . $cognome;
            $user_name = $_SESSION['user_name'];
        } else {
            $error = "Errore durante l'aggiornamento dei dati.";
        }
    }
}

// GESTIONE CAMBIO PASSWORD
if (isset($_POST['cambia_password'])) {
    $password_attuale = $_POST['password_attuale'];
    $nuova_password = $_POST['nuova_password'];
    $conferma_password = $_POST['conferma_password'];
    
    // Recupera password attuale dal database
    $stmt = $conn->prepare("SELECT password FROM UTENTE WHERE id_utente = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    // Verifica password attuale
    if (md5($password_attuale) !== $result['password']) {
        $password_error = "La password attuale non è corretta.";
    } elseif (strlen($nuova_password) < 6) {
        $password_error = "La nuova password deve essere di almeno 6 caratteri.";
    } elseif ($nuova_password !== $conferma_password) {
        $password_error = "Le password non corrispondono.";
    } else {
        $password_hash = md5($nuova_password);
        $stmt = $conn->prepare("UPDATE UTENTE SET password = ? WHERE id_utente = ?");
        $stmt->bind_param("si", $password_hash, $user_id);
        
        if ($stmt->execute()) {
            $password_success = "Password modificata con successo!";
        } else {
            $password_error = "Errore durante il cambio password.";
        }
    }
}

// Recupera dati utente
$stmt = $conn->prepare("
    SELECT u.*, p.nome as tipo_profilo 
    FROM UTENTE u
    JOIN PROFILO p ON u.id_profilo = p.id_profilo
    WHERE u.id_utente = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$utente = $stmt->get_result()->fetch_assoc();

// Recupera statistiche utente
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as tot_prenotazioni,
        COUNT(CASE WHEN stato = 'CONFERMATA' THEN 1 END) as confermate,
        COUNT(CASE WHEN stato = 'ANNULLATA' THEN 1 END) as annullate
    FROM PRENOTAZIONE 
    WHERE id_utente = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Calcola km totali e spesa totale
$stmt = $conn->prepare("
    SELECT 
        SUM(ABS(sa.km_progressivo - sp.km_progressivo)) as km_totali,
        SUM(b.importo) as spesa_totale
    FROM PRENOTAZIONE p
    JOIN STAZIONE sp ON p.id_stazione_partenza = sp.id_stazione
    JOIN STAZIONE sa ON p.id_stazione_arrivo = sa.id_stazione
    LEFT JOIN BIGLIETTO b ON p.id_prenotazione = b.id_prenotazione
    WHERE p.id_utente = ? AND p.stato = 'CONFERMATA'
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$viaggi = $stmt->get_result()->fetch_assoc();

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Il Mio Profilo - SFT</title>
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
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
        
        /* Sezioni Profilo */
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .profile-section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .profile-section h3 {
            font-size: 1.3rem;
            color: #333;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #667eea;
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
        
        .form-group input {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group input:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        
        .form-info {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
        
        .form-info p {
            margin: 0.5rem 0;
            color: #666;
            font-size: 0.9rem;
        }
        
        .form-info strong {
            color: #333;
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
        
        .password-hint {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.3rem;
        }
        
        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            nav ul {
                flex-direction: column;
            }
            
            nav a {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <h1>🚂 Società Ferrovie Turistiche</h1>
                <div class="user-info">
                    <span>👤 <?php echo htmlspecialchars($user_name); ?></span>
                    <a href="../logout.php" class="btn btn-secondary">Esci</a>
                </div>
            </div>
        </div>
    </header>

    <nav>
        <ul>
            <li><a href="../index.php">Home</a></li>
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="prenota.php">Prenota Biglietto</a></li>
            <li><a href="prenotazioni.php">Le Mie Prenotazioni</a></li>
            <li><a href="profilo.php" class="active">Il Mio Profilo</a></li>
        </ul>
    </nav>

    <main>
        <div class="container">
            <div class="page-title">
                <h2>👤 Il Mio Profilo</h2>
                <p>Gestisci i tuoi dati personali e visualizza le tue statistiche</p>
            </div>

            <!-- Statistiche Utente -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">🎫</div>
                    <div class="stat-value"><?php echo $stats['tot_prenotazioni']; ?></div>
                    <div class="stat-label">Prenotazioni Totali</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-value"><?php echo $stats['confermate']; ?></div>
                    <div class="stat-label">Viaggi Confermati</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">📏</div>
                    <div class="stat-value"><?php echo number_format($viaggi['km_totali'] ?? 0, 1); ?> km</div>
                    <div class="stat-label">Chilometri Percorsi</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value">€ <?php echo number_format($viaggi['spesa_totale'] ?? 0, 2); ?></div>
                    <div class="stat-label">Spesa Totale</div>
                </div>
            </div>

            <!-- Sezioni Profilo -->
            <div class="profile-grid">
                <!-- Dati Personali -->
                <div class="profile-section">
                    <h3>📝 Dati Personali</h3>
                    
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
                    
                    <div class="form-info">
                        <p><strong>Tipo Account:</strong> <?php echo htmlspecialchars($utente['tipo_profilo']); ?></p>
                        <p><strong>Registrato dal:</strong> <?php echo date('d/m/Y', strtotime($utente['data_registrazione'])); ?></p>
                    </div>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" value="<?php echo htmlspecialchars($utente['email']); ?>" disabled>
                            <p class="password-hint">L'email non può essere modificata</p>
                        </div>
                        
                        <div class="form-group">
                            <label>Nome *</label>
                            <input type="text" name="nome" value="<?php echo htmlspecialchars($utente['nome']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Cognome *</label>
                            <input type="text" name="cognome" value="<?php echo htmlspecialchars($utente['cognome']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Telefono</label>
                            <input type="tel" name="telefono" value="<?php echo htmlspecialchars($utente['telefono'] ?? ''); ?>" placeholder="es. 3331234567">
                        </div>
                        
                        <button type="submit" name="aggiorna_dati" class="btn btn-success">
                            💾 Salva Modifiche
                        </button>
                    </form>
                </div>

                <!-- Cambio Password -->
                <div class="profile-section">
                    <h3>🔐 Cambio Password</h3>
                    
                    <?php if ($password_success): ?>
                        <div class="alert alert-success">
                            ✓ <?php echo htmlspecialchars($password_success); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($password_error): ?>
                        <div class="alert alert-error">
                            ✗ <?php echo htmlspecialchars($password_error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label>Password Attuale *</label>
                            <input type="password" name="password_attuale" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Nuova Password *</label>
                            <input type="password" name="nuova_password" required minlength="6">
                            <p class="password-hint">Minimo 6 caratteri</p>
                        </div>
                        
                        <div class="form-group">
                            <label>Conferma Nuova Password *</label>
                            <input type="password" name="conferma_password" required minlength="6">
                        </div>
                        
                        <button type="submit" name="cambia_password" class="btn btn-success">
                            🔒 Cambia Password
                        </button>
                    </form>
                    
                    <div class="form-info" style="margin-top: 1.5rem;">
                        <p><strong>⚠️ Suggerimenti per la sicurezza:</strong></p>
                        <p>• Usa una password di almeno 8 caratteri</p>
                        <p>• Combina lettere maiuscole e minuscole</p>
                        <p>• Aggiungi numeri e simboli</p>
                        <p>• Non usare dati personali evidenti</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>