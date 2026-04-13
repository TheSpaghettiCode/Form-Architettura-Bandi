<?php
// FASE 3: SALVATAGGIO DATI
header('Content-Type: application/json');

// Impedisci accessi non POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito.']);
    exit;
}

// Includi autoloader di Composer se esiste, altrimenti potremmo avere un errore critico
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

// Supporto per payload x-www-form-urlencoded o raw JSON
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
$motivazioni = sanitize($postData['motivazioni'] ?? '');

// Estrazione campi attività
$attivitaKeys = ['ore_contratti', 'ore_studenti', 'ore_conferenze', 'ore_tut_studenti', 'ore_tut_dottorandi'];
$datiAttivita = [];
foreach ($attivitaKeys as $ak) {
    $datiAttivita[$ak] = [
        'attiva' => isset($postData["{$ak}_attiva"]) ? 1 : 0,
        'studenti' => (int)($postData["{$ak}_studenti"] ?? 0),
        'ore' => (float)($postData[$ak] ?? 0),
        'colloquio' => isset($postData["{$ak}_colloquio"]) ? 1 : 0
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
            'Docente di Riferimento', 'Budget Iniziale (€)', 'Costo Totale (€)', 'Motivazioni'
        ];
        
        $titoliAttivita = [
            'Supporto contratti', 'Supporto studenti', 'Conferenze', 
            'Tutorato studenti', 'Tutorato dottorandi'
        ];
        
        foreach ($titoliAttivita as $titolo) {
            $headers[] = "$titolo - Attiva";
            $headers[] = "$titolo - Studenti";
            $headers[] = "$titolo - Ore";
            $headers[] = "$titolo - Colloquio";
        }
        
        $colIndex = 1;
        $sheet->freezePane('E2'); // Blocca top riga e prime 4 colonne (Anagrafica) per scroll agevole
        
        $colorPalette = [
            'FFD9E1F2', // Blue pallido
            'FFE2EFDA', // Verde pastello
            'FFFFF2CC', // Giallo tenue
            'FFFCE4D6', // Arancio pastello
            'FFE6B8B7'  // Rosso pallido
        ];
        $paletteIdx = 0;

        foreach ($headers as $index => $header) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            
            // Stile Base Orizzontale
            $sheet->setCellValue($colLetter . '1', $header);
            $sheet->getStyle($colLetter . '1')->getFont()->setBold(true);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            
            // Colori Header
            if ($index < 8) {
                // Primi 8 campi generici: Sfondo Blu Scuro, Testo Bianco
                $sheet->getStyle($colLetter . '1')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF1F4E78');
                $sheet->getStyle($colLetter . '1')->getFont()->getColor()->setARGB('FFFFFFFF');
            } else {
                // Raggruppamenti da 4 per Attività -> Colore a rotazione
                $group = floor(($index - 8) / 4);
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
    $sheet->setCellValue("H$newRow", $motivazioni);

    // Scrittura Dati Attività (Inizia da colonna 'I' che è la numero 9)
    // Scrittura Dati Attività (Inizia da colonna 'I' che è la numero 9)
    $colIndex = 9;
    foreach ($attivitaKeys as $ak) {
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $newRow, $datiAttivita[$ak]['attiva'] ? 'SI' : 'NO');
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $newRow, $datiAttivita[$ak]['studenti']);
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $newRow, $datiAttivita[$ak]['ore']);
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++) . $newRow, $datiAttivita[$ak]['colloquio'] ? 'SI' : 'NO');
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
