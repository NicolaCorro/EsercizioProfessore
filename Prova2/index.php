<?php
require_once 'config.php';

// Verifica se l'utente è già loggato
$isLoggedIn = isset($_SESSION['user_id']);
$userName = $isLoggedIn ? $_SESSION['user_name'] : '';
$userProfile = $isLoggedIn ? $_SESSION['user_profile'] : '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Steam - Sistema di Pagamento Online</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        
        header {
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            color: white;
            padding: 2rem 0;
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
        
        .logo-icon {
            font-size: 3rem;
        }
        
        h1 {
            font-size: 2.5rem;
        }
        
        .subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-top: 0.5rem;
        }
        
        nav {
            background: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
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
        
        nav a:hover {
            background: #10b981;
            color: white;
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
            background: #10b981;
            color: white;
            border: 2px solid #10b981;
        }
        
        .btn-secondary:hover {
            background: #059669;
        }
        
        main {
            padding: 2rem 0;
        }
        
        .hero {
            background: linear-gradient(rgba(16, 185, 129, 0.9), rgba(8, 145, 178, 0.9)),
                        url('https://images.unsplash.com/photo-1563013544-824ae1b704d3?w=1200') center/cover;
            color: white;
            padding: 4rem 0;
            text-align: center;
            margin-bottom: 2rem;
            border-radius: 10px;
        }
        
        .hero h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .hero p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
        }
        
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin: 3rem 0;
        }
        
        .feature-card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
        }
        
        .feature-card h3 {
            color: #10b981;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .info-section {
            background: #f8f9fa;
            padding: 3rem 0;
            margin: 3rem 0;
            border-radius: 10px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        
        .info-box {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #10b981;
        }
        
        .info-box h4 {
            color: #10b981;
            margin-bottom: 0.5rem;
        }
        
        footer {
            background: #333;
            color: white;
            text-align: center;
            padding: 2rem 0;
            margin-top: 3rem;
        }
        
        .welcome-user {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .cta-section {
            text-align: center;
            margin: 3rem 0;
            padding: 2rem;
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            border-radius: 10px;
            color: white;
        }

        .cta-section h2 {
            margin-bottom: 1rem;
        }

        .cta-section p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo-area">
                    <span class="logo-icon">💳</span>
                    <div>
                        <h1>Pay Steam</h1>
                        <p class="subtitle">Sistema di Pagamento Sicuro Online</p>
                    </div>
                </div>
                <div class="user-menu">
                    <?php if ($isLoggedIn): ?>
                        <span class="welcome-user">Benvenuto, <?php echo htmlspecialchars($userName); ?></span>
                        <?php if ($userProfile == 'ESERCENTE'): ?>
                            <a href="esercente/" class="btn btn-primary">Area Esercente</a>
                        <?php else: ?>
                            <a href="user/" class="btn btn-primary">Il Mio Conto</a>
                        <?php endif; ?>
                        <a href="logout.php" class="btn btn-secondary">Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-primary">Login</a>
                        <a href="register.php" class="btn btn-secondary">Registrati</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <nav>
        <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="#vantaggi">Vantaggi</a></li>
            <li><a href="#sicurezza">Sicurezza</a></li>
            <?php if ($isLoggedIn): ?>
                <?php if ($userProfile == 'ESERCENTE'): ?>
                    <li><a href="esercente/">Dashboard Esercente</a></li>
                <?php else: ?>
                    <li><a href="user/">Il Mio Conto</a></li>
                    <li><a href="user/movimenti.php">Movimenti</a></li>
                <?php endif; ?>
            <?php endif; ?>
        </ul>
    </nav>

    <main>
        <div class="container">
            <div class="hero">
                <h2>Pagamenti Online Sicuri e Veloci</h2>
                <p>La soluzione completa per gestire i tuoi pagamenti digitali</p>
                <?php if (!$isLoggedIn): ?>
                    <a href="register.php" class="btn btn-primary" style="font-size: 1.1rem; padding: 1rem 2rem; background: white; color: #10b981;">
                        Apri il tuo conto gratuito
                    </a>
                <?php endif; ?>
            </div>

            <div class="features">
                <div class="feature-card">
                    <h3>🔒 Pagamenti Sicuri</h3>
                    <p>Tecnologia di crittografia avanzata per proteggere ogni transazione. I tuoi dati sono sempre al sicuro con Pay Steam.</p>
                </div>
                
                <div class="feature-card">
                    <h3>⚡ Veloce e Semplice</h3>
                    <p>Completa i tuoi acquisti online in pochi secondi. Interfaccia intuitiva e processo di pagamento ottimizzato.</p>
                </div>
                
                <div class="feature-card">
                    <h3>💰 Conto Integrato</h3>
                    <p>Gestisci il tuo saldo, visualizza lo storico delle transazioni e monitora le tue spese in tempo reale.</p>
                </div>
            </div>

            <div class="info-section" id="vantaggi">
                <div class="container">
                    <h2 style="text-align: center; color: #10b981; margin-bottom: 2rem;">Perché scegliere Pay Steam?</h2>
                    <div class="info-grid">
                        <div class="info-box">
                            <h4>Zero Commissioni</h4>
                            <p>Nessun costo nascosto per le transazioni tra utenti Pay Steam</p>
                        </div>
                        <div class="info-box">
                            <h4>Disponibile 24/7</h4>
                            <p>Accedi al tuo conto e gestisci i pagamenti in qualsiasi momento</p>
                        </div>
                        <div class="info-box">
                            <h4>Protezione Acquisti</h4>
                            <p>Sistema di garanzia per proteggere i tuoi acquisti online</p>
                        </div>
                        <div class="info-box">
                            <h4>Supporto Clienti</h4>
                            <p>Team dedicato pronto ad aiutarti per qualsiasi necessità</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="info-section" id="sicurezza">
                <div class="container">
                    <h2 style="text-align: center; color: #10b981; margin-bottom: 1rem;">Sicurezza Garantita</h2>
                    <p style="text-align: center; color: #666; max-width: 800px; margin: 0 auto 2rem;">
                        Pay Steam utilizza le più avanzate tecnologie di sicurezza per proteggere i tuoi dati e le tue transazioni. 
                        Ogni operazione è monitorata e protetta da sistemi di crittografia di livello bancario.
                    </p>
                    <div style="text-align: center; margin-top: 2rem;">
                        <div style="display: inline-block; background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                            <div style="font-size: 4rem; margin-bottom: 1rem;">🛡️</div>
                            <h3 style="color: #10b981; margin-bottom: 0.5rem;">Certificato SSL</h3>
                            <p style="color: #666;">Connessioni sempre sicure e crittografate</p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isLoggedIn): ?>
            <div class="cta-section">
                <h2>Inizia subito con Pay Steam</h2>
                <p>Registrati gratuitamente e ricevi 100€ di credito iniziale per iniziare!</p>
                <a href="register.php" class="btn btn-primary" style="font-size: 1.1rem; padding: 1rem 2rem; background: white; color: #10b981; border: none;">
                    Registrati Ora - È Gratis!
                </a>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2025 Pay Steam - Sistema di Pagamento Online</p>
            <p style="margin-top: 0.5rem; font-size: 0.9rem;">Prova In Itinere 2 - Basi di Dati - Università Guglielmo Marconi</p>
        </div>
    </footer>
</body>
</html>
