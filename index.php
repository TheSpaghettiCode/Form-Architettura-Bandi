<?php
// Avvia l'inclusione delle dipendenze, se disponibili (PhpSpreadsheet)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Inizializzazione variabili per il Frontend
$corsi = [];
$compilazioniPrecedenti = [];
$tariffe = [
    'Supporto contratti' => 0,
    'Supporto studenti' => 0,
    'Conferenze' => 0,
    'Tutorato studenti' => 0,
    'Tutorato dottorandi' => 0
];

// FASE 1: LETTURA DATI
try {
    if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        // 1. Leggi offerta_formativa_2026-2027.xlsx
        // Cerchiamo qualsiasi file inizi per "offerta_formativa" nella dir data/
        $offertaFiles = glob(__DIR__ . '/data/offerta_formativa_*.xlsx');
        if (!empty($offertaFiles)) {
            $fileSorgente = $offertaFiles[0];
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileSorgente);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestDataRow();
            $highestCol = $sheet->getHighestDataColumn();
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
            
            // Intelligente ricerca delle colonne iterando le prime 3 righe (le intestazioni sono alla riga 2 solitamente)
            $colDocente = 'A'; $colCorso = 'D'; $colBudget = 'J';
            
            for ($r = 1; $r <= 3; $r++) {
                $rowIterator = $sheet->getRowIterator($r, $r)->current();
                if ($rowIterator) {
                    foreach ($rowIterator->getCellIterator() as $cell) {
                        $val = strtolower(trim((string)$cell->getValue()));
                        $col = $cell->getColumn();
                        if ($val === 'docente') $colDocente = $col;
                        elseif (strpos($val, 'denominazione corso') !== false) $colCorso = $col;
                        elseif (strpos($val, 'budget previsto per supporto') !== false || strpos($val, 'budget') !== false) $colBudget = $col;
                    }
                }
            }

            // I dati partono subito dopo l'ultima riga di intestazione individuata (che e' la riga 3)
            for ($row = 4; $row <= $highestRow; $row++) {
                $docente = (string)$sheet->getCell($colDocente . $row)->getValue();
                $corso = (string)$sheet->getCell($colCorso . $row)->getValue();
                $budgetStr = (string)$sheet->getCell($colBudget . $row)->getCalculatedValue();
                // Rimuoviamo eventuali formattazioni per estrarre il valore float
                $budget = (float)filter_var($budgetStr, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                
                if (!empty(trim($corso)) && !empty(trim($docente))) {
                    $corsi[] = [
                        'docente' => trim($docente),
                        'corso' => trim($corso),
                        'budget' => $budget
                    ];
                }
            }
        }

        // 2. Leggi Budget_Architettura.xlsx
        $fileTariffe = __DIR__ . '/data/Budget_Architettura.xlsx';
        if (file_exists($fileTariffe)) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileTariffe);
            $sheet = $spreadsheet->getActiveSheet();
            
            // La struttura orizzontale prevede gli handler alla riga 1 e i costi alla riga 2
            $headerRow = $sheet->getRowIterator(1, 1)->current();
            $costRow = $sheet->getRowIterator(2, 2)->current();
            
            if ($headerRow && $costRow) {
                foreach ($headerRow->getCellIterator() as $cell) {
                    $val = strtolower(trim((string)$cell->getValue()));
                    $col = $cell->getColumn();
                    
                    // Cerchiamo la tariffa corrispondente nella colonna
                    $costoStr = (string)$sheet->getCell($col . '2')->getCalculatedValue();
                    $costo = (float)filter_var($costoStr, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    
                    foreach ($tariffe as $key => $defaultVal) {
                        if (stripos($val, $key) !== false || stripos($key, $val) !== false) {
                            $tariffe[$key] = $costo;
                            break;
                        }
                    }
                }
            }
        }
        // 3. Leggi compilazioni pre-esistenti per permettere l'update
        $fileCompilazioni = __DIR__ . '/data/compilazioni_docenti.xlsx';
        if (file_exists($fileCompilazioni)) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileCompilazioni);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestDataRow();
            
            $attivitaKeys = ['ore_contratti', 'ore_studenti', 'ore_conferenze', 'ore_tut_studenti', 'ore_tut_dottorandi'];
            
            for ($row = 2; $row <= $highestRow; $row++) {
                $corsoStr = (string)$sheet->getCell('D' . $row)->getValue();
                if (!empty($corsoStr)) {
                    $compData = [
                        'nome' => (string)$sheet->getCell('B' . $row)->getValue(),
                        'cognome' => (string)$sheet->getCell('C' . $row)->getValue(),
                        'motivazioni' => (string)$sheet->getCell('H' . $row)->getValue()
                    ];
                    
                    $colIndex = 9;
                    foreach ($attivitaKeys as $ak) {
                        $attiva = (string)$sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $row)->getValue();
                        $studenti = (string)$sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $row)->getValue();
                        $ore = (string)$sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $row)->getValue();
                        $colloquio = (string)$sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $row)->getValue();
                        
                        // Per retrocompatibilità col nostro JS, estraiamo chiavi piatte
                        $compData["{$ak}_attiva"] = (strtoupper($attiva) === 'SI');
                        $compData["{$ak}_studenti"] = (int)$studenti;
                        $compData[$ak] = (float)$ore;
                        $compData["{$ak}_colloquio"] = (strtoupper($colloquio) === 'SI');
                    }
                    
                    $compilazioniPrecedenti[$corsoStr] = $compData;
                }
            }
        }
    }
} catch (Exception $e) {
    // Continua anche in caso di errori di lettura, usando null o array default se disastroso
}

