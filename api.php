<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$action = isset($_GET['action']) ? $_GET['action'] : '';

function real_estate_statuses() {
    return array('Novo', 'Contatado', 'Qualificado', 'Top Agents', 'Em negociação', 'Respondeu', 'Fechado');
}

function launch_statuses() {
    return array('Novo', 'Contatado', 'Respondeu', 'Qualificado', 'Call Marcada', 'Fechado', 'Descartado');
}

function labels_from_statuses($statuses) {
    $labels = array();
    foreach ($statuses as $status) {
        $labels[$status] = $status;
    }
    return $labels;
}

function pipeline_configs() {
    return array(
        'real_estate' => array(
            'key' => 'real_estate',
            'name' => 'Real Estate',
            'file' => __DIR__ . '/data.json',
            'statuses' => real_estate_statuses(),
            'column_labels' => labels_from_statuses(real_estate_statuses()),
            'editable_fields' => array('Status', 'Observação', 'Internal_Notes'),
            'supports_import' => true,
            'supports_sync' => false,
            'sync_url' => ''
        ),
        'lancamento_maio_2026' => array(
            'key' => 'lancamento_maio_2026',
            'name' => 'Lançamento Maio 2026',
            'file' => __DIR__ . '/data-lancamento-maio-2026.json',
            'statuses' => launch_statuses(),
            'column_labels' => labels_from_statuses(launch_statuses()),
            'editable_fields' => array('Status', 'Internal_Notes'),
            'supports_import' => false,
            'supports_sync' => true,
            'sync_url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vQ7iPToWrBRwQv3r0Sp_sJo31DGhYAc0Tidyt0q6dBg9rCe7GZO9wSfwPJnvFq4ocJuU4JcVGlGlRzS/pub?gid=0&single=true&output=csv'
        )
    );
}

function json_response($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function current_pipeline() {
    $pipelines = pipeline_configs();
    $pipelineKey = isset($_GET['pipeline']) && $_GET['pipeline'] !== '' ? $_GET['pipeline'] : 'real_estate';

    if (!isset($pipelines[$pipelineKey])) {
        json_response(array('success' => false, 'error' => 'Pipeline inválido'), 400);
    }

    return $pipelines[$pipelineKey];
}

function empty_data($pipeline) {
    return array(
        'leads' => array(),
        'last_updated' => '',
        'last_synced' => '',
        'column_labels' => $pipeline['column_labels']
    );
}

function read_data($pipeline) {
    $dataFile = $pipeline['file'];

    if (!file_exists($dataFile)) {
        return empty_data($pipeline);
    }

    $contents = file_get_contents($dataFile);
    if ($contents === false || trim($contents) === '') {
        return empty_data($pipeline);
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        json_response(array('success' => false, 'error' => basename($dataFile) . ' inválido'), 500);
    }

    if (!isset($data['leads']) || !is_array($data['leads'])) {
        $data['leads'] = array();
    }

    if (!isset($data['last_updated'])) {
        $data['last_updated'] = '';
    }

    if (!isset($data['last_synced'])) {
        $data['last_synced'] = '';
    }

    if (!isset($data['column_labels']) || !is_array($data['column_labels'])) {
        $data['column_labels'] = $pipeline['column_labels'];
    } else {
        $data['column_labels'] = array_merge($pipeline['column_labels'], $data['column_labels']);
    }

    return $data;
}

function write_data($pipeline, &$data) {
    $data['last_updated'] = gmdate('Y-m-d\TH:i:s\Z');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($json === false || file_put_contents($pipeline['file'], $json, LOCK_EX) === false) {
        json_response(array('success' => false, 'error' => 'Não foi possível escrever ' . basename($pipeline['file'])), 500);
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

function read_csv_headers($handle) {
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        return false;
    }

    $headers = array_map('normalize_header', $headers);
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    }

    return $headers;
}

function row_to_assoc($headers, $row) {
    $lead = array();
    foreach ($headers as $index => $header) {
        if ($header === '') {
            continue;
        }
        $lead[$header] = isset($row[$index]) ? (string)$row[$index] : '';
    }
    return $lead;
}

function fetch_csv($url) {
    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 20,
            'header' => "User-Agent: OiDigitalMediaCRM/1.0\r\n"
        )
    ));

    $csv = @file_get_contents($url, false, $context);
    if ($csv === false || trim($csv) === '') {
        json_response(array('success' => false, 'error' => 'Não foi possível baixar o CSV publicado'), 502);
    }

    return $csv;
}

function open_csv_string($csv) {
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        json_response(array('success' => false, 'error' => 'Não foi possível preparar o CSV'), 500);
    }

    fwrite($handle, $csv);
    rewind($handle);
    return $handle;
}

function import_real_estate($pipeline) {
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

    $headers = read_csv_headers($handle);
    if ($headers === false) {
        fclose($handle);
        json_response(array('success' => false, 'error' => 'CSV vazio ou sem headers'), 400);
    }

    $data = read_data($pipeline);
    $existingMap = get_existing_map($data['leads']);
    $imported = 0;
    $updated = 0;
    $new = 0;
    $skipped = 0;

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (count($row) === 1 && trim($row[0]) === '') {
            continue;
        }

        $csvLead = array();
        foreach ($expectedFields as $field) {
            $index = array_search($field, $headers, true);
            $csvLead[$field] = ($index !== false && isset($row[$index])) ? (string)$row[$index] : '';
        }

        if ($csvLead['Lead_ID'] === '') {
            $skipped++;
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

    write_data($pipeline, $data);
    json_response(array(
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'new' => $new,
        'skipped' => $skipped
    ));
}

function sync_launch_pipeline($pipeline) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(array('success' => false, 'error' => 'Método inválido'), 405);
    }

    $csv = fetch_csv($pipeline['sync_url']);
    $handle = open_csv_string($csv);
    $headers = read_csv_headers($handle);
    if ($headers === false) {
        fclose($handle);
        json_response(array('success' => false, 'error' => 'CSV publicado vazio ou sem headers'), 400);
    }

    $data = read_data($pipeline);
    $existingMap = get_existing_map($data['leads']);
    $imported = 0;
    $updated = 0;
    $new = 0;
    $skipped = 0;

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (count($row) === 1 && trim($row[0]) === '') {
            continue;
        }

        $csvLead = row_to_assoc($headers, $row);
        $leadId = isset($csvLead['Lead_ID']) ? trim((string)$csvLead['Lead_ID']) : '';

        if ($leadId === '') {
            $skipped++;
            continue;
        }

        $csvLead['Lead_ID'] = $leadId;
        $imported++;

        if (isset($existingMap[$leadId])) {
            $leadIndex = $existingMap[$leadId];
            $existingLead = $data['leads'][$leadIndex];
            $csvLead['Status'] = isset($existingLead['Status']) ? $existingLead['Status'] : 'Novo';
            $csvLead['Internal_Notes'] = isset($existingLead['Internal_Notes']) ? $existingLead['Internal_Notes'] : '';
            $data['leads'][$leadIndex] = $csvLead;
            $updated++;
        } else {
            $csvLead['Status'] = 'Novo';
            $csvLead['Internal_Notes'] = '';
            $data['leads'][] = $csvLead;
            $existingMap[$leadId] = count($data['leads']) - 1;
            $new++;
        }
    }
    fclose($handle);

    $data['last_synced'] = gmdate('Y-m-d\TH:i:s\Z');
    write_data($pipeline, $data);

    json_response(array(
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'new' => $new,
        'skipped' => $skipped,
        'leads' => $data['leads'],
        'column_labels' => $data['column_labels'],
        'last_updated' => $data['last_updated'],
        'last_synced' => $data['last_synced']
    ));
}

try {
    $pipeline = current_pipeline();

    if ($action === 'get_leads') {
        $data = read_data($pipeline);
        json_response(array(
            'success' => true,
            'pipeline' => $pipeline['key'],
            'leads' => $data['leads'],
            'column_labels' => $data['column_labels'],
            'last_updated' => $data['last_updated'],
            'last_synced' => $data['last_synced'],
            'statuses' => $pipeline['statuses'],
            'supports_import' => $pipeline['supports_import'],
            'supports_sync' => $pipeline['supports_sync']
        ));
    }

    if ($action === 'update_column_labels') {
        if (!$pipeline['supports_import']) {
            json_response(array('success' => false, 'error' => 'Renomear colunas não está disponível neste pipeline'), 400);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['column_labels']) || !is_array($input['column_labels'])) {
            json_response(array('success' => false, 'error' => 'column_labels obrigatório'), 400);
        }

        $labels = $pipeline['column_labels'];
        foreach ($pipeline['statuses'] as $status) {
            if (isset($input['column_labels'][$status])) {
                $label = trim((string)$input['column_labels'][$status]);
                $labels[$status] = $label !== '' ? $label : $status;
            }
        }

        $data = read_data($pipeline);
        $data['column_labels'] = $labels;
        write_data($pipeline, $data);
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

        $data = read_data($pipeline);
        $found = false;

        foreach ($data['leads'] as &$lead) {
            if (isset($lead['Lead_ID']) && $lead['Lead_ID'] === $input['Lead_ID']) {
                foreach ($pipeline['editable_fields'] as $field) {
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

        write_data($pipeline, $data);
        json_response(array('success' => true));
    }

    if ($action === 'import') {
        if (!$pipeline['supports_import']) {
            json_response(array('success' => false, 'error' => 'Import manual não está disponível neste pipeline'), 400);
        }

        import_real_estate($pipeline);
    }

    if ($action === 'sync') {
        if (!$pipeline['supports_sync']) {
            json_response(array('success' => false, 'error' => 'Sync não está disponível neste pipeline'), 400);
        }

        sync_launch_pipeline($pipeline);
    }

    json_response(array('success' => false, 'error' => 'Ação inválida'), 400);
} catch (Exception $e) {
    json_response(array('success' => false, 'error' => $e->getMessage()), 500);
}
?>
