<?php
require_once 'config.php';

// Verifica che sia stato passato l'ID del treno
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: orari.php');
    exit();
}

$id_treno = (int)$_GET['id'];

$conn = getDBConnection();

// Recupera informazioni treno
$stmt = $conn->prepare("
    SELECT 
        t.id_treno,
        t.numero_treno,
        t.data_partenza,
        t.direzione,
        t.stato,
        c.nome as nome_convoglio,
        c.posti_totali,
        sp.nome as stazione_partenza,
        sa.nome as stazione_arrivo
    FROM treni t
    JOIN convogli c ON t.id_convoglio = c.id_convoglio
    JOIN stazioni sp ON t.id_stazione_partenza = sp.id_stazione
    JOIN stazioni sa ON t.id_stazione_arrivo = sa.id_stazione
    WHERE t.id_treno = ?
");
$stmt->bind_param("i", $id_treno);
$stmt->execute();
$treno = $stmt->get_result()->fetch_assoc();

if (!$treno) {
    header('Location: orari.php');
    exit();
}

// Recupera tutte le fermate del treno
$stmt = $conn->prepare("
    SELECT 
        f.id_fermata,
        f.orario_arrivo,
        f.orario_partenza,
        f.ordine_fermata,
        s.id_stazione,
        s.nome as stazione,
        s.km_progressivo
    FROM fermate f
    JOIN stazioni s ON f.id_stazione = s.id_stazione
    WHERE f.id_treno = ?
    ORDER BY f.ordine_fermata ASC
");
$stmt->bind_param("i", $id_treno);
$stmt->execute();
$fermate = $stmt->get_result();

// Recupera composizione convoglio
$stmt = $conn->prepare("
    SELECT 
        m.sigla,
        m.nome,
        m.posti_sedere,
        tm.nome as tipo,
        cc.posizione
    FROM composizioni cc
    JOIN materiale_rotabile m ON cc.id_materiale = m.id_materiale
    JOIN tipi_materiale tm ON m.id_tipo = tm.id_tipo
    JOIN treni t ON cc.id_convoglio = t.id_convoglio
    WHERE t.id_treno = ?
    ORDER BY cc.posizione ASC
");
$stmt->bind_param("i", $id_treno);
$stmt->execute();
$composizione = $stmt->get_result();

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treno <?php echo htmlspecialchars($treno['numero_treno']); ?> - SFT</title>
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
        
        .back-link {
            display: inline-block;
            color: white;
            text-decoration: none;
            margin-bottom: 1rem;
            opacity: 0.9;
            transition: opacity 0.3s;
        }
        
        .back-link:hover {
            opacity: 1;
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
        
        .train-summary {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .train-summary h2 {
            color: #667eea;
            margin-bottom: 1rem;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .summary-item {
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .summary-label {
            font-size: 0.85rem;
            color: #999;
            margin-bottom: 0.25rem;
        }
        
        .summary-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }
        
        .section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .section h3 {
            color: #667eea;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
        }
        
        .stop {
            position: relative;
            padding: 1.5rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            margin-left: 1rem;
        }
        
        .stop::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            background: white;
            border: 4px solid #667eea;
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .stop.first::before {
            background: #667eea;
        }
        
        .stop.last::before {
            background: #764ba2;
        }
        
        .stop-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .stop-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: #667eea;
        }
        
        .stop-order {
            background: #667eea;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .stop-times {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .time-box {
            padding: 0.75rem;
            background: white;
            border-radius: 6px;
        }
        
        .time-label {
            font-size: 0.8rem;
            color: #999;
            margin-bottom: 0.25rem;
        }
        
        .time-value {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
        }
        
        .km-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: #e3f2fd;
            color: #1976d2;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }
        
        .composition-grid {
            display: grid;
            gap: 1rem;
        }
        
        .material-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .position-badge {
            width: 40px;
            height: 40px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .material-info {
            flex: 1;
        }
        
        .material-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .material-type {
            font-size: 0.9rem;
            color: #666;
        }
        
        .seats-badge {
            padding: 0.5rem 1rem;
            background: #d4edda;
            color: #155724;
            border-radius: 20px;
            font-weight: 600;
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
            text-align: center;
        }
        
        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .cta-box {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            color: white;
        }
        
        .cta-box h3 {
            color: white;
            border: none;
            margin-bottom: 1rem;
        }
        
        footer {
            background: #333;
            color: white;
            text-align: center;
            padding: 2rem 0;
            margin-top: 3rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <a href="orari.php" class="back-link">← Torna agli orari</a>
            <h1>Treno <?php echo htmlspecialchars($treno['numero_treno']); ?></h1>
            <p class="subtitle"><?php echo htmlspecialchars($treno['nome_convoglio']); ?> - <?php echo date('d/m/Y', strtotime($treno['data_partenza'])); ?></p>
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
            <div class="train-summary">
                <h2>Informazioni Generali</h2>
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-label">Tratta</div>
                        <div class="summary-value">
                            <?php echo htmlspecialchars($treno['stazione_partenza']); ?> → 
                            <?php echo htmlspecialchars($treno['stazione_arrivo']); ?>
                        </div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Direzione</div>
                        <div class="summary-value"><?php echo htmlspecialchars($treno['direzione']); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Stato</div>
                        <div class="summary-value"><?php echo htmlspecialchars($treno['stato']); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Posti Totali</div>
                        <div class="summary-value"><?php echo $treno['posti_totali']; ?> posti</div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>🚉 Fermate e Orari</h3>
                <div class="timeline">
                    <?php 
                    $fermate->data_seek(0);
                    $count = $fermate->num_rows;
                    $index = 0;
                    while ($fermata = $fermate->fetch_assoc()): 
                        $index++;
                        $is_first = ($index == 1);
                        $is_last = ($index == $count);
                        $class = $is_first ? 'first' : ($is_last ? 'last' : '');
                    ?>
                        <div class="stop <?php echo $class; ?>">
                            <div class="stop-header">
                                <div class="stop-name"><?php echo htmlspecialchars($fermata['stazione']); ?></div>
                                <div class="stop-order">Fermata <?php echo $fermata['ordine_fermata']; ?></div>
                            </div>
                            
                            <div class="stop-times">
                                <div class="time-box">
                                    <div class="time-label"><?php echo $is_first ? 'Partenza' : 'Arrivo'; ?></div>
                                    <div class="time-value">
                                        <?php echo date('H:i', strtotime($fermata['orario_arrivo'])); ?>
                                    </div>
                                </div>
                                
                                <?php if (!$is_last): ?>
                                <div class="time-box">
                                    <div class="time-label">Partenza</div>
                                    <div class="time-value">
                                        <?php echo date('H:i', strtotime($fermata['orario_partenza'])); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <span class="km-badge">📍 Km <?php echo number_format($fermata['km_progressivo'], 2); ?></span>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>

            <div class="section">
                <h3>🚂 Composizione Convoglio</h3>
                <div class="composition-grid">
                    <?php while ($materiale = $composizione->fetch_assoc()): ?>
                        <div class="material-item">
                            <div class="position-badge"><?php echo $materiale['posizione']; ?></div>
                            <div class="material-info">
                                <div class="material-name">
                                    <?php echo htmlspecialchars($materiale['sigla']); ?>
                                    <?php if ($materiale['nome']): ?>
                                        - <?php echo htmlspecialchars($materiale['nome']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="material-type"><?php echo htmlspecialchars($materiale['tipo']); ?></div>
                            </div>
                            <?php if ($materiale['posti_sedere'] > 0): ?>
                                <div class="seats-badge"><?php echo $materiale['posti_sedere']; ?> posti</div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>

            <?php if (isset($_SESSION['user_id']) && $_SESSION['user_profile'] == 'REGISTRATO'): ?>
                <div class="cta-box">
                    <h3>Prenota il tuo posto!</h3>
                    <p style="margin-bottom: 1.5rem;">Scegli la tua fermata di partenza e destinazione</p>
                    <a href="user/prenota.php?treno=<?php echo $id_treno; ?>" class="btn btn-success">
                        Prenota Ora
                    </a>
                </div>
            <?php else: ?>
                <div class="cta-box">
                    <h3>Vuoi prenotare questo treno?</h3>
                    <p style="margin-bottom: 1.5rem;">Registrati gratuitamente per prenotare i tuoi biglietti!</p>
                    <a href="register.php" class="btn" style="background: white; color: #667eea;">
                        Registrati Ora
                    </a>
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