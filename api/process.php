<?php
// FASE 3: SALVATAGGIO DATI
header('Content-Type: application/json');

// Impedisci accessi non POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito.']);
    exit;
}

if (!file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    echo json_encode(['success' => false, 'message' => 'Libreria PhpSpreadsheet non trovata. Esegui composer install.']);
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function sanitize($input) {
    if (is_null($input)) return '';
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

$postData = $_POST;
if (empty($postData)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $postData = $decoded;
    }
}

// Estrai e sanitizza i dati dal form
$nome = sanitize($postData['nome'] ?? '');
$cognome = sanitize($postData['cognome'] ?? '');
$corso = sanitize($postData['corso'] ?? '');
$docente = sanitize($postData['docente'] ?? '');
$budget_iniziale = (float)($postData['budget_iniziale'] ?? 0);
$costo_totale = (float)($postData['costo_totale'] ?? 0);
$competenze = sanitize($postData['competenze'] ?? '');
$note = sanitize($postData['note'] ?? '');

// Estrazione campi attività dinamici da Budget_Architettura.xlsx
$fileTariffe = dirname(__DIR__) . '/data/Budget_Architettura.xlsx';
$titoliAttivita = [];
if (file_exists($fileTariffe)) {
    $spreadsheetBudget = IOFactory::load($fileTariffe);
    $sheetBudget = $spreadsheetBudget->getActiveSheet();
    $highestRowBudget = $sheetBudget->getHighestDataRow();
    for ($row = 2; $row <= $highestRowBudget; $row++) {
        $nomeAtt = trim((string)$sheetBudget->getCell('A' . $row)->getValue());
        if (!empty($nomeAtt)) {
            $titoliAttivita[] = $nomeAtt;
        }
    }
}

// Fallback nel caso in cui il file non esista
if (empty($titoliAttivita)) {
    $titoliAttivita = [
        'Supporto contratti', 'Supporto studenti', 'Conferenze', 
        'Tutorato studenti', 'Tutorato dottorandi'
    ];
}

$minLimiti = [
    'supporto_contratti' => ['minOre' => 9],
    'supporto_studenti' => ['minOre' => 30],
    'tutorato_studenti' => ['minOre' => 14],
    'tutorato_dottorandi' => ['minOre' => 10],
    'conferenze' => ['minCosto' => 100, 'maxCosto' => 600]
];

$datiAttivita = [];
foreach ($titoliAttivita as $titolo) {
    $inputName = strtolower(str_replace(' ', '_', $titolo));
    $dettagliRaw = $postData["{$inputName}_dettagli"] ?? '[]';
    
    // Validazione base per assicurarsi che sia JSON
    json_decode($dettagliRaw);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $dettagliRaw = '[]';
    }

    $attiva = isset($postData["{$inputName}_attiva"]) ? 1 : 0;
    
    // Validazione dei limiti lato server
    if ($attiva) {
        $details = json_decode($dettagliRaw, true);
        if (is_array($details)) {
            foreach ($details as $idx => $item) {
                if ($inputName === 'conferenze') {
                    $costo = (float)($item['costo'] ?? 0);
                    if ($costo < 100 || $costo > 600) {
                        echo json_encode(['success' => false, 'message' => "Il costo della Persona #" . ($idx + 1) . " per l'attività 'Conferenze' deve essere compreso tra 100€ e 600€."]);
                        exit;
                    }
                } else {
                    if (isset($minLimiti[$inputName])) {
                        $minOre = $minLimiti[$inputName]['minOre'];
                        $ore = (float)($item['ore'] ?? 0);
                        if ($ore < $minOre) {
                            echo json_encode(['success' => false, 'message' => "Le ore per la Persona #" . ($idx + 1) . " dell'attività '$titolo' non possono essere inferiori al minimo di $minOre ore."]);
                            exit;
                        }
                    }
                }
            }
        }
    }

    $datiAttivita[$titolo] = [
        'attiva' => $attiva,
        'colloquio' => isset($postData["{$inputName}_colloquio"]) ? 1 : 0,
        'dettagli_json' => $dettagliRaw
    ];
}

