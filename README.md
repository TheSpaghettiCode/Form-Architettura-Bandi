# Web App Gestione Budget Didattici

Una Single Page Application (SPA) ultra-leggera per gestire il budget docenti/attività partendo e arrivando **unicamente da file Excel**. Architettura server-side in PHP minimale, senza alcun database relazionale, perfetta per ambienti ristretti o in locale senza configurazioni IT pesanti.

## 🚀 Funzionalità
- **Nessun Database:** tutto viene letto dai file d'ingresso e salvato (Append/Sovrascrittura Inclusivo) nel master `.xlsx` risultante.
- **Validazione Dinamica Totali:** Il JS non permette salvataggi che sforano il budget base del corso.
- **Sincronia Smart:** Sovrascrive elegantemente solo i dati di un corso se già stato processato precedentemente, evitando record duplicati caotici ma non azzerando l'anagrafica base.
- **Intelligenza Dati UI:** Checkbox interattivi e layout "Glassmorphism Premium". Assieme ad una modale sicura anti-sovrascrittura involontaria.

## 📂 Struttura del Progetto (Git Tracker)

- `api/` -> Endpoint PHP. Contiene `process.php` (Parsing e Salva Dati su excel compilazioni)
- `css/` -> Foglio stile Premium `style.css`
- `js/` -> Logica SPA interattiva `script.js`
- `data/` -> Cartella destinata allo storage dei fogli di calcolo. Nel repo sono incluse solo le "matrici" input (*offerta_formativa* e *Budget_Architettura*).
- `index.php` -> Pagina Front-end e Interfaccia UI.
- `success.html` -> Pagina di fallback success.

## 🛠️ Come avviare su una nuova macchina (Setup & Run)

Il progetto sfrutta la potenza di ***PhpSpreadsheet***. Se hai appena fatto il "git clone", dovrai installare l'unica dipendenza usando Composer e far partire un server locale.

1. Installa tutte le dipendenze:
   ```bash
   composer install
   ```
2. Avvia il server (Assicurati di avere PHP installato o usando php_portable). Si può avviare comodamente dal file batch per Windows:
   ```bash
   avvia_server.bat
   ```
3. Vai all'indirizzo `http://localhost:8000` dal tuo browser preferito.

## ⚠️ Da escludere esplicitamente (non in Git)
Sono stati volutamente rimossi dal repository e inseriti nel `.gitignore`:
- Ambiente `/php_portable`
- Cartella Packages `/vendor`
- File risultante `data/compilazioni_docenti.xlsx` in quanto generato dinamicamente (conterrebbe dati privati!)
