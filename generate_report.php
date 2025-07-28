<?php
// generate_report.php - Génération de rapports PDF et Excel
require_once 'config.php';
session_start();

// Vérifier les permissions
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'direction')) {
    header('Location: login.php');
    exit;
}

// Vérifier les paramètres
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: management_dashboard.php');
    exit;
}

$reportType = $_POST['report_type'] ?? '';
$format = $_POST['report_format'] ?? 'pdf';
$currentYear = $_SESSION['current_year'];

// Récupérer les données en fonction du type de rapport
$reportData = [];
$title = '';

switch ($reportType) {
    case 'class':
        $classId = $_POST['report_class'] ?? 0;
        if ($classId) {
            $stmt = $conn->prepare("
                SELECT s.id, s.last_name, s.first_name, c.name AS class_name, c.level,
                       p.specialties, p.options, p.drop_specialty
                FROM students s
                JOIN classes c ON s.class_id = c.id
                LEFT JOIN preferences p ON p.student_id = s.id AND p.grade = c.level AND p.term = 1
                WHERE c.id = ? AND c.year = ?
                ORDER BY s.last_name, s.first_name
            ");
            $stmt->bind_param('is', $classId, $currentYear);
            $stmt->execute();
            $result = $stmt->get_result();
            $reportData = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            if (!empty($reportData)) {
                $class = $reportData[0];
                $title = "Élèves de la classe : {$class['level']} {$class['class_name']}";
            }
        }
        break;
        
    case 'specialty':
        $specialty = $_POST['report_specialty'] ?? '';
        if ($specialty) {
            $stmt = $conn->prepare("
                SELECT s.id, s.last_name, s.first_name, c.name AS class_name, c.level,
                       p.specialties, p.options, p.drop_specialty
                FROM students s
                JOIN classes c ON s.class_id = c.id
                JOIN preferences p ON p.student_id = s.id AND p.grade = c.level AND p.term = 1
                WHERE p.specialties LIKE ? AND c.year = ?
                ORDER BY c.level, c.name, s.last_name, s.first_name
            ");
            $searchTerm = "%" . $specialty . "%";
            $stmt->bind_param('ss', $searchTerm, $currentYear);
            $stmt->execute();
            $result = $stmt->get_result();
            $reportData = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            $title = "Élèves avec la spécialité : $specialty";
        }
        break;
        
    case 'option':
        $option = $_POST['report_option'] ?? '';
        if ($option) {
            $stmt = $conn->prepare("
                SELECT s.id, s.last_name, s.first_name, c.name AS class_name, c.level,
                       p.specialties, p.options, p.drop_specialty
                FROM students s
                JOIN classes c ON s.class_id = c.id
                JOIN preferences p ON p.student_id = s.id AND p.grade = c.level AND p.term = 1
                WHERE p.options LIKE ? AND c.year = ?
                ORDER BY c.level, c.name, s.last_name, s.first_name
            ");
            $searchTerm = "%" . $option . "%";
            $stmt->bind_param('ss', $searchTerm, $currentYear);
            $stmt->execute();
            $result = $stmt->get_result();
            $reportData = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            $title = "Élèves avec l'option : $option";
        }
        break;
        
    case 'level':
        $level = $_POST['report_level'] ?? '';
        if ($level) {
            $stmt = $conn->prepare("
                SELECT s.id, s.last_name, s.first_name, c.name AS class_name, c.level,
                       p.specialties, p.options, p.drop_specialty
                FROM students s
                JOIN classes c ON s.class_id = c.id
                LEFT JOIN preferences p ON p.student_id = s.id AND p.grade = c.level AND p.term = 1
                WHERE c.level = ? AND c.year = ?
                ORDER BY c.name, s.last_name, s.first_name
            ");
            $stmt->bind_param('ss', $level, $currentYear);
            $stmt->execute();
            $result = $stmt->get_result();
            $reportData = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            $title = "Élèves de niveau : $level";
        }
        break;
        
    case 'all':
        $stmt = $conn->prepare("
            SELECT s.id, s.last_name, s.first_name, c.name AS class_name, c.level,
                   p.specialties, p.options, p.drop_specialty
            FROM students s
            JOIN classes c ON s.class_id = c.id
            LEFT JOIN preferences p ON p.student_id = s.id AND p.grade = c.level AND p.term = 1
            WHERE c.year = ?
            ORDER BY c.level, c.name, s.last_name, s.first_name
        ");
        $stmt->bind_param('s', $currentYear);
        $stmt->execute();
        $result = $stmt->get_result();
        $reportData = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $title = "Tous les élèves - Année scolaire $currentYear";
        break;
}

// Générer le rapport dans le format demandé
if ($format === 'pdf') {
    generatePdfReport($reportData, $title);
} else {
    generateExcelReport($reportData, $title);
}

exit;

// Fonction pour générer un rapport PDF
function generatePdfReport($data, $title) {
    require_once('tcpdf/tcpdf.php');
    
    // Créer une nouvelle instance TCPDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Configuration du document
    $pdf->SetCreator('Système d\'Orientation - Lycée Saint Elme');
    $pdf->SetAuthor('Direction');
    $pdf->SetTitle($title);
    $pdf->SetSubject('Rapport d\'orientation');
    $pdf->SetKeywords('orientation, élèves, classes, spécialités');
    
    // Ajouter une page
    $pdf->AddPage();
    
    // En-tête
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Lycée Saint Elme', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 10, 'Généré le ' . date('d/m/Y à H:i'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Tableau des données
    if (!empty($data)) {
        // En-tête du tableau
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('helvetica', 'B', 10);
        
        $header = array('Nom', 'Prénom', 'Classe', 'Spécialités', 'Options', 'Spé. abandonnée');
        $widths = array(35, 30, 30, 50, 30, 25);
        
        for ($i = 0; $i < count($header); $i++) {
            $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', 1);
        }
        $pdf->Ln();
        
        // Données
        $pdf->SetFont('helvetica', '', 9);
        $fill = false;
        
        foreach ($data as $row) {
            $pdf->Cell($widths[0], 6, $row['last_name'], 'LR', 0, 'L', $fill);
            $pdf->Cell($widths[1], 6, $row['first_name'], 'LR', 0, 'L', $fill);
            $pdf->Cell($widths[2], 6, $row['class_name'], 'LR', 0, 'C', $fill);
            
            // Spécialités
            $spe = $row['specialties'] ? str_replace('||', ', ', $row['specialties']) : '-';
            $pdf->Cell($widths[3], 6, $spe, 'LR', 0, 'L', $fill);
            
            // Options
            $opt = $row['options'] ? str_replace('||', ', ', $row['options']) : '-';
            $pdf->Cell($widths[4], 6, $opt, 'LR', 0, 'L', $fill);
            
            // Spécialité abandonnée
            $drop = $row['drop_specialty'] ?? '-';
            $pdf->Cell($widths[5], 6, $drop, 'LR', 0, 'C', $fill);
            
            $pdf->Ln();
            $fill = !$fill;
        }
        
        // Fermer le tableau
        $pdf->Cell(array_sum($widths), 0, '', 'T');
    } else {
        $pdf->SetFont('helvetica', 'I', 12);
        $pdf->Cell(0, 10, 'Aucune donnée à afficher', 0, 1, 'C');
    }
    
    // Pied de page
    $pdf->SetY(-15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 0, 'C');
    
    // Nom du fichier
    $filename = 'rapport_' . date('Ymd_His') . '.pdf';
    
    // Envoyer le PDF au navigateur
    $pdf->Output($filename, 'D');
}

// Fonction pour générer un rapport Excel
function generateExcelReport($data, $title) {
    require_once 'vendor/autoload.php';
    
    // Créer une nouvelle feuille de calcul
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Titre
    $sheet->setCellValue('A1', 'Lycée Saint Elme');
    $sheet->setCellValue('A2', $title);
    $sheet->setCellValue('A3', 'Généré le ' . date('d/m/Y à H:i'));
    
    // Fusionner les cellules pour le titre
    $sheet->mergeCells('A1:F1');
    $sheet->mergeCells('A2:F2');
    $sheet->mergeCells('A3:F3');
    
    // Style du titre
    $titleStyle = [
        'font' => [
            'bold' => true,
            'size' => 14,
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
        ],
    ];
    
    $sheet->getStyle('A1')->applyFromArray($titleStyle);
    $sheet->getStyle('A2')->applyFromArray($titleStyle);
    
    $subtitleStyle = [
        'font' => [
            'size' => 10,
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
        ],
    ];
    
    $sheet->getStyle('A3')->applyFromArray($subtitleStyle);
    
    // En-têtes de colonnes
    $sheet->setCellValue('A5', 'Nom');
    $sheet->setCellValue('B5', 'Prénom');
    $sheet->setCellValue('C5', 'Classe');
    $sheet->setCellValue('D5', 'Niveau');
    $sheet->setCellValue('E5', 'Spécialités');
    $sheet->setCellValue('F5', 'Options');
    $sheet->setCellValue('G5', 'Spécialité abandonnée');
    
    // Style des en-têtes
    $headerStyle = [
        'font' => [
            'bold' => true,
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => [
                'argb' => 'FFD9E1F2',
            ]
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            ]
        ]
    ];
    
    $sheet->getStyle('A5:G5')->applyFromArray($headerStyle);
    
    // Remplir les données
    $row = 6;
    
    foreach ($data as $student) {
        $sheet->setCellValue('A' . $row, $student['last_name']);
        $sheet->setCellValue('B' . $row, $student['first_name']);
        $sheet->setCellValue('C' . $row, $student['class_name']);
        $sheet->setCellValue('D' . $row, $student['level']);
        
        $spe = $student['specialties'] ? str_replace('||', ', ', $student['specialties']) : '-';
        $sheet->setCellValue('E' . $row, $spe);
        
        $opt = $student['options'] ? str_replace('||', ', ', $student['options']) : '-';
        $sheet->setCellValue('F' . $row, $opt);
        
        $drop = $student['drop_specialty'] ?? '-';
        $sheet->setCellValue('G' . $row, $drop);
        
        $row++;
    }
    
    // Ajuster la largeur des colonnes
    $sheet->getColumnDimension('A')->setWidth(25);
    $sheet->getColumnDimension('B')->setWidth(20);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(40);
    $sheet->getColumnDimension('F')->setWidth(30);
    $sheet->getColumnDimension('G')->setWidth(25);
    
    // Ajouter des bordures aux données
    $dataStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            ]
        ]
    ];
    
    $lastRow = $row - 1;
    if ($lastRow >= 6) {
        $sheet->getStyle('A5:G' . $lastRow)->applyFromArray($dataStyle);
    }
    
    // Centrer les en-têtes
    $sheet->getStyle('A5:G5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Créer un écrivain Excel
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    
    // Nom du fichier
    $filename = 'rapport_' . date('Ymd_His') . '.xlsx';
    
    // Envoyer les en-têtes
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Envoyer le fichier Excel
    $writer->save('php://output');
    exit;
}