<?php
// config.php - Configurazione Database

// Parametri connessione database
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ni_corro');

// Connessione al database
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Errore connessione: " . $conn->connect_error);

    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Funzione per chiudere la connessione
function closeDBConnection($conn) {
    if ($conn) {
        $conn->close();
    }
}

// =====================================================
// CONFIGURAZIONE PAY STEAM (Sistema di Pagamento)
// =====================================================
define('PAY_STEAM_URL', 'http://localhost/ProveItinere1/EsercizioProfessore/Prova2');
define('PAY_STEAM_API_URL', PAY_STEAM_URL . '/api/richiesta_pagamento.php');
define('PAY_STEAM_ESERCENTE_ID', 7); // ID esercente SFT su Pay Steam

// URL di callback per ricevere conferma pagamento
define('SFT_CALLBACK_URL', 'http://localhost/ProveItinere1/EsercizioProfessore/Prova1/api/conferma_pagamento.php');
define('SFT_BASE_URL', 'http://localhost/ProveItinere1/EsercizioProfessore/Prova1');

// =====================================================
// FUNZIONE: Richiedi Pagamento a Pay Steam
// =====================================================
/**
 * Invia richiesta di pagamento a Pay Steam
 * 
 * @param float $importo Importo da pagare
 * @param string $descrizione Descrizione del pagamento
 * @param string $id_transazione_esterna ID transazione SFT (codice prenotazione)
 * @return array Risposta da Pay Steam con success, codice_transazione, url_autorizzazione
 */
function richiestaPagamentoPaySteam($importo, $descrizione, $id_transazione_esterna) {
    $data = [
        'url_chiamante' => SFT_BASE_URL,
        'url_risposta' => SFT_CALLBACK_URL,
        'id_esercente' => PAY_STEAM_ESERCENTE_ID,
        'descrizione' => $descrizione,
        'importo' => $importo,
        'id_transazione_esterna' => $id_transazione_esterna
    ];
    error_log("SFT invia a Pay Steam: " . json_encode($data));
    
    // Inizializza cURL
    $ch = curl_init(PAY_STEAM_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ]);
    
    // Esegui richiesta
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Gestisci errori cURL
    if ($response === false) {
        return [
            'success' => false,
            'errore' => 'Errore di connessione a Pay Steam: ' . $curl_error
        ];
    }
    
    // Decodifica risposta JSON
    $result = json_decode($response, true);
    
    if ($result === null) {
        return [
            'success' => false,
            'errore' => 'Risposta non valida da Pay Steam'
        ];
    }
    
    // Aggiungi codice HTTP alla risposta
    $result['http_code'] = $http_code;
    
    return $result;
}

// Avvia sessione se non già attiva
if (session_status() === PHP_SESSION_NONE) {
	session_name('SFT_SESSION');
    session_start();
}
?>