<?php
require_once 'config.php';

$conn = getDBConnection();

// Recupera tutto il materiale rotabile con il tipo
$query = "
    SELECT m.*, t.nome as tipo_nome
    FROM MATERIALE_ROTABILE m
    JOIN TIPI_MATERIALE t ON m.id_tipo = t.id_tipo
    ORDER BY t.id_tipo, m.sigla
";
$materiali = $conn->query($query);

// Raggruppa per tipo
$locomotive = [];
$carrozze = [];
$bagagliai = [];
$automotrici = [];

while ($m = $materiali->fetch_assoc()) {
    switch ($m['tipo_nome']) {
        case 'Locomotiva':
            $locomotive[] = $m;
            break;
        case 'Carrozza':
            $carrozze[] = $m;
            break;
        case 'Bagagliaio':
            $bagagliai[] = $m;
            break;
        case 'Automotrice':
            $automotrici[] = $m;
            break;
    }
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Materiale Rotabile - SFT</title>
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
            margin-bottom: 3rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .intro h2 {
            color: #667eea;
            margin-bottom: 1rem;
        }
        
        .section {
            margin-bottom: 3rem;
        }
        
        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-header h2 {
            font-size: 1.8rem;
        }
        
        .count-badge {
            background: white;
            color: #667eea;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .materiale-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 2rem;
            background: white;
            padding: 2rem;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .materiale-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .materiale-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        
        .materiale-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .materiale-sigla {
            font-size: 1.5rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .materiale-nome {
            font-size: 1.1rem;
            color: #764ba2;
            font-style: italic;
            margin-top: 0.25rem;
        }
        
        .anno-badge {
            background: #667eea;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .materiale-info {
            margin-bottom: 1rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #666;
            font-weight: 500;
        }
        
        .info-value {
            color: #333;
            font-weight: 600;
        }
        
        .materiale-storia {
            color: #666;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid #e0e0e0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.95rem;
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
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        footer {
            background: #333;
            color: white;
            text-align: center;
            padding: 2rem 0;
            margin-top: 3rem;
        }
        
        .posti-highlight {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Il Nostro Materiale Rotabile</h1>
            <p class="subtitle">Convogli storici restaurati con passione</p>
        </div>
    </header>

    <nav>
        <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="stazioni.php">Le Stazioni</a></li>
            <li><a href="materiale.php" class="active">Materiale Rotabile</a></li>
            <li><a href="orari.php">Orari Treni</a></li>
        </ul>
    </nav>

    <main>
        <div class="container">
            <div class="intro">
                <h2>Una Flotta Storica Unica</h2>
                <p>Il nostro materiale rotabile è composto da autentici pezzi di storia ferroviaria italiana, restaurati con cura maniacale per offrire un'esperienza di viaggio autentica e sicura. Ogni locomotiva, carrozza e automotrice racconta una storia che risale ai primi del '900.</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($locomotive); ?></div>
                    <div class="stat-label">Locomotive Storiche</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($carrozze); ?></div>
                    <div class="stat-label">Carrozze Passeggeri</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($automotrici); ?></div>
                    <div class="stat-label">Automotrici</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">368</div>
                    <div class="stat-label">Posti Totali</div>
                </div>
            </div>

            <!-- LOCOMOTIVE -->
            <div class="section">
                <div class="section-header">
                    <h2>🚂 Locomotive a Vapore</h2>
                    <span class="count-badge"><?php echo count($locomotive); ?> unità</span>
                </div>
                <div class="materiale-grid">
                    <?php foreach ($locomotive as $loco): ?>
                        <div class="materiale-card">
                            <div class="materiale-header">
                                <div>
                                    <div class="materiale-sigla"><?php echo htmlspecialchars($loco['sigla']); ?></div>
                                    <?php if ($loco['nome']): ?>
                                        <div class="materiale-nome">"<?php echo htmlspecialchars($loco['nome']); ?>"</div>
                                    <?php endif; ?>
                                </div>
                                <span class="anno-badge"><?php echo $loco['anno_costruzione']; ?></span>
                            </div>
                            <div class="materiale-info">
                                <div class="info-row">
                                    <span class="info-label">Tipo:</span>
                                    <span class="info-value">Locomotiva a Vapore</span>
                                </div>
                            </div>
                            <?php if ($loco['storia']): ?>
                                <div class="materiale-storia">
                                    <?php echo htmlspecialchars($loco['storia']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- CARROZZE -->
            <div class="section">
                <div class="section-header">
                    <h2>🚃 Carrozze Passeggeri</h2>
                    <span class="count-badge"><?php echo count($carrozze); ?> unità</span>
                </div>
                <div class="materiale-grid">
                    <?php foreach ($carrozze as $carr): ?>
                        <div class="materiale-card">
                            <div class="materiale-header">
                                <div>
                                    <div class="materiale-sigla"><?php echo htmlspecialchars($carr['sigla']); ?></div>
                                </div>
                                <span class="anno-badge"><?php echo $carr['anno_costruzione']; ?></span>
                            </div>
                            <div class="materiale-info">
                                <div class="info-row">
                                    <span class="info-label">Posti a sedere:</span>
                                    <span class="posti-highlight"><?php echo $carr['posti_sedere']; ?> posti</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Serie:</span>
                                    <span class="info-value"><?php echo $carr['anno_costruzione']; ?></span>
                                </div>
                            </div>
                            <?php if ($carr['storia']): ?>
                                <div class="materiale-storia">
                                    <?php echo htmlspecialchars($carr['storia']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- BAGAGLIAI -->
            <?php if (count($bagagliai) > 0): ?>
            <div class="section">
                <div class="section-header">
                    <h2>📦 Bagagliai con Compartimento Passeggeri</h2>
                    <span class="count-badge"><?php echo count($bagagliai); ?> unità</span>
                </div>
                <div class="materiale-grid">
                    <?php foreach ($bagagliai as $bag): ?>
                        <div class="materiale-card">
                            <div class="materiale-header">
                                <div>
                                    <div class="materiale-sigla"><?php echo htmlspecialchars($bag['sigla']); ?></div>
                                </div>
                                <span class="anno-badge"><?php echo $bag['anno_costruzione']; ?></span>
                            </div>
                            <div class="materiale-info">
                                <div class="info-row">
                                    <span class="info-label">Posti a sedere:</span>
                                    <span class="posti-highlight"><?php echo $bag['posti_sedere']; ?> posti</span>
                                </div>
                            </div>
                            <?php if ($bag['storia']): ?>
                                <div class="materiale-storia">
                                    <?php echo htmlspecialchars($bag['storia']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- AUTOMOTRICI -->
            <div class="section">
                <div class="section-header">
                    <h2>🚊 Automotrici Diesel</h2>
                    <span class="count-badge"><?php echo count($automotrici); ?> unità</span>
                </div>
                <div class="materiale-grid">
                    <?php foreach ($automotrici as $auto): ?>
                        <div class="materiale-card">
                            <div class="materiale-header">
                                <div>
                                    <div class="materiale-sigla"><?php echo htmlspecialchars($auto['sigla']); ?></div>
                                </div>
                                <span class="anno-badge"><?php echo $auto['anno_costruzione']; ?></span>
                            </div>
                            <div class="materiale-info">
                                <div class="info-row">
                                    <span class="info-label">Posti a sedere:</span>
                                    <span class="posti-highlight"><?php echo $auto['posti_sedere']; ?> posti</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Caratteristica:</span>
                                    <span class="info-value">Puo viaggiare isolata</span>
                                </div>
                            </div>
                            <?php if ($auto['storia']): ?>
                                <div class="materiale-storia">
                                    <?php echo htmlspecialchars($auto['storia']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="text-align: center; margin-top: 3rem; padding: 2rem; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <h3 style="color: #667eea; margin-bottom: 1rem;">Pronto a salire a bordo?</h3>
                <p style="margin-bottom: 1.5rem;">Consulta gli orari e prenota il tuo viaggio su uno dei nostri treni storici!</p>
                <a href="orari.php" class="btn">Vedi gli Orari</a>
            </div>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2025 Società Ferrovie Turistiche (SFT)</p>
        </div>
    </footer>
</body>
</html>