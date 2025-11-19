<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test API - Richiesta Pagamento</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        h1 {
            color: #10b981;
            margin-bottom: 1rem;
        }
        
        .info {
            background: #f0fdf4;
            border-left: 4px solid #10b981;
            padding: 1rem;
            margin-bottom: 2rem;
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
        
        input, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
        }
        
        input:focus, textarea:focus {
            outline: none;
            border-color: #10b981;
        }
        
        button {
            background: #10b981;
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        button:hover {
            background: #059669;
        }
        
        #response {
            margin-top: 2rem;
            padding: 1.5rem;
            border-radius: 8px;
            display: none;
        }
        
        #response.success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        
        #response.error {
            background: #fee;
            border-left: 4px solid #c33;
            color: #c33;
        }
        
        pre {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            overflow-x: auto;
            margin-top: 1rem;
        }
        
        .url-link {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.5rem 1rem;
            background: #10b981;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .url-link:hover {
            background: #059669;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Test API - Richiesta Pagamento</h1>
        
        <div class="info">
            <p><strong>Questa pagina simula una chiamata da SFT a Pay Steam.</strong></p>
            <p>Compila il form e clicca "Invia Richiesta" per testare l'API di richiesta pagamento.</p>
        </div>
        
        <form id="testForm">
            <div class="form-group">
                <label>URL Chiamante (URL app richiedente):</label>
                <input type="text" name="url_chiamante" value="http://localhost/ProveItinere1/Prova1" required>
            </div>
            
            <div class="form-group">
                <label>URL Risposta (dove ricevere l'esito):</label>
                <input type="text" name="url_risposta" value="http://localhost/ProveItinere1/Prova1/api/conferma_pagamento.php" required>
            </div>
            
            <div class="form-group">
                <label>ID Esercente Pay Steam:</label>
                <input type="number" name="id_esercente" value="3" required>
                <small style="color: #666;">3 = SFT Ferrovie, 4 = Hotel Roma</small>
            </div>
            
            <div class="form-group">
                <label>Descrizione pagamento:</label>
                <textarea name="descrizione" rows="3" required>Biglietto treno SFT - Treno 101 del 25/11/2025 da Torre Spaventa a Villa San Felice</textarea>
            </div>
            
            <div class="form-group">
                <label>Importo (€):</label>
                <input type="number" name="importo" value="27.34" step="0.01" min="0.01" required>
            </div>
            
            <div class="form-group">
                <label>ID Transazione Esterna (opzionale):</label>
                <input type="text" name="id_transazione_esterna" value="SFT20251118TEST001">
                <small style="color: #666;">Es: ID della prenotazione in SFT</small>
            </div>
            
            <button type="submit">🚀 Invia Richiesta</button>
        </form>
        
        <div id="response"></div>
    </div>
    
    <script>
        document.getElementById('testForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            const responseDiv = document.getElementById('response');
            
            try {
                responseDiv.style.display = 'block';
                responseDiv.className = '';
                responseDiv.innerHTML = '⏳ Invio richiesta in corso...';
                
                const response = await fetch('api/richiesta_pagamento.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    responseDiv.className = 'success';
                    responseDiv.innerHTML = `
                        <h3>✅ Transazione Creata con Successo!</h3>
                        <p><strong>Codice Transazione:</strong> ${data.codice_transazione}</p>
                        <p><strong>Importo:</strong> €${data.importo}</p>
                        <p><strong>Descrizione:</strong> ${data.descrizione}</p>
                        <p><strong>Esercente:</strong> ${data.esercente.nome}</p>
                        <p style="margin-top: 1rem;"><strong>L'utente deve andare a questo URL per autorizzare:</strong></p>
                        <a href="${data.url_autorizzazione}" class="url-link" target="_blank">
                            🔗 Vai alla pagina di autorizzazione
                        </a>
                        <pre><strong>Risposta JSON completa:</strong>
${JSON.stringify(data, null, 2)}</pre>
                    `;
                } else {
                    responseDiv.className = 'error';
                    responseDiv.innerHTML = `
                        <h3>❌ Errore</h3>
                        <p>${data.errore}</p>
                        <pre><strong>Risposta JSON:</strong>
${JSON.stringify(data, null, 2)}</pre>
                    `;
                }
            } catch (error) {
                responseDiv.className = 'error';
                responseDiv.innerHTML = `
                    <h3>❌ Errore di rete</h3>
                    <p>${error.message}</p>
                `;
            }
        });
    </script>
</body>
</html>