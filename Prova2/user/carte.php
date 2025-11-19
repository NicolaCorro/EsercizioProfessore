<?php
require_once '../config.php';

// Verifica login e profilo UTENTE
requireProfile('UTENTE');

$conn = getDBConnection();
$error = '';
$success = '';

// Gestione aggiunta carta
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['azione'])) {
    $azione = $_POST['azione'];
    
    if ($azione == 'aggiungi') {
        $numero_carta = trim($_POST['numero_carta'] ?? '');
        $intestatario = trim($_POST['intestatario'] ?? '');
        $scadenza = trim($_POST['scadenza'] ?? '');
        $tipo_carta = $_POST['tipo_carta'] ?? 'VISA';
        
        // Validazione
        if (empty($numero_carta) || empty($intestatario) || empty($scadenza)) {
            $error = 'Tutti i campi sono obbligatori';
        } elseif (strlen($numero_carta) < 13 || strlen($numero_carta) > 19) {
            $error = 'Numero carta non valido';
        } elseif (!preg_match('/^\d{2}\/\d{4}$/', $scadenza)) {
            $error = 'Formato scadenza non valido (usa MM/YYYY)';
        } else {
            // Verifica se è la prima carta (diventa predefinita)
            $stmt = $conn->prepare("SELECT COUNT(*) as num_carte FROM carte_credito WHERE id_utente = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $predefinita = ($result['num_carte'] == 0) ? 1 : 0;
            
            // Inserisci carta
            $stmt = $conn->prepare("
                INSERT INTO carte_credito (id_utente, numero_carta, intestatario, scadenza, tipo_carta, predefinita)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issssi", $_SESSION['user_id'], $numero_carta, $intestatario, $scadenza, $tipo_carta, $predefinita);
            
            if ($stmt->execute()) {
                $success = 'Carta aggiunta con successo!';
            } else {
                $error = 'Errore durante l\'aggiunta della carta';
            }
        }
    } elseif ($azione == 'elimina') {
        $id_carta = intval($_POST['id_carta'] ?? 0);
        
        // Verifica che la carta appartenga all'utente
        $stmt = $conn->prepare("DELETE FROM carte_credito WHERE id_carta = ? AND id_utente = ?");
        $stmt->bind_param("ii", $id_carta, $_SESSION['user_id']);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success = 'Carta eliminata con successo';
        } else {
            $error = 'Impossibile eliminare la carta';
        }
    } elseif ($azione == 'predefinita') {
        $id_carta = intval($_POST['id_carta'] ?? 0);
        
        // Rimuovi predefinita da tutte le carte
        $stmt = $conn->prepare("UPDATE carte_credito SET predefinita = 0 WHERE id_utente = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        
        // Imposta nuova predefinita
        $stmt = $conn->prepare("UPDATE carte_credito SET predefinita = 1 WHERE id_carta = ? AND id_utente = ?");
        $stmt->bind_param("ii", $id_carta, $_SESSION['user_id']);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success = 'Carta predefinita aggiornata';
        }
    }
}

// Recupera tutte le carte
$stmt = $conn->prepare("
    SELECT * FROM carte_credito
    WHERE id_utente = ?
    ORDER BY predefinita DESC, data_inserimento DESC
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$carte = $stmt->get_result();

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Le Mie Carte - Pay Steam</title>
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
            max-width: 900px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .back-btn {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: opacity 0.3s;
        }
        
        .back-btn:hover {
            opacity: 0.8;
        }
        
        h1 {
            font-size: 1.8rem;
        }
        
        main {
            padding: 2rem 0;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .card h2 {
            color: #10b981;
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        input, select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #10b981;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #10b981;
            color: white;
        }
        
        .btn-primary:hover {
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
        
        .cards-list {
            display: grid;
            gap: 1rem;
        }
        
        .card-item {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            position: relative;
        }
        
        .card-item.default {
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
        }
        
        .card-type {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 1rem;
        }
        
        .card-number {
            font-family: monospace;
            font-size: 1.5rem;
            letter-spacing: 0.1rem;
            margin-bottom: 1rem;
        }
        
        .card-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 1rem;
        }
        
        .card-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .card-actions button {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            border: 1px solid white;
            background: transparent;
            color: white;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .card-actions button:hover {
            background: white;
            color: #10b981;
        }
        
        .default-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: white;
            color: #10b981;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        .info-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: #92400e;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="back-btn">
                    <span>←</span>
                    <span>Torna alla Dashboard</span>
                </a>
                <h1>💳 Le Mie Carte</h1>
            </div>
        </div>
    </header>

    <main>
        <div class="container">
            <?php if ($error): ?>
                <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <!-- Aggiungi Carta -->
            <div class="card">
                <h2>➕ Aggiungi Nuova Carta</h2>
                
                <div class="info-box">
                    ℹ️ <strong>Nota:</strong> In questa versione demo, i dati della carta vengono salvati per comodità. 
                    In produzione, le informazioni sensibili devono essere criptate!
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="azione" value="aggiungi">
                    
                    <div class="form-group">
                        <label>Numero Carta</label>
                        <input type="text" name="numero_carta" placeholder="1234 5678 9012 3456" 
                               maxlength="19" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Intestatario</label>
                        <input type="text" name="intestatario" placeholder="MARIO ROSSI" 
                               style="text-transform: uppercase;" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Scadenza (MM/YYYY)</label>
                            <input type="text" name="scadenza" placeholder="12/2027" 
                                   pattern="\d{2}/\d{4}" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Tipo Carta</label>
                            <select name="tipo_carta" required>
                                <option value="VISA">VISA</option>
                                <option value="MASTERCARD">MASTERCARD</option>
                                <option value="AMEX">AMERICAN EXPRESS</option>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Aggiungi Carta</button>
                </form>
            </div>
            
            <!-- Lista Carte -->
            <div class="card">
                <h2>🃏 Carte Salvate</h2>
                
                <?php if ($carte->num_rows > 0): ?>
                    <div class="cards-list">
                        <?php while ($carta = $carte->fetch_assoc()): ?>
                            <div class="card-item <?php echo $carta['predefinita'] ? 'default' : ''; ?>">
                                <?php if ($carta['predefinita']): ?>
                                    <span class="default-badge">✓ Predefinita</span>
                                <?php endif; ?>
                                
                                <div class="card-type">
                                    <?php echo htmlspecialchars($carta['tipo_carta']); ?>
                                </div>
                                
                                <div class="card-number">
                                    •••• •••• •••• <?php echo substr($carta['numero_carta'], -4); ?>
                                </div>
                                
                                <div class="card-info">
                                    <span><?php echo htmlspecialchars($carta['intestatario']); ?></span>
                                    <span>Scad. <?php echo htmlspecialchars($carta['scadenza']); ?></span>
                                </div>
                                
                                <div class="card-actions">
                                    <?php if (!$carta['predefinita']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="azione" value="predefinita">
                                            <input type="hidden" name="id_carta" value="<?php echo $carta['id_carta']; ?>">
                                            <button type="submit">Imposta come predefinita</button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Sei sicuro di voler eliminare questa carta?');">
                                        <input type="hidden" name="azione" value="elimina">
                                        <input type="hidden" name="id_carta" value="<?php echo $carta['id_carta']; ?>">
                                        <button type="submit">Elimina</button>
                                    </form>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">💳</div>
                        <p>Nessuna carta salvata</p>
                        <p style="font-size: 0.9rem; margin-top: 0.5rem;">Aggiungi la tua prima carta usando il form sopra</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>