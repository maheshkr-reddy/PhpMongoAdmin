<?php
/** Requests that bypass the HTML layout: nav AJAX, print, inline-edit, export. */

/* ------------------------------------- intercepts that bypass HTML layout */

// Sidebar accordion -> JSON list of a database's collections.
if (((isset($_GET['do']) ? $_GET['do'] : (''))) === 'nav_cols') {
    header('Content-Type: application/json');
    try {
        echo json_encode(['ok' => true, 'collections' => $mongo->listCollections((isset($_GET['db']) ? $_GET['db'] : ('')))]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Printable view of selected collections (standalone page -> browser "Save as PDF").
if (((isset($_GET['action']) ? $_GET['action'] : (''))) === 'print' && ((isset($_GET['db']) ? $_GET['db'] : (''))) !== '' && ((isset($_GET['collection']) ? $_GET['collection'] : (''))) === '') {
    render_print($config, $mongo, (string)$_GET['db'], (array)((isset($_GET['collections']) ? $_GET['collections'] : ([]))));
    exit;
}

// AJAX inline cell edit -> JSON response.
if (((isset($_POST['do']) ? $_POST['do'] : (''))) === 'update_field') {
    csrf_check();
    header('Content-Type: application/json');
    try {
        $value = $mongo->setField((isset($_POST['db']) ? $_POST['db'] : ('')), (isset($_POST['collection']) ? $_POST['collection'] : ('')),
                                  (isset($_POST['id']) ? $_POST['id'] : ('')), (isset($_POST['field']) ? $_POST['field'] : ('')), (isset($_POST['value']) ? $_POST['value'] : ('')));
        echo json_encode([
            'ok'      => true,
            'preview' => cell_preview($value),
            'json'    => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Export -> streamed file/zip download (must precede any output).
if (((isset($_POST['do']) ? $_POST['do'] : (''))) === 'export') {
    csrf_check();
    try {
        handle_export($mongo, $_POST);
    } catch (Exception $e) {
        flash('Export failed: ' . $e->getMessage(), 'error');
        redirect(['db' => (isset($_POST['db']) ? $_POST['db'] : ('')), 'action' => 'export']);
    }
    exit;
}

