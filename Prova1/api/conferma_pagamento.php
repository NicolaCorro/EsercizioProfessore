<?php
/**
 * API: Conferma Pagamento
 * Riceve la risposta da Pay Steam dopo l'autorizzazione del pagamento
 * 
 * METODO: POST
 * 
 * PARAMETRI:
 * - url_chiamante: URL di Pay Steam (per verifica)
 * - id_transazione: Codice transazione Pay Steam
 * - esito: OK o KO
 * 
 * RISPOSTA JSON:
 * Success: {success: true, messaggio: "..."}
 * Errore: {success: false, errore: "..."}
 */

require_once '../config.php';

// Imposta header per JSON
header('Content-Type: application/json');

// Log per debug (opzionale - commentare in produzione)
$log_data = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'post' => $_POST,
    'get' => $_GET
];
error_log("API conferma_pagamento chiamata: " . json_encode($log_data));

// Verifica che sia una richiesta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        'success' => false,
        'errore' => 'Metodo non consentito. Usa POST.'
    ]);
    exit();
}

// Leggi i parametri
$url_chiamante = trim($_POST['url_chiamante'] ?? '');
$id_transazione = trim($_POST['id_transazione'] ?? '');
$esito = strtoupper(trim($_POST['esito'] ?? ''));

// Validazione parametri
if (empty($url_chiamante)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errore' => 'Parametro obbligatorio mancante: url_chiamante'
    ]);
    exit();
}

if (empty($id_transazione)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errore' => 'Parametro obbligatorio mancante: id_transazione'
    ]);
    exit();
}

if ($esito !== 'OK' && $esito !== 'KO') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errore' => 'Parametro esito non valido. Deve essere OK o KO.'
    ]);
    exit();
}

// Verifica che la chiamata provenga da Pay Steam
if (strpos($url_chiamante, 'pay') === false && strpos($url_chiamante, 'steam') === false) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'errore' => 'Chiamata non autorizzata'
    ]);
    exit();
}

