<?php
require_once 'config.php';

// Se l'utente è già loggato, redirect
if (isset($_SESSION['user_id'])) {
    $profile = $_SESSION['user_profile'];
    if ($profile == 'ESERCENTE') {
        header('Location: esercente/');
    } else {
        header('Location: user/');
    }
    exit();
}

$error = '';

// Gestione login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Inserisci email e password';
    } else {
        $conn = getDBConnection();
        
        // Query per verificare le credenziali
        $stmt = $conn->prepare("
            SELECT u.id_utente, u.email, u.password, u.nome, u.cognome, p.nome as profilo
            FROM utente u
            JOIN profilo p ON u.id_profilo = p.id_profilo
            WHERE u.email = ? AND u.attivo = 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // Verifica password (MD5 come nel database)
            if (md5($password) == $user['password']) {
                // Login successful
                $_SESSION['user_id'] = $user['id_utente'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['nome'] . ' ' . $user['cognome'];
                $_SESSION['user_profile'] = $user['profilo'];
                
				// Se c'è un URL salvato, reindirizza lì
                if (isset($_SESSION['redirect_dopo_login'])) {
                    $redirect_url = $_SESSION['redirect_dopo_login'];
                    unset($_SESSION['redirect_dopo_login']); // Pulisci la variabile
                    header('Location: ' . $redirect_url);
                    exit();
                }
                // Redirect in base al profilo
                if ($user['profilo'] == 'ESERCENTE') {
                    header('Location: esercente/');
                } else {
                    header('Location: user/');
                }
                exit();
            } else {
                $error = 'Email o password non corretti';
            }
        } else {
            $error = 'Email o password non corretti';
        }
        
        $stmt->close();
        closeDBConnection($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Pay Steam</title>
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
        
        .login-container {
            background: white;
            padding: 3rem;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 450px;
            width: 100%;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo-icon {
            font-size: 4rem;
            margin-bottom: 0.5rem;
        }
        
        .logo h1 {
            color: #10b981;
            font-size: 2.5rem;
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
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border 0.3s;
        }
        
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #10b981;
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
        
        .test-credentials {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1.5rem;
            font-size: 0.85rem;
        }
        
        .test-credentials h4 {
            color: #10b981;
            margin-bottom: 0.5rem;
        }
        
        .test-credentials p {
            margin: 0.25rem 0;
            color: #666;
        }
        
        .test-credentials code {
            background: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
        }

        .test-credentials strong {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <div class="logo-icon">💳</div>
            <h1>Pay Steam</h1>
            <p>Sistema di Pagamento Online</p>
        </div>
        
        <h2>Accedi al tuo account</h2>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn">Accedi</button>
        </form>
        
        <div class="divider">oppure</div>
        
        <div class="links">
            <a href="register.php">Non hai un account? Registrati</a>
            <br><br>
            <a href="index.php">← Torna alla homepage</a>
        </div>
        
        <div class="test-credentials">
            <h4>🔑 Credenziali di test:</h4>
            <p><strong>Utente (Mario Rossi):</strong><br>
            Email: <code>mario.rossi@email.it</code><br>
            Password: <code>mario123</code></p>
            
            <p style="margin-top: 0.75rem;"><strong>Utente (Anna Verdi):</strong><br>
            Email: <code>anna.verdi@email.it</code><br>
            Password: <code>anna123</code></p>
            
            <p style="margin-top: 0.75rem;"><strong>Esercente (SFT Ferrovie):</strong><br>
            Email: <code>sft@ferrovie.it</code><br>
            Password: <code>sft123</code></p>
            
            <p style="margin-top: 0.75rem;"><strong>Esercente (Hotel Roma):</strong><br>
            Email: <code>hotel.roma@email.it</code><br>
            Password: <code>hotel123</code></p>
        </div>
    </div>
</body>
</html>
