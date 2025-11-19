<?php
/**
 * File di configurazione Pay Steam
 * Sistema di Pagamento Online
 * Prova In Itinere 2
 */

// Avvia la sessione se non già avviata
if (session_status() === PHP_SESSION_NONE) {
	session_name('PAY_STEAM_SESSION');
    session_start();
}

// =====================================================
// CONFIGURAZIONE DATABASE
// =====================================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'pay_steam');

// =====================================================
// CONFIGURAZIONE APPLICAZIONE
// =====================================================
define('SITE_NAME', 'Pay Steam');
define('SITE_URL', 'http://localhost/ProveItinere1/Prova2');
define('API_URL', SITE_URL . '/api');

// =====================================================
// FUNZIONE: Connessione Database
// =====================================================
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Errore connessione database: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// =====================================================
// FUNZIONE: Chiusura Connessione Database
// =====================================================
function closeDBConnection($conn) {
    if ($conn) {
        $conn->close();
    }
}

// =====================================================
// FUNZIONE: Verifica Autenticazione
// =====================================================
function requireLogin() {
if (!isset($_SESSION['user_id'])) {
        $_SESSION['redirect_dopo_login'] = $_SERVER['REQUEST_URI'];
        
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }
}

// =====================================================
// FUNZIONE: Verifica Profilo
// =====================================================
function requireProfile($profile) {
    requireLogin();
    if ($_SESSION['user_profile'] != $profile) {
        header('Location: ' . SITE_URL . '/index.php');
        exit();
    }
}

// =====================================================
// FUNZIONE: Genera Codice Transazione Univoco
// =====================================================
function generateTransactionCode() {
    // Formato: PS + YYYYMMDD + numero progressivo 4 cifre
    $date = date('Ymd');
    $random = sprintf('%04d', rand(1, 9999));
    return 'PS' . $date . $random;
}

// =====================================================
// FUNZIONE: Formatta Importo
// =====================================================
function formatAmount($amount) {
    return '€ ' . number_format($amount, 2, ',', '.');
}

// =====================================================
// FUNZIONE: Sanitize Input
// =====================================================
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// =====================================================
// FUNZIONE: Esegui Transazione (Atomica)
// =====================================================
function executeTransaction($conn, $id_transazione, $id_cliente, $id_esercente, $importo) {
    // Inizia transazione SQL
    $conn->begin_transaction();
    
    try {
        // 1. Recupera conti
        $stmt = $conn->prepare("SELECT id_conto, saldo FROM CONTO WHERE id_utente = ?");
        
        // Conto cliente
        $stmt->bind_param("i", $id_cliente);
        $stmt->execute();
        $conto_cliente = $stmt->get_result()->fetch_assoc();
        
        if (!$conto_cliente || $conto_cliente['saldo'] < $importo) {
            throw new Exception("Saldo insufficiente");
        }
        
        // Conto esercente
        $stmt->bind_param("i", $id_esercente);
        $stmt->execute();
        $conto_esercente = $stmt->get_result()->fetch_assoc();
        
        if (!$conto_esercente) {
            throw new Exception("Conto esercente non trovato");
        }
        
        // 2. Aggiorna saldi
        $nuovo_saldo_cliente = $conto_cliente['saldo'] - $importo;
        $nuovo_saldo_esercente = $conto_esercente['saldo'] + $importo;
        
        $stmt = $conn->prepare("UPDATE CONTO SET saldo = ? WHERE id_conto = ?");
        
        $stmt->bind_param("di", $nuovo_saldo_cliente, $conto_cliente['id_conto']);
        $stmt->execute();
        
        $stmt->bind_param("di", $nuovo_saldo_esercente, $conto_esercente['id_conto']);
        $stmt->execute();
        
        // 3. Registra movimenti
        $causale_uscita = "Pagamento transazione " . $id_transazione;
        $causale_entrata = "Incasso transazione " . $id_transazione;
        
        $stmt = $conn->prepare("
            INSERT INTO MOVIMENTO (id_conto, id_transazione, tipo, importo, causale, saldo_precedente, saldo_nuovo)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        // Movimento USCITA cliente
        $tipo_uscita = 'USCITA';
        $stmt->bind_param("iisdsdd", 
            $conto_cliente['id_conto'], 
            $id_transazione, 
            $tipo_uscita, 
            $importo, 
            $causale_uscita, 
            $conto_cliente['saldo'], 
            $nuovo_saldo_cliente
        );
        $stmt->execute();
        
        // Movimento ENTRATA esercente
        $tipo_entrata = 'ENTRATA';
        $stmt->bind_param("iisdsdd", 
            $conto_esercente['id_conto'], 
            $id_transazione, 
            $tipo_entrata, 
            $importo, 
            $causale_entrata, 
            $conto_esercente['saldo'], 
            $nuovo_saldo_esercente
        );
        $stmt->execute();
        
        // 4. Aggiorna stato transazione
        $stmt = $conn->prepare("
            UPDATE TRANSAZIONE 
            SET stato = 'COMPLETATA', data_completamento = NOW() 
            WHERE id_transazione = ?
        ");
        $stmt->bind_param("i", $id_transazione);
        $stmt->execute();
        
        // Commit transazione
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        // Rollback in caso di errore
        $conn->rollback();
        return false;
    }
}

// =====================================================
// FUNZIONE: Invia Risposta API
// =====================================================
function sendAPIResponse($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    return ['response' => $response, 'http_code' => $http_code];
}

// =====================================================
// CONFIGURAZIONE TIMEZONE
// =====================================================
date_default_timezone_set('Europe/Rome');

// =====================================================
// ERROR REPORTING (solo in sviluppo!)
// =====================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>