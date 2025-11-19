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
    <title>SFT - Società Ferrovie Turistiche</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            background: #667eea;
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
            color: #667eea;
            border: 2px solid white;
        }
        
        .btn-primary:hover {
            background: transparent;
            color: white;
        }
        
        .btn-secondary {
            background: #667eea;
            color: white;
            border: 2px solid #667eea;
        }
        
        .btn-secondary:hover {
            background: #5568d3;
        }
        
        main {
            padding: 2rem 0;
        }
        
        .hero {
            background: linear-gradient(rgba(102, 126, 234, 0.9), rgba(118, 75, 162, 0.9)),
                        url('https://images.unsplash.com/photo-1474487548417-781cb71495f3?w=1200') center/cover;
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
            color: #667eea;
            margin-bottom: 1rem;
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
            border-left: 4px solid #667eea;
        }
        
        .info-box h4 {
            color: #667eea;
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
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div>
                    <h1>SFT</h1>
                    <p class="subtitle">Società Ferrovie Turistiche</p>
                </div>
                <div class="user-menu">
                    <?php if ($isLoggedIn): ?>
                        <span class="welcome-user">Benvenuto, <?php echo htmlspecialchars($userName); ?></span>
                        <?php if ($userProfile == 'BACKOFFICE_AMM'): ?>
                            <a href="admin/" class="btn btn-primary">Area Admin</a>
                        <?php elseif ($userProfile == 'BACKOFFICE_ESE'): ?>
                            <a href="esercizio/" class="btn btn-primary">Area Esercizio</a>
                        <?php else: ?>
                            <a href="user/" class="btn btn-primary">Area Utente</a>
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
            <li><a href="stazioni.php">Le Stazioni</a></li>
            <li><a href="materiale.php">Materiale Rotabile</a></li>
            <li><a href="orari.php">Orari Treni</a></li>
            <?php if ($isLoggedIn && $userProfile == 'REGISTRATO'): ?>
                <li><a href="user/prenota.php">Prenota Biglietto</a></li>
            <?php endif; ?>
        </ul>
    </nav>

    <main>
        <div class="container">
            <div class="hero">
                <h2>Viaggia nel tempo con i nostri treni storici</h2>
                <p>54 km di storia, natura e tradizione attraverso 10 stazioni panoramiche</p>
                <?php if (!$isLoggedIn): ?>
                    <a href="register.php" class="btn btn-primary" style="font-size: 1.1rem; padding: 1rem 2rem;">
                        Prenota il tuo viaggio
                    </a>
                <?php endif; ?>
            </div>

            <div class="features">
                <div class="feature-card">
                    <h3>🚂 Treni Storici</h3>
                    <p>Viaggia a bordo di autentici convogli d'epoca restaurati con cura, trainati da locomotive storiche come la "Cavour", "Vittorio Emanuele" e "Garibaldi".</p>
                </div>
                
                <div class="feature-card">
                    <h3>🎫 Prenotazione Online</h3>
                    <p>Prenota comodamente il tuo posto a sedere online. Scegli la tratta, la data e il posto che preferisci con pochi click.</p>
                </div>
                
                <div class="feature-card">
                    <h3>🏞️ Panorami Mozzafiato</h3>
                    <p>Attraversa paesaggi spettacolari: dalle colline di Rocca Pietrosa alle spiagge di Porto San Felice, ogni viaggio è un'esperienza unica.</p>
                </div>
            </div>

            <div class="info-section">
                <div class="container">
                    <h2 style="text-align: center; color: #667eea; margin-bottom: 2rem;">La Nostra Linea</h2>
                    <div class="info-grid">
                        <div class="info-box">
                            <h4>Lunghezza</h4>
                            <p>54,68 km di percorso panoramico</p>
                        </div>
                        <div class="info-box">
                            <h4>Stazioni</h4>
                            <p>10 fermate tra mare e collina</p>
                        </div>
                        <div class="info-box">
                            <h4>Velocità</h4>
                            <p>50 km/h per godersi il paesaggio</p>
                        </div>
                        <div class="info-box">
                            <h4>Corse Giornaliere</h4>
                            <p>4 coppie di treni nei festivi, 1 nei feriali estivi</p>
                        </div>
                    </div>
                </div>
            </div>

            <div style="text-align: center; margin: 3rem 0;">
                <h2 style="color: #667eea; margin-bottom: 1rem;">Pronto a partire?</h2>
                <p style="font-size: 1.1rem; margin-bottom: 2rem;">
                    Scopri gli orari, consulta il materiale rotabile e prenota la tua esperienza ferroviaria unica!
                </p>
                <a href="orari.php" class="btn btn-secondary" style="margin-right: 1rem;">Vedi Orari</a>
                <?php if (!$isLoggedIn): ?>
                    <a href="register.php" class="btn btn-primary">Registrati Ora</a>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2025 Società Ferrovie Turistiche (SFT) - Progetto Prova In Itinere 1</p>
            <p style="margin-top: 0.5rem; font-size: 0.9rem;">Basi di Dati - Università Guglielmo Marconi</p>
        </div>
    </footer>
</body>
</html>