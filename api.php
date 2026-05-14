<?php
header('Content-Type: application/json; charset=utf-8');

$dataFile = __DIR__ . '/data.json';
$action = isset($_GET['action']) ? $_GET['action'] : '';

function default_column_labels() {
    return array(
        'Novo' => 'Novo',
        'Contatado' => 'Contatado',
        'Respondeu' => 'Respondeu',
        'Em negociação' => 'Em negociação'
    );
}

function json_response($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function empty_data() {
    return array('leads' => array(), 'last_updated' => '', 'column_labels' => default_column_labels());
}

function read_data($dataFile) {
    if (!file_exists($dataFile)) {
        return empty_data();
    }

    $contents = file_get_contents($dataFile);
    if ($contents === false || trim($contents) === '') {
        return empty_data();
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        json_response(array('success' => false, 'error' => 'data.json inválido'), 500);
    }

    if (!isset($data['leads']) || !is_array($data['leads'])) {
        $data['leads'] = array();
    }

    if (!isset($data['last_updated'])) {
        $data['last_updated'] = '';
    }

    if (!isset($data['column_labels']) || !is_array($data['column_labels'])) {
        $data['column_labels'] = default_column_labels();
    } else {
        $data['column_labels'] = array_merge(default_column_labels(), $data['column_labels']);
    }

    return $data;
}

function write_data($dataFile, $data) {
    $data['last_updated'] = gmdate('Y-m-d\TH:i:s\Z');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($json === false || file_put_contents($dataFile, $json, LOCK_EX) === false) {
        json_response(array('success' => false, 'error' => 'Não foi possível escrever data.json'), 500);
    }
}

function normalize_header($value) {
    return trim((string)$value);
}

function get_existing_map($leads) {
    $map = array();
    foreach ($leads as $index => $lead) {
        if (isset($lead['Lead_ID']) && $lead['Lead_ID'] !== '') {
            $map[$lead['Lead_ID']] = $index;
        }
    }
    return $map;
}

try {
    if ($action === 'get_leads') {
        $data = read_data($dataFile);
        json_response(array(
            'success' => true,
            'leads' => $data['leads'],
            'column_labels' => $data['column_labels'],
            'last_updated' => $data['last_updated']
        ));
    }

    if ($action === 'update_column_labels') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['column_labels']) || !is_array($input['column_labels'])) {
            json_response(array('success' => false, 'error' => 'column_labels obrigatório'), 400);
        }

        $allowedStatuses = array_keys(default_column_labels());
        $labels = default_column_labels();
        foreach ($allowedStatuses as $status) {
            if (isset($input['column_labels'][$status])) {
                $label = trim((string)$input['column_labels'][$status]);
                $labels[$status] = $label !== '' ? $label : $status;
            }
        }

        $data = read_data($dataFile);
        $data['column_labels'] = $labels;
        write_data($dataFile, $data);
        json_response(array('success' => true, 'column_labels' => $labels));
    }

    if ($action === 'update_lead') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input['Lead_ID'])) {
            json_response(array('success' => false, 'error' => 'Lead_ID obrigatório'), 400);
        }

        $data = read_data($dataFile);
        $found = false;
        $editableFields = array('Status', 'Observação', 'Internal_Notes');

        foreach ($data['leads'] as &$lead) {
            if (isset($lead['Lead_ID']) && $lead['Lead_ID'] === $input['Lead_ID']) {
                foreach ($editableFields as $field) {
                    if (array_key_exists($field, $input)) {
                        $lead[$field] = (string)$input[$field];
                    }
                }
                $found = true;
                break;
            }
        }
        unset($lead);

        if (!$found) {
            json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
        }

        write_data($dataFile, $data);
        json_response(array('success' => true));
    }

    if ($action === 'import') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        }

        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            json_response(array('success' => false, 'error' => 'Arquivo CSV obrigatório'), 400);
        }

        $expectedFields = array(
            'Lead_ID', 'Name', 'Type', 'Phone', 'Email', 'Website', 'Instagram', 'Linkedin',
            'Observação', 'Score_Fase1', 'Tier_Fase1', 'Recommended_Angle', 'IG_Followers',
            'IG_PostCount', 'IG_Posts_30d', 'IG_Last_Post_Days', 'IG_Activity', 'LI_Followers',
            'LI_Connections', 'LI_Headline', 'LI_Company', 'Website_Active', 'Website_Summary',
            'Gender', 'Brand_Score', 'Approach_Type', 'New_Tier', 'Website_Match',
            'Short_Note', 'First_Message'
        );

        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        if ($handle === false) {
            json_response(array('success' => false, 'error' => 'Não foi possível ler o CSV'), 400);
        }

        $headers = fgetcsv($handle, 0, ',');
        if ($headers === false) {
            fclose($handle);
            json_response(array('success' => false, 'error' => 'CSV vazio ou sem headers'), 400);
        }

        $headers = array_map('normalize_header', $headers);
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }

        $data = read_data($dataFile);
        $existingMap = get_existing_map($data['leads']);
        $imported = 0;
        $updated = 0;
        $new = 0;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            if (count($row) === 1 && trim($row[0]) === '') {
                continue;
            }

            $csvLead = array();
            foreach ($expectedFields as $field) {
                $index = array_search($field, $headers, true);
                $csvLead[$field] = ($index !== false && isset($row[$index])) ? (string)$row[$index] : '';
            }

            if ($csvLead['Lead_ID'] === '') {
                continue;
            }

            $imported++;
            if (isset($existingMap[$csvLead['Lead_ID']])) {
                $leadIndex = $existingMap[$csvLead['Lead_ID']];
                $existingLead = $data['leads'][$leadIndex];
                $csvLead['Status'] = isset($existingLead['Status']) ? $existingLead['Status'] : 'Novo';
                $csvLead['Observação'] = isset($existingLead['Observação']) ? $existingLead['Observação'] : $csvLead['Observação'];
                $csvLead['Internal_Notes'] = isset($existingLead['Internal_Notes']) ? $existingLead['Internal_Notes'] : '';
                $data['leads'][$leadIndex] = $csvLead;
                $updated++;
            } else {
                $csvLead['Status'] = 'Novo';
                $csvLead['Internal_Notes'] = '';
                $data['leads'][] = $csvLead;
                $existingMap[$csvLead['Lead_ID']] = count($data['leads']) - 1;
                $new++;
            }
        }
        fclose($handle);

        write_data($dataFile, $data);
        json_response(array(
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'new' => $new
        ));
    }

    json_response(array('success' => false, 'error' => 'Ação inválida'), 400);
} catch (Exception $e) {
    json_response(array('success' => false, 'error' => $e->getMessage()), 500);
}
?>