$timestamp = date('d/m/Y H:i:s');

// Percorso file output
$dirPath = dirname(__DIR__) . '/data';
if (!is_dir($dirPath)) {
    if (!mkdir($dirPath, 0777, true)) {
        echo json_encode(['success' => false, 'message' => 'Impossibile creare la cartella data/.']);
        exit;
    }
}

$outputFile = $dirPath . '/compilazioni_docenti.xlsx';

try {
    if (file_exists($outputFile)) {
        $spreadsheet = IOFactory::load($outputFile);
        $sheet = $spreadsheet->getActiveSheet();
    } else {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $headers = [
            'Data/Ora', 'Nome Compilatore', 'Cognome Compilatore', 'Corso Selezionato',
            'Docente di Riferimento', 'Budget Iniziale (€)', 'Prezzo Totale (€)', 'Competenze/Requisiti', 'Note'
        ];
        
        foreach ($titoliAttivita as $titolo) {
            $headers[] = "$titolo - Attiva";
            $headers[] = "$titolo - Colloquio";
            $headers[] = "$titolo - Dettagli";
        }
        
        $colIndex = 1;
        $sheet->freezePane('E2');
        
        $colorPalette = [
            'FFD9E1F2', // Blue pallido
            'FFE2EFDA', // Verde pastello
            'FFFFF2CC', // Giallo tenue
            'FFFCE4D6', // Arancio pastello
            'FFE6B8B7'  // Rosso pallido
        ];

        foreach ($headers as $index => $header) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            
            // Stile Base Orizzontale
            $sheet->setCellValue($colLetter . '1', $header);
            $sheet->getStyle($colLetter . '1')->getFont()->setBold(true);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            
            // Colori Header
            if ($index < 9) {
                // Primi 9 campi generici
                $sheet->getStyle($colLetter . '1')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF1F4E78');
                $sheet->getStyle($colLetter . '1')->getFont()->getColor()->setARGB('FFFFFFFF');
            } else {
                // Raggruppamenti da 3 per Attività -> Colore a rotazione
                $group = floor(($index - 9) / 3);
                $bgColor = $colorPalette[$group % count($colorPalette)];
                $sheet->getStyle($colLetter . '1')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB($bgColor);
                $sheet->getStyle($colLetter . '1')->getFont()->getColor()->setARGB('FF000000');
            }
            
            $colIndex++;
        }
    }

    $highestRow = $sheet->getHighestDataRow();
    $newRow = $highestRow + 1;

    for ($r = 2; $r <= $highestRow; $r++) {
        $corsoEsistente = trim((string)$sheet->getCell('D' . $r)->getValue());
        if ($corsoEsistente === trim($corso) && !empty($corsoEsistente)) {
            $newRow = $r;
            break;
        }
    }

    // Scrittura Dati Fissi
    $sheet->setCellValue("A$newRow", $timestamp);
    $sheet->setCellValue("B$newRow", $nome);
    $sheet->setCellValue("C$newRow", $cognome);
    $sheet->setCellValue("D$newRow", $corso);
    $sheet->setCellValue("E$newRow", $docente);
    $sheet->setCellValue("F$newRow", $budget_iniziale);
    $sheet->setCellValue("G$newRow", $costo_totale);
    $sheet->setCellValue("H$newRow", $competenze);
    $sheet->setCellValue("I$newRow", $note);

    // Scrittura Dati Attività (Inizia da colonna 'J' che è la numero 10)
    $colIndex = 10;
    foreach ($titoliAttivita as $titolo) {
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $newRow, $datiAttivita[$titolo]['attiva'] ? 'SI' : 'NO');
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $newRow, $datiAttivita[$titolo]['colloquio'] ? 'SI' : 'NO');
        $sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $newRow, $datiAttivita[$titolo]['dettagli_json'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }

    $writer = new Xlsx($spreadsheet);
    $writer->save($outputFile);

    echo json_encode([
        'success' => true, 
        'message' => 'Richiesta salvata con successo!'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Errore nel salvataggio su Excel: ' . $e->getMessage()
    ]);
}
?>
