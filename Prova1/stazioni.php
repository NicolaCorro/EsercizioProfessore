<?php
require_once 'config.php';

$conn = getDBConnection();

// Recupera tutte le stazioni ordinate per km
$query = "SELECT * FROM STAZIONE ORDER BY km_progressivo ASC";
$stazioni = $conn->query($query);

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Le Stazioni - SFT</title>
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
        
        .line-map {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .line-visual {
            position: relative;
            padding: 4rem 0;
        }
        
        .line {
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 2px;
            position: relative;
        }
        
        .station-marker {
            position: absolute;
            width: 20px;
            height: 20px;
            background: #667eea;
            border: 4px solid white;
            border-radius: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .station-marker:hover {
            transform: translate(-50%, -50%) scale(1.3);
            background: #764ba2;
        }
        
        .station-label {
            position: absolute;
            top: -40px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.75rem;
            white-space: nowrap;
            font-weight: 600;
            color: #667eea;
        }
        
        .stations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .station-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .station-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .station-number {
            display: inline-block;
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 40px;
            font-weight: bold;
            margin-bottom: 1rem;
        }
        
        .station-card h3 {
            color: #667eea;
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
        }
        
        .station-km {
            color: #999;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        .station-description {
            color: #666;
            line-height: 1.6;
        }
        
        .btn {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #5568d3;
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
            <h1>Le Stazioni della Linea SFT</h1>
            <p class="subtitle">54,68 km di storia e panorami mozzafiato</p>
        </div>
    </header>

    <nav>
        <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="stazioni.php" class="active">Le Stazioni</a></li>
            <li><a href="materiale.php">Materiale Rotabile</a></li>
            <li><a href="orari.php">Orari Treni</a></li>
        </ul>
    </nav>

    <main>
        <div class="container">
            <div class="intro">
                <h2>Il Nostro Percorso</h2>
                <p>La linea SFT attraversa 54,68 chilometri di territorio variegato, dalle colline dell'entroterra fino alle spiagge della costa. Ogni stazione racconta una storia unica e offre scorci panoramici indimenticabili.</p>
            </div>

			<div class="line-map">
				<h2 style="color: #667eea; margin-bottom: 2rem;">Mappa della Linea</h2>
				<div class="line-visual">
					<div class="line">
						<?php 
						$stazioni->data_seek(0);
						$counter_map = 0;
						while ($s = $stazioni->fetch_assoc()): 
							$percentage = ($s['km_progressivo'] / 54.68) * 100;
							$isEven = ($counter_map % 2 == 0);
							$counter_map++;
						?>
							<div class="station-marker" style="left: <?php echo $percentage; ?>%;">
								<span class="station-label" style="<?php echo $isEven ? 'top: -50px;' : 'top: 30px;'; ?>">
									<?php echo htmlspecialchars($s['nome']); ?>
								</span>
							</div>
						<?php endwhile; ?>
					</div>
				</div>
				<div style="display: flex; justify-content: space-between; margin-top: 4rem; color: #999; font-size: 0.9rem;">
					<span>Torre Spaventa (km 0)</span>
					<span>Villa San Felice (km 54,68)</span>
				</div>
			</div>

            <h2 style="color: #667eea; margin-bottom: 2rem;">Tutte le Stazioni</h2>
            <div class="stations-grid">
                <?php 
                $stazioni->data_seek(0);
                $counter = 1;
                while ($stazione = $stazioni->fetch_assoc()): 
                ?>
                    <div class="station-card">
                        <div class="station-number"><?php echo $counter++; ?></div>
                        <h3><?php echo htmlspecialchars($stazione['nome']); ?></h3>
                        <div class="station-km">
                            📍 Km <?php echo number_format($stazione['km_progressivo'], 3); ?>
                        </div>
                        <div class="station-description">
                            <?php echo htmlspecialchars($stazione['descrizione']); ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>

            <div style="text-align: center; margin-top: 3rem; padding: 2rem; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <h3 style="color: #667eea; margin-bottom: 1rem;">Pronto a partire?</h3>
                <p style="margin-bottom: 1.5rem;">Scegli la tua stazione di partenza e destinazione e prenota il tuo viaggio!</p>
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