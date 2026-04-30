<?php
// Avvia l'inclusione delle dipendenze, se disponibili (PhpSpreadsheet)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Inizializzazione variabili per il Frontend
$corsi = [];
$compilazioniPrecedenti = [];
$tariffe = [];

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
            
            // Struttura verticale: Nome(A), Costo(B), Con spesa(C)
            $highestRow = $sheet->getHighestDataRow();
            for ($row = 2; $row <= $highestRow; $row++) {
                $nome = trim((string)$sheet->getCell('A' . $row)->getValue());
                $costoStr = (string)$sheet->getCell('B' . $row)->getCalculatedValue();
                $conSpesaStr = trim((string)$sheet->getCell('C' . $row)->getCalculatedValue());
                
                if (!empty($nome)) {
                    $costo = (float)filter_var($costoStr, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    $conSpesa = ($conSpesaStr === '1' || strtolower($conSpesaStr) === 'true' || strtolower($conSpesaStr) === 'si');
                    $tariffe[$nome] = [
                        'costo' => $costo,
                        'con_spesa' => $conSpesa
                    ];
                }
            }
        }

        // 3. Leggi compilazioni pre-esistenti per permettere l'update
        $fileCompilazioni = __DIR__ . '/data/compilazioni_docenti.xlsx';
        if (file_exists($fileCompilazioni)) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileCompilazioni);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestDataRow();
            $highestCol = $sheet->getHighestDataColumn();
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
            
            $headers = [];
            for ($col = 1; $col <= $highestColIndex; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $headers[$colLetter] = trim((string)$sheet->getCell($colLetter . '1')->getValue());
            }
            
            for ($row = 2; $row <= $highestRow; $row++) {
                $corsoStr = (string)$sheet->getCell('D' . $row)->getValue();
                if (!empty($corsoStr)) {
                    $compData = [
                        'nome' => (string)$sheet->getCell('B' . $row)->getValue(),
                        'cognome' => (string)$sheet->getCell('C' . $row)->getValue(),
                        'competenze' => (string)$sheet->getCell('H' . $row)->getValue(),
                        'note' => (string)$sheet->getCell('I' . $row)->getValue()
                    ];
                    
                    foreach ($tariffe as $nomeAttivita => $dati) {
                        $inputName = strtolower(str_replace(' ', '_', $nomeAttivita));
                        $colAttiva = array_search("$nomeAttivita - Attiva", $headers);
                        $colColloquio = array_search("$nomeAttivita - Colloquio", $headers);
                        $colDettagli = array_search("$nomeAttivita - Dettagli", $headers);
                        
                        if ($colAttiva !== false && $colDettagli !== false) {
                            $attiva = (string)$sheet->getCell($colAttiva . $row)->getValue();
                            $colloquio = $colColloquio !== false ? (string)$sheet->getCell($colColloquio . $row)->getValue() : 'NO';
                            $dettagliJson = (string)$sheet->getCell($colDettagli . $row)->getValue();
                            
                            $compData["{$inputName}_attiva"] = (strtoupper($attiva) === 'SI');
                            $compData["{$inputName}_colloquio"] = (strtoupper($colloquio) === 'SI');
                            
                            $sottovoci = json_decode($dettagliJson, true);
                            if (is_array($sottovoci)) {
                                $compData["{$inputName}_sottovoci"] = $sottovoci;
                            } else {
                                $compData["{$inputName}_sottovoci"] = [];
                            }
                        }
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
foreach ($tariffe as $val) {
    if (isset($val['costo']) && $val['costo'] > 0) $allTariffeZero = false;
}
if (empty($tariffe) || $allTariffeZero) {
    $tariffe = [
        'Supporto contratti' => ['costo' => 32.00, 'con_spesa' => true],
        'Supporto studenti' => ['costo' => 20.00, 'con_spesa' => true],
        'Conferenze' => ['costo' => 0, 'con_spesa' => false],
        'Tutorato studenti' => ['costo' => 18.00, 'con_spesa' => true],
        'Tutorato dottorandi' => ['costo' => 22.00, 'con_spesa' => true]
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
                <label>Attività costo fisso</label>
                <div class="table-responsive">
                    <table class="modern-table" id="activitiesGridNoPrice">
                        <thead>
                            <tr>
                                <th style="width: 25%">Voce</th>
                                <th style="width: 10%">Attiva</th>
                                <th style="width: 50%">Dettagli (Costo)</th>
                                <th style="text-align: right; width: 15%">Somma Costi</th>
                            </tr>
                        </thead>
                        <tbody id="activitiesTableBodyNoPrice">
                            <!-- Dinamicamente popolato dal JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-group" style="margin-top: 2rem;">
                <label>Attività costo orario</label>
                <div class="table-responsive">
                    <table class="modern-table" id="activitiesGridPrice">
                        <thead>
                            <tr>
                                <th style="width: 25%">Voce</th>
                                <th style="width: 10%">Attiva</th>
                                <th style="width: 15%">Colloquio</th>
                                <th style="width: 35%">Dettagli (Persone , Ore)</th>
                                <th style="text-align: right; width: 15%">Somma Costi</th>
                            </tr>
                        </thead>
                        <tbody id="activitiesTableBodyPrice">
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

            <div class="form-group" style="margin-top: 2rem;">
                <label for="competenze">Competenze/requisiti richiesti</label>
                <textarea id="competenze" name="competenze" required placeholder="Descrivi brevemente un profilo di competenze e/o requisiti richiesti per il bando"></textarea>
            </div>

            <div class="form-group" style="margin-top: 2rem;">
                <label for="note">Note<small> (opzionale)</small></label>
                <textarea id="note" name="note" placeholder="Descrivi qui ulteriori dettagli relativi alla ripartizione dei supporti"></textarea>
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
    <script src="js/script.js?v=<?php echo time(); ?>"></script>
</body>
</html>
