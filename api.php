<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ── helpers ───────────────────────────────────────────────────────────────────

function real_estate_statuses() {
    return array('Novo', 'Contatado', 'Qualificado', 'Top Agents', 'Em negociação', 'Respondeu', 'Fechado');
}

function labels_from_statuses($statuses) {
    $labels = array();
    foreach ($statuses as $status) {
        $labels[$status] = $status;
    }
    return $labels;
}

function json_response($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── driver: file (real_estate only, intocável) ────────────────────────────────

function file_pipeline_config() {
    $statuses = real_estate_statuses();
    return array(
        'key'            => 'real_estate',
        'name'           => 'Real Estate',
        'driver'         => 'file',
        'file'           => __DIR__ . '/data.json',
        'statuses'       => $statuses,
        'column_labels'  => labels_from_statuses($statuses),
        'editable_fields'=> array('Status', 'Observação', 'Internal_Notes'),
        'supports_import'=> true,
        'supports_sync'  => false,
        'supports_delete'=> false,
    );
}

function empty_data($pipeline) {
    return array(
        'leads'        => array(),
        'last_updated' => '',
        'last_synced'  => '',
        'column_labels'=> $pipeline['column_labels']
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

// ── CSV helpers (compartilhado) ───────────────────────────────────────────────

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
            'header'  => "User-Agent: OiDigitalMediaCRM/1.0\r\n"
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

// ── import: somente real_estate ───────────────────────────────────────────────

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

    $data     = read_data($pipeline);
    $existingMap = get_existing_map($data['leads']);
    $imported = 0;
    $updated  = 0;
    $new      = 0;
    $skipped  = 0;

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
            $leadIndex  = $existingMap[$csvLead['Lead_ID']];
            $existingLead = $data['leads'][$leadIndex];
            $csvLead['Status']        = isset($existingLead['Status'])        ? $existingLead['Status']        : 'Novo';
            $csvLead['Observação']    = isset($existingLead['Observação'])    ? $existingLead['Observação']    : $csvLead['Observação'];
            $csvLead['Internal_Notes']= isset($existingLead['Internal_Notes'])? $existingLead['Internal_Notes']: '';
            $data['leads'][$leadIndex] = $csvLead;
            $updated++;
        } else {
            $csvLead['Status']         = 'Novo';
            $csvLead['Internal_Notes'] = '';
            $data['leads'][]           = $csvLead;
            $existingMap[$csvLead['Lead_ID']] = count($data['leads']) - 1;
            $new++;
        }
    }
    fclose($handle);

    write_data($pipeline, $data);
    json_response(array(
        'success'  => true,
        'imported' => $imported,
        'updated'  => $updated,
        'new'      => $new,
        'skipped'  => $skipped
    ));
}

// ── driver: Neon (HTTP SQL API — sem pdo_pgsql) ───────────────────────────────

function neon_http_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $dsn = defined('NEON_DATABASE_URL') ? NEON_DATABASE_URL : getenv('NEON_DATABASE_URL');
    if (!$dsn) throw new Exception('NEON_DATABASE_URL não configurada no servidor');
    $p = parse_url($dsn);
    // HTTP SQL API usa endpoint sem -pooler
    $host = str_replace('-pooler.', '.', $p['host']);
    $cfg = array(
        'endpoint' => 'https://' . $host . '/sql',
        'password' => urldecode($p['pass']),
        'role'     => urldecode($p['user']),
        'database' => ltrim($p['path'], '/'),
    );
    return $cfg;
}

function neon_http($body) {
    $c = neon_http_config();
    $ctx = stream_context_create(array('http' => array(
        'method'        => 'POST',
        'header'        =>
            "Content-Type: application/json\r\n" .
            "Authorization: Bearer " . $c['password'] . "\r\n" .
            "Neon-Role-Name: " . $c['role'] . "\r\n" .
            "Neon-Database-Name: " . $c['database'] . "\r\n",
        'content'       => json_encode($body, JSON_UNESCAPED_UNICODE),
        'timeout'       => 20,
        'ignore_errors' => true,
    )));
    $raw = @file_get_contents($c['endpoint'], false, $ctx);
    if ($raw === false) throw new Exception('Neon HTTP: sem resposta do servidor');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new Exception('Neon HTTP: resposta inválida');
    if (isset($data['message'])) throw new Exception('Neon: ' . $data['message']);
    return $data;
}

