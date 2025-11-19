<?php
/**
 * API: Richiesta Pagamento
 * Riceve richieste di pagamento da applicazioni esterne (come SFT)
 * 
 * METODO: POST
 * 
 * PARAMETRI:
 * - url_chiamante: URL dell'applicazione che richiede il pagamento
 * - url_risposta: URL dove inviare l'esito del pagamento
 * - id_esercente: ID dell'esercente in Pay Steam
 * - descrizione: Descrizione del bene/servizio
 * - importo: Importo da pagare (decimale)
 * - id_transazione_esterna: (opzionale) ID della transazione nell'app esterna
 * 
 * RISPOSTA JSON:
 * Success: {success: true, codice_transazione: "...", url_autorizzazione: "..."}
 * Errore: {success: false, errore: "..."}
 */

require_once '../config.php';

// Imposta header per JSON
header('Content-Type: application/json');

// Verifica che sia una richiesta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        'success' => false,
        'errore' => 'Metodo non consentito. Usa POST.'
    ]);
    exit();
}

// Leggi i dati POST
$url_chiamante = trim($_POST['url_chiamante'] ?? '');
$url_risposta = trim($_POST['url_risposta'] ?? '');
$id_esercente = intval($_POST['id_esercente'] ?? 0);
$descrizione = trim($_POST['descrizione'] ?? '');
$importo = floatval($_POST['importo'] ?? 0);
$id_transazione_esterna = trim($_POST['id_transazione_esterna'] ?? '');

// Validazione parametri obbligatori
if (empty($url_chiamante)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errore' => 'Parametro obbligatorio mancante: url_chiamante'
    ]);
    exit();
}

if (empty($url_risposta)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errore' => 'Parametro obbligatorio mancante: url_risposta'
    ]);
    exit();
}

if ($id_esercente <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errore' => 'Parametro obbligatorio mancante o non valido: id_esercente'
    ]);
    exit();
}

if (empty($descrizione)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errore' => 'Parametro obbligatorio mancante: descrizione'
    ]);
    exit();
}

if ($importo <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errore' => 'Importo non valido. Deve essere maggiore di 0.'
    ]);
    exit();
}

// Connessione database
$conn = getDBConnection();

try {
    // Verifica che l'esercente esista e sia effettivamente un ESERCENTE
    $stmt = $conn->prepare("
        SELECT u.id_utente, u.nome, u.cognome, p.nome as profilo
        FROM utenti u
        JOIN profili p ON u.id_profilo = p.id_profilo
        WHERE u.id_utente = ? AND u.attivo = 1
    ");
    $stmt->bind_param("i", $id_esercente);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'errore' => 'Esercente non trovato o account non attivo'
        ]);
        exit();
    }
    
    $esercente = $result->fetch_assoc();
    
    if ($esercente['profilo'] !== 'ESERCENTE') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'errore' => 'L\'utente specificato non è un esercente'
        ]);
        exit();
    }
    
    // Genera codice transazione univoco
    $codice_transazione = generateTransactionCode();
    
    // Verifica che il codice sia univoco
    $stmt = $conn->prepare("SELECT id_transazione FROM transazioni WHERE codice_transazione = ?");
    $stmt->bind_param("s", $codice_transazione);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Se il codice esiste già, genera uno nuovo (molto improbabile)
    $tentativi = 0;
    while ($result->num_rows > 0 && $tentativi < 10) {
        $codice_transazione = generateTransactionCode();
        $stmt->bind_param("s", $codice_transazione);
        $stmt->execute();
        $result = $stmt->get_result();
        $tentativi++;
    }
    
    // Inserisci la transazione con stato IN_ATTESA
    $stmt = $conn->prepare("
        INSERT INTO transazioni 
        (codice_transazione, id_esercente, importo, descrizione, url_chiamante, url_risposta, id_transazione_esterna, stato)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'IN_ATTESA')
    ");
    $stmt->bind_param("sidssss", 
        $codice_transazione, 
        $id_esercente, 
        $importo, 
        $descrizione, 
        $url_chiamante, 
        $url_risposta, 
        $id_transazione_esterna
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Errore durante la creazione della transazione");
    }
    
    // Costruisci URL di autorizzazione
    $url_autorizzazione = SITE_URL . '/user/autorizza_pagamento.php?codice=' . urlencode($codice_transazione);
    
    // Risposta di successo
    http_response_code(201); // Created
    echo json_encode([
        'success' => true,
        'codice_transazione' => $codice_transazione,
        'url_autorizzazione' => $url_autorizzazione,
        'messaggio' => 'Transazione creata con successo. L\'utente deve autorizzare il pagamento.',
        'esercente' => [
            'nome' => $esercente['nome'] . ' ' . $esercente['cognome']
        ],
        'importo' => number_format($importo, 2, '.', ''),
        'descrizione' => $descrizione
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'errore' => 'Errore interno del server: ' . $e->getMessage()
    ]);
} finally {
    closeDBConnection($conn);
}
?>