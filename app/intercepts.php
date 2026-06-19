<?php
/** Requests that bypass the HTML layout: nav AJAX, print, inline-edit, export. */

/* ------------------------------------- intercepts that bypass HTML layout */

// Sidebar accordion -> JSON list of a database's collections.
if (($_GET['do'] ?? '') === 'nav_cols') {
    header('Content-Type: application/json');
    try {
        echo json_encode(['ok' => true, 'collections' => $mongo->listCollections($_GET['db'] ?? '')]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Printable view of selected collections (standalone page -> browser "Save as PDF").
if (($_GET['action'] ?? '') === 'print' && ($_GET['db'] ?? '') !== '' && ($_GET['collection'] ?? '') === '') {
    render_print($config, $mongo, (string)$_GET['db'], (array)($_GET['collections'] ?? []));
    exit;
}

// AJAX inline cell edit -> JSON response.
if (($_POST['do'] ?? '') === 'update_field') {
    csrf_check();
    header('Content-Type: application/json');
    try {
        $value = $mongo->setField($_POST['db'] ?? '', $_POST['collection'] ?? '',
                                  $_POST['id'] ?? '', $_POST['field'] ?? '', $_POST['value'] ?? '');
        echo json_encode([
            'ok'      => true,
            'preview' => cell_preview($value),
            'json'    => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Export -> streamed file/zip download (must precede any output).
if (($_POST['do'] ?? '') === 'export') {
    csrf_check();
    try {
        handle_export($mongo, $_POST);
    } catch (Throwable $e) {
        flash('Export failed: ' . $e->getMessage(), 'error');
        redirect(['db' => $_POST['db'] ?? '', 'action' => 'export']);
    }
    exit;
}