// Connessione database
$conn = getDBConnection();
error_log("DEBUG: Cerco prenotazione con codice: " . $id_transazione);
try {
    // Cerca la prenotazione associata a questa transazione Pay Steam
    // Il codice transazione Pay Steam è salvato nel campo codice_pagamento del BIGLIETTO
    $stmt = $conn->prepare("
        SELECT 
            p.id_prenotazione,
            p.id_utente,
            p.id_treno,
            p.id_posto,
            p.stato,
            p.codice_prenotazione,
            b.id_biglietto,
            b.importo,
            b.stato_pagamento
        FROM prenotazioni p
        LEFT JOIN biglietti b ON p.id_prenotazione = b.id_prenotazione
        WHERE b.codice_pagamento = ? OR p.codice_prenotazione = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $id_transazione, $id_transazione);
    $stmt->execute();
    $result = $stmt->get_result();
    
    error_log("DEBUG: ID transazione cercato: " . $id_transazione);
    
    if ($result->num_rows === 0) {
        // Prova a cercare solo per codice_prenotazione come fallback
        // (per gestire il caso in cui il biglietto non sia ancora stato creato)
        $stmt = $conn->prepare("
            SELECT 
                p.id_prenotazione,
                p.id_utente,
                p.id_treno,
                p.id_posto,
                p.stato,
                p.codice_prenotazione
            FROM prenotazioni p
            WHERE p.codice_prenotazione = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $id_transazione);
        $stmt->execute();
        $result = $stmt->get_result();
        error_log("DEBUG: Seconda query (fallback) - righe trovate: " . $result->num_rows);  // ⬅️ Testo più chiaro
        
        if ($result->num_rows === 0) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'errore' => 'Prenotazione non trovata per la transazione specificata'
            ]);
            exit();
        }
    }
    
    $prenotazione = $result->fetch_assoc();
    
    // Inizia transazione SQL per atomicità
    $conn->begin_transaction();

    $prenotazione = $result->fetch_assoc();
    error_log("DEBUG: Prenotazione trovata - ID: " . $prenotazione['id_prenotazione'] . ", Stato: " . $prenotazione['stato']);
    if ($esito === 'OK') {
        // PAGAMENTO COMPLETATO CON SUCCESSO
        
        // Verifica che la prenotazione sia in stato IN_ATTESA_PAGAMENTO
        if ($prenotazione['stato'] !== 'IN_ATTESA_PAGAMENTO') {
            $conn->rollback();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'errore' => 'La prenotazione non è in attesa di pagamento (stato: ' . $prenotazione['stato'] . ')'
            ]);
            exit();
        }
        
        // Aggiorna stato prenotazione a CONFERMATA
        $stmt = $conn->prepare("
            UPDATE prenotazioni 
            SET stato = 'CONFERMATA' 
            WHERE id_prenotazione = ?
        ");
        $stmt->bind_param("i", $prenotazione['id_prenotazione']);
        
        if (!$stmt->execute()) {
            throw new Exception("Errore nell'aggiornamento della prenotazione");
        }
        
        // Verifica se il biglietto esiste già
        if (isset($prenotazione['id_biglietto']) && $prenotazione['id_biglietto']) {
            // Aggiorna biglietto esistente
            $stmt = $conn->prepare("
                UPDATE biglietti 
                SET stato_pagamento = 'PAGATO',
                    codice_pagamento = ?
                WHERE id_biglietto = ?
            ");
            $stmt->bind_param("si", $id_transazione, $prenotazione['id_biglietto']);
            
            if (!$stmt->execute()) {
                throw new Exception("Errore nell'aggiornamento del biglietto");
            }
        } else {
            // Crea nuovo biglietto
            $stmt = $conn->prepare("
                INSERT INTO biglietti 
                (id_prenotazione, importo, codice_pagamento, stato_pagamento)
                VALUES (?, ?, ?, 'PAGATO')
            ");
            $importo = $prenotazione['importo'] ?? 0;
            $stmt->bind_param("ids", $prenotazione['id_prenotazione'], $importo, $id_transazione);
            
            if (!$stmt->execute()) {
                throw new Exception("Errore nella creazione del biglietto");
            }
        }
        
        // Commit transazione
        $conn->commit();
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'messaggio' => 'Pagamento confermato con successo',
            'prenotazione' => [
                'codice' => $prenotazione['codice_prenotazione'],
                'stato' => 'CONFERMATA'
            ]
        ]);
        
    } else {
        // PAGAMENTO RIFIUTATO O ANNULLATO (esito = KO)
        
        // Annulla la prenotazione
        $stmt = $conn->prepare("
            UPDATE prenotazioni 
            SET stato = 'ANNULLATA' 
            WHERE id_prenotazione = ?
        ");
        $stmt->bind_param("i", $prenotazione['id_prenotazione']);
        
        if (!$stmt->execute()) {
            throw new Exception("Errore nell'annullamento della prenotazione");
        }
        
        // Se esiste un biglietto, aggiornalo
        if (isset($prenotazione['id_biglietto']) && $prenotazione['id_biglietto']) {
            $stmt = $conn->prepare("
                UPDATE biglietti 
                SET stato_pagamento = 'NON_PAGATO',
                    codice_pagamento = ?
                WHERE id_biglietto = ?
            ");
            $stmt->bind_param("si", $id_transazione, $prenotazione['id_biglietto']);
            $stmt->execute();
        }
        
        // Commit transazione
        $conn->commit();
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'messaggio' => 'Pagamento annullato, prenotazione cancellata',
            'prenotazione' => [
                'codice' => $prenotazione['codice_prenotazione'],
                'stato' => 'ANNULLATA'
            ]
        ]);
    }
    
} catch (Exception $e) {
    // Rollback in caso di errore
    if ($conn) {
        $conn->rollback();
    }
    error_log("ERRORE in conferma_pagamento.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'errore' => 'Errore interno del server: ' . $e->getMessage()
    ]);
    
} finally {
    closeDBConnection($conn);
}
?>