// Fallback in caso di assenza file (utile per test frontend puro)
if (empty($corsi)) {
    $corsi = [
        ['docente' => 'Rossi Mario', 'corso' => 'Architettura del Paesaggio', 'budget' => 1500.00],
        ['docente' => 'Bianchi Lucia', 'corso' => 'Laboratorio di Urbanistica', 'budget' => 2000.00],
        ['docente' => 'Verdi Giuseppe', 'corso' => 'Storia dell\'Architettura', 'budget' => 800.00]
    ];
}
$allTariffeZero = true;
foreach ($tariffe as $val) if ($val > 0) $allTariffeZero = false;
if ($allTariffeZero) {
    $tariffe = [
        'Supporto contratti' => 25.50,
        'Supporto studenti' => 20.00,
        'Conferenze' => 50.00,
        'Tutorato studenti' => 18.00,
        'Tutorato dottorandi' => 22.00
    ];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Budget Didattici | Architettura</title>
    <!-- Modern Typography -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <div class="container">
        <header>
            <h1>Budget Didattico</h1>
            <p class="subtitle">Gestione risorse per supporto alla didattica</p>
        </header>

        <div id="success-msg" class="alert-success">Modulo inviato con successo!</div>

        <form id="budgetForm">
            <div class="grid-2">
                <div class="form-group">
                    <label for="nome">Nome Compilatore</label>
                    <input type="text" id="nome" name="nome" required placeholder="Inserisci il tuo nome">
                </div>
                <div class="form-group">
                    <label for="cognome">Cognome Compilatore</label>
                    <input type="text" id="cognome" name="cognome" required placeholder="Inserisci il tuo cognome">
                </div>
            </div>

            <div class="form-group">
                <label for="corso">Seleziona Insegnamento</label>
                <select id="corso" name="corso" required>
                    <option value="" disabled selected>Scegli un corso dall'elenco...</option>
                </select>
            </div>

            <div id="infoCard" class="info-card">
                <div class="info-item">
                    <h3>Docente di Riferimento</h3>
                    <p id="docenteDisplay">-</p>
                    <input type="hidden" id="docente" name="docente">
                </div>
                <div class="info-item">
                    <h3>Budget Previsto</h3>
                    <p id="budgetDisplay">€ 0.00</p>
                    <input type="hidden" id="budget_iniziale" name="budget_iniziale">
                </div>
            </div>

            <div class="form-group">
                <label>Voci di Spesa</label>
                <div class="table-responsive">
                    <table class="modern-table" id="activitiesGrid">
                        <thead>
                            <tr>
                                <th>Voce</th>
                                <th>Attiva</th>
                                <th>Studenti</th>
                                <th>Ore</th>
                                <th>Colloquio</th>
                                <th style="text-align: right;">Costo Calcolato</th>
                            </tr>
                        </thead>
                        <tbody id="activitiesTableBody">
                            <!-- Dinamicamente popolato dal JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="total-section">
                <div class="total-label">Costo Totale:</div>
                <div class="total-value" id="totalValue">€ 0.00</div>
                <input type="hidden" id="costo_totale" name="costo_totale" value="0">
            </div>

            <div id="budgetAlert" class="alert-message">
                ⚠️ Attenzione! Il costo totale supera il budget previsto per questo corso. Non è possibile inviare la richiesta.
            </div>

            <div class="form-group">
                <label for="motivazioni">Motivazioni della richiesta</label>
                <textarea id="motivazioni" name="motivazioni" placeholder="Descrivi brevemente i motivi della ripartizione..."></textarea>
            </div>

            <button type="submit" id="submitBtn" class="btn-submit" disabled>
                <span id="btnText">Invia Richiesta</span>
                <div class="spinner" id="btnSpinner"></div>
            </button>
        </form>
    </div>

    <!-- Passaggio Dati Backend -> Frontend -->
    <div id="customConfirmModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-icon">⚠️</div>
            <h2>Attenzione Sostituzione</h2>
            <p>Risulta già una richiesta inviata per questa materia.<br><br>Procedendo, la NUOVA richiesta <b>sovrascriverà e cancellerà</b> quella esistente.<br>Vuoi confermare?</p>
            <div class="modal-actions">
                <button class="btn-cancel" id="btnCancelModal">Annulla</button>
                <button class="btn-confirm" id="btnConfirmModal">Sì, Sovrascrivi</button>
            </div>
        </div>
    </div>

    <script>
        const corsiData = <?php echo json_encode($corsi, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const tariffeData = <?php echo json_encode($tariffe, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const compilazioniPrecedentiData = <?php echo json_encode($compilazioniPrecedenti, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="js/script.js"></script>
</body>
</html>
