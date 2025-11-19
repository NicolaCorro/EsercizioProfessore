<?php
require_once 'config.php';

$conn = getDBConnection();

// Recupera tutti i treni con le loro fermate
$query = "
    SELECT 
        t.id_treno,
        t.numero_treno,
        t.data_partenza,
        t.direzione,
        t.stato,
        c.nome as nome_convoglio,
        c.posti_totali,
        sp.nome as stazione_partenza,
        sa.nome as stazione_arrivo,
        MIN(f.orario_partenza) as orario_partenza_prima,
        MAX(f.orario_arrivo) as orario_arrivo_ultima
    FROM treni t
    JOIN convogli c ON t.id_convoglio = c.id_convoglio
    JOIN stazioni sp ON t.id_stazione_partenza = sp.id_stazione
    JOIN stazioni sa ON t.id_stazione_arrivo = sa.id_stazione
    LEFT JOIN fermate f ON t.id_treno = f.id_treno
    WHERE t.data_partenza >= CURDATE()
    GROUP BY t.id_treno, t.numero_treno, t.data_partenza, t.direzione, t.stato, 
             c.nome, c.posti_totali, sp.nome, sa.nome
    ORDER BY t.data_partenza ASC, t.numero_treno ASC
";

$treni = $conn->query($query);

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orari Treni - SFT</title>
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
            background: #f5f5f5;
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
        
        h1 {
            font-size: 2.5rem;
        }
        
        .subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-top: 0.5rem;
        }
        
        nav {
            background: white;
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
            padding: 3rem 0;
        }
        
        .intro {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .intro h2 {
            color: #667eea;
            margin-bottom: 1rem;
        }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .badge-nord {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-sud {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-programmato {
            background: #cce5ff;
            color: #004085;
        }
        
        .trains-grid {
            display: grid;
            gap: 2rem;
        }
        
        .train-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .train-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .train-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .train-number {
            font-size: 2rem;
            font-weight: bold;
        }
        
        .train-type {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .train-body {
            padding: 1.5rem;
        }
        
        .route {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }
        
        .station {
            font-weight: 600;
            color: #667eea;
        }
        
        .arrow {
            color: #999;
            font-size: 1.5rem;
        }
        
        .train-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .info-item {
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .info-label {
            font-size: 0.85rem;
            color: #999;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }
        
        .btn {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .empty-state h3 {
            color: #667eea;
            margin-bottom: 1rem;
        }
        
        footer {
            background: #333;
            color: white;
            text-align: center;
            padding: 2rem 0;
            margin-top: 3rem;
        }
        
        .date-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: white;
            color: #667eea;
            border-radius: 20px;
            font-weight: 600;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Orari dei Treni</h1>
            <p class="subtitle">Pianifica il tuo viaggio sulla linea SFT</p>
        </div>
    </header>

    <nav>
        <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="stazioni.php">Le Stazioni</a></li>
            <li><a href="materiale.php">Materiale Rotabile</a></li>
            <li><a href="orari.php" class="active">Orari Treni</a></li>
        </ul>
    </nav>

    <main>
        <div class="container">
            <div class="intro">
                <h2>Consulta gli Orari</h2>
                <p>Qui trovi tutti i treni in circolazione sulla linea SFT. Scegli il tuo treno e prenota il tuo posto!</p>
            </div>

            <div class="trains-grid">
                <?php if ($treni && $treni->num_rows > 0): ?>
                    <?php while ($treno = $treni->fetch_assoc()): ?>
                        <div class="train-card">
                            <div class="train-header">
                                <div>
                                    <div class="train-number">Treno <?php echo htmlspecialchars($treno['numero_treno']); ?></div>
                                    <div class="train-type"><?php echo htmlspecialchars($treno['nome_convoglio']); ?></div>
                                    <div class="date-badge">
                                        <?php echo date('d/m/Y', strtotime($treno['data_partenza'])); ?>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge <?php echo strtolower($treno['direzione']) == 'nord' ? 'badge-nord' : 'badge-sud'; ?>">
                                        <?php echo htmlspecialchars($treno['direzione']); ?>
                                    </span>
                                    <br><br>
                                    <span class="badge badge-programmato">
                                        <?php echo htmlspecialchars($treno['stato']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="train-body">
                                <div class="route">
                                    <span class="station"><?php echo htmlspecialchars($treno['stazione_partenza']); ?></span>
                                    <span class="arrow">→</span>
                                    <span class="station"><?php echo htmlspecialchars($treno['stazione_arrivo']); ?></span>
                                </div>
                                
                                <div class="train-info">
                                    <div class="info-item">
                                        <div class="info-label">Partenza</div>
                                        <div class="info-value">
                                            <?php echo $treno['orario_partenza_prima'] ? date('H:i', strtotime($treno['orario_partenza_prima'])) : 'N/D'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">Arrivo</div>
                                        <div class="info-value">
                                            <?php echo $treno['orario_arrivo_ultima'] ? date('H:i', strtotime($treno['orario_arrivo_ultima'])) : 'N/D'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">Posti Disponibili</div>
                                        <div class="info-value">
                                            <?php echo $treno['posti_totali']; ?> posti
                                        </div>
                                    </div>
                                    
                                    <?php if ($treno['orario_partenza_prima'] && $treno['orario_arrivo_ultima']): ?>
                                    <div class="info-item">
                                        <div class="info-label">Durata</div>
                                        <div class="info-value">
                                            <?php 
                                            $start = new DateTime($treno['orario_partenza_prima']);
                                            $end = new DateTime($treno['orario_arrivo_ultima']);
                                            $diff = $start->diff($end);
                                            echo $diff->h . 'h ' . $diff->i . 'm';
                                            ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div style="text-align: center; margin-top: 1rem;">
                                    <a href="dettaglio_treno.php?id=<?php echo $treno['id_treno']; ?>" class="btn">
                                        Vedi Fermate Dettagliate
                                    </a>
                                    <?php if (isset($_SESSION['user_id']) && $_SESSION['user_profile'] == 'REGISTRATO'): ?>
                                        <a href="user/prenota.php?treno=<?php echo $treno['id_treno']; ?>" class="btn" style="background: #28a745; margin-left: 1rem;">
                                            Prenota Ora
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <h3>Nessun treno programmato</h3>
                        <p>Al momento non ci sono treni in circolazione. Torna a trovarci presto!</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!isset($_SESSION['user_id'])): ?>
                <div style="text-align: center; margin-top: 3rem; padding: 2rem; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                    <h3 style="color: #667eea; margin-bottom: 1rem;">Vuoi prenotare un viaggio?</h3>
                    <p style="margin-bottom: 1.5rem;">Registrati gratuitamente per prenotare i tuoi biglietti online!</p>
                    <a href="register.php" class="btn">Registrati Ora</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2025 Società Ferrovie Turistiche (SFT)</p>
        </div>
    </footer>
</body>
</html>