function neon_query($sql, $params = array()) {
    return neon_http(array('query' => $sql, 'params' => array_values($params)));
}

function neon_transaction($queries) {
    return neon_http(array('queries' => $queries));
}

function neon_row_to_pipeline($row) {
    // HTTP API retorna JSONB como array nativo; PDO retornaria string — aceita os dois
    $d = function($v) { return is_string($v) ? json_decode($v, true) : $v; };
    $cl = $d($row['column_labels']);
    return array(
        'key'             => $row['key'],
        'name'            => $row['name'],
        'driver'          => 'neon',
        'statuses'        => $d($row['statuses'])        ?: array(),
        'board_statuses'  => isset($row['board_statuses']) && $row['board_statuses'] !== null ? $d($row['board_statuses']) : null,
        'editable_fields' => $d($row['editable_fields']) ?: array('Status', 'Internal_Notes'),
        'column_labels'   => is_array($cl) && !empty($cl) ? $cl : array(),
        'sheet_urls'      => $d($row['sheet_urls'])      ?: array(),
        'display'         => $d($row['display'])         ?: array(),
        'supports_import' => false,
        'supports_sync'   => (bool)$row['supports_sync'],
        'supports_delete' => (bool)$row['supports_delete'],
    );
}

function neon_list_pipelines() {
    $result = neon_query('SELECT * FROM pipelines ORDER BY created_at');
    $out = array();
    foreach ($result['rows'] as $row) {
        $out[$row['key']] = neon_row_to_pipeline($row);
    }
    return $out;
}

function neon_get_pipeline($key) {
    $result = neon_query('SELECT * FROM pipelines WHERE key = $1', array($key));
    if (empty($result['rows'])) return null;
    return neon_row_to_pipeline($result['rows'][0]);
}

function neon_flatten_lead($row) {
    $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
    if (!is_array($data)) $data = array();
    return array_merge(
        array('Lead_ID' => $row['lead_id']),
        $data,
        array('Status' => $row['status'], 'Internal_Notes' => $row['internal_notes'])
    );
}

function neon_read_leads($pipeline) {
    $result = neon_query(
        'SELECT lead_id, data, status, internal_notes FROM leads WHERE pipeline_key = $1 ORDER BY created_at',
        array($pipeline['key'])
    );
    $leads = array();
    foreach ($result['rows'] as $row) {
        $leads[] = neon_flatten_lead($row);
    }
    return $leads;
}

function neon_update_lead($pipeline, $input) {
    $result = neon_query(
        'SELECT status, internal_notes FROM leads WHERE pipeline_key = $1 AND lead_id = $2',
        array($pipeline['key'], $input['Lead_ID'])
    );
    if (empty($result['rows'])) {
        json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
    }
    $existing  = $result['rows'][0];
    $newStatus = array_key_exists('Status', $input)         ? (string)$input['Status']        : $existing['status'];
    $newNotes  = array_key_exists('Internal_Notes', $input) ? (string)$input['Internal_Notes'] : $existing['internal_notes'];
    neon_query(
        'UPDATE leads SET status = $1, internal_notes = $2 WHERE pipeline_key = $3 AND lead_id = $4',
        array($newStatus, $newNotes, $pipeline['key'], $input['Lead_ID'])
    );
}

function neon_delete_lead($pipeline, $leadId) {
    $result = neon_query(
        'DELETE FROM leads WHERE pipeline_key = $1 AND lead_id = $2',
        array($pipeline['key'], $leadId)
    );
    if (($result['rowCount'] ?? 0) === 0) {
        json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
    }
}

function neon_update_column_labels($pipeline, $labels) {
    neon_query(
        'UPDATE pipelines SET column_labels = $1::jsonb WHERE key = $2',
        array(json_encode($labels, JSON_UNESCAPED_UNICODE), $pipeline['key'])
    );
    return $labels;
}

function neon_sync_pipeline($pipeline) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(array('success' => false, 'error' => 'Método inválido'), 405);
    }

    $sheetUrls = $pipeline['sheet_urls'];
    if (empty($sheetUrls)) {
        json_response(array('success' => false, 'error' => 'Nenhuma sheet_url configurada para este pipeline'), 400);
    }

    // Merge todas as abas por Lead_ID (LEFT JOIN: aba contato = base)
    $mergedByLeadId = array();
    foreach ($sheetUrls as $urlIndex => $url) {
        $csv    = fetch_csv($url);
        $handle = open_csv_string($csv);
        $headers = read_csv_headers($handle);
        if ($headers === false) {
            fclose($handle);
            json_response(array('success' => false, 'error' => 'CSV da aba ' . ($urlIndex + 1) . ' vazio ou sem headers'), 400);
        }

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($row) === 1 && trim($row[0]) === '') continue;

            $csvRow = row_to_assoc($headers, $row);
            $leadId = isset($csvRow['Lead_ID']) ? trim((string)$csvRow['Lead_ID']) : '';
            if ($leadId === '') continue;

            if (!isset($mergedByLeadId[$leadId])) {
                $mergedByLeadId[$leadId] = array('Lead_ID' => $leadId);
            }
            foreach ($csvRow as $k => $v) {
                if ($k !== 'Lead_ID') $mergedByLeadId[$leadId][$k] = $v;
            }
        }
        fclose($handle);
    }

    $pipelineKey = $pipeline['key'];

    $existingResult = neon_query('SELECT lead_id FROM leads WHERE pipeline_key = $1', array($pipelineKey));
    $existingIds = array();
    foreach ($existingResult['rows'] as $row) {
        $existingIds[$row['lead_id']] = true;
    }

    $queries  = array();
    $imported = 0; $new = 0; $updated = 0;

    foreach ($mergedByLeadId as $leadId => $mergedRow) {
        $data = $mergedRow;
        unset($data['Lead_ID']);
        $queries[] = array(
            'query'  => "INSERT INTO leads (pipeline_key, lead_id, data, status, internal_notes)
                         VALUES (\$1, \$2, \$3::jsonb, 'Novo', '')
                         ON CONFLICT (pipeline_key, lead_id)
                         DO UPDATE SET data = excluded.data",
            'params' => array($pipelineKey, $leadId, json_encode($data, JSON_UNESCAPED_UNICODE)),
        );
        $imported++;
        if (isset($existingIds[$leadId])) { $updated++; } else { $new++; }
    }

    if (!empty($queries)) {
        neon_transaction($queries);
    }

    $leads        = neon_read_leads($pipeline);
    $columnLabels = !empty($pipeline['column_labels'])
        ? $pipeline['column_labels']
        : labels_from_statuses($pipeline['statuses']);

    json_response(array(
        'success'      => true,
        'imported'     => $imported,
        'updated'      => $updated,
        'new'          => $new,
        'skipped'      => 0,
        'leads'        => $leads,
        'column_labels'=> $columnLabels,
        'last_updated' => gmdate('Y-m-d\TH:i:s\Z'),
        'last_synced'  => gmdate('Y-m-d\TH:i:s\Z'),
        'statuses'     => $pipeline['statuses'],
    ));
}

// ── resolução de pipeline ─────────────────────────────────────────────────────

function all_pipeline_configs(&$neonError = null) {
    $configs = array('real_estate' => file_pipeline_config());
    try {
        foreach (neon_list_pipelines() as $key => $config) {
            $configs[$key] = $config;
        }
    } catch (Throwable $e) {
        $neonError = $e->getMessage();
    }
    return $configs;
}

function current_pipeline() {
    $pipelineKey = isset($_GET['pipeline']) && $_GET['pipeline'] !== '' ? $_GET['pipeline'] : 'real_estate';

    if ($pipelineKey === 'real_estate') {
        return file_pipeline_config();
    }

    try {
        $config = neon_get_pipeline($pipelineKey);
        if ($config) return $config;
    } catch (Throwable $e) {
        json_response(array('success' => false, 'error' => 'Erro ao carregar pipeline: ' . $e->getMessage()), 500);
    }

    json_response(array('success' => false, 'error' => 'Pipeline inválido'), 400);
}

// ── roteamento ────────────────────────────────────────────────────────────────

try {
    // action=pipelines não precisa de pipeline específico
    if ($action === 'pipelines') {
        $neonError = null;
        $configs = all_pipeline_configs($neonError);
        $output  = array();
        foreach ($configs as $config) {
            $entry = array(
                'key'             => $config['key'],
                'name'            => $config['name'],
                'driver'          => $config['driver'],
                'statuses'        => $config['statuses'],
                'supports_import' => !empty($config['supports_import']),
                'supports_sync'   => !empty($config['supports_sync']),
                'supports_delete' => !empty($config['supports_delete']),
                'editable_fields' => $config['editable_fields'],
            );
            if (!empty($config['board_statuses'])) $entry['board_statuses'] = $config['board_statuses'];
            if (!empty($config['display']))         $entry['display']        = $config['display'];
            $output[] = $entry;
        }
        $resp = array('success' => true, 'pipelines' => $output);
        if ($neonError) $resp['neon_error'] = $neonError;
        json_response($resp);
    }

    $pipeline = current_pipeline();

    if ($action === 'get_leads') {
        if ($pipeline['driver'] === 'neon') {
            $leads        = neon_read_leads($pipeline);
            $columnLabels = !empty($pipeline['column_labels'])
                ? $pipeline['column_labels']
                : labels_from_statuses($pipeline['statuses']);
            json_response(array(
                'success'       => true,
                'pipeline'      => $pipeline['key'],
                'leads'         => $leads,
                'column_labels' => $columnLabels,
                'last_updated'  => '',
                'last_synced'   => '',
                'statuses'      => $pipeline['statuses'],
                'supports_import'=> false,
                'supports_sync'  => $pipeline['supports_sync'],
                'supports_delete'=> $pipeline['supports_delete'],
            ));
        }

        $data = read_data($pipeline);
        json_response(array(
            'success'        => true,
            'pipeline'       => $pipeline['key'],
            'leads'          => $data['leads'],
            'column_labels'  => $data['column_labels'],
            'last_updated'   => $data['last_updated'],
            'last_synced'    => $data['last_synced'],
            'statuses'       => $pipeline['statuses'],
            'supports_import'=> $pipeline['supports_import'],
            'supports_sync'  => $pipeline['supports_sync'],
            'supports_delete'=> $pipeline['supports_delete'],
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

        $labels = labels_from_statuses($pipeline['statuses']);
        if ($pipeline['driver'] === 'file') {
            $labels = $pipeline['column_labels'];
        }

        foreach ($pipeline['statuses'] as $status) {
            if (isset($input['column_labels'][$status])) {
                $label = trim((string)$input['column_labels'][$status]);
                $labels[$status] = $label !== '' ? $label : $status;
            }
        }

        if ($pipeline['driver'] === 'neon') {
            $labels = neon_update_column_labels($pipeline, $labels);
        } else {
            $data = read_data($pipeline);
            $data['column_labels'] = $labels;
            write_data($pipeline, $data);
        }

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

        if ($pipeline['driver'] === 'neon') {
            neon_update_lead($pipeline, $input);
            json_response(array('success' => true));
        }

        $data  = read_data($pipeline);
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

    if ($action === 'delete_lead') {
        if (!$pipeline['supports_delete']) {
            json_response(array('success' => false, 'error' => 'Excluir lead não está disponível neste pipeline'), 400);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input['Lead_ID'])) {
            json_response(array('success' => false, 'error' => 'Lead_ID obrigatório'), 400);
        }

        if ($pipeline['driver'] === 'neon') {
            neon_delete_lead($pipeline, $input['Lead_ID']);
            json_response(array('success' => true));
        }

        $data            = read_data($pipeline);
        $found           = false;
        $remainingLeads  = array();

        foreach ($data['leads'] as $lead) {
            if (isset($lead['Lead_ID']) && $lead['Lead_ID'] === $input['Lead_ID']) {
                $found = true;
                continue;
            }
            $remainingLeads[] = $lead;
        }

        if (!$found) {
            json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
        }

        $data['leads'] = $remainingLeads;
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

        if ($pipeline['driver'] === 'neon') {
            neon_sync_pipeline($pipeline);
        }
    }

    json_response(array('success' => false, 'error' => 'Ação inválida'), 400);
} catch (Exception $e) {
    json_response(array('success' => false, 'error' => $e->getMessage()), 500);
}
