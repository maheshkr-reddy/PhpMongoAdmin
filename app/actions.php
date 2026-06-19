<?php
/** Every state-changing POST action. */

function handle_post(Mongo $mongo, array $config): void
{
    csrf_check();
    $do   = $_POST['do'] ?? '';
    $db   = $_POST['db'] ?? '';
    $coll = $_POST['collection'] ?? '';
    try {
        switch ($do) {
            case 'create_db':
                $mongo->createDatabase(trim($_POST['name']), trim($_POST['firstCollection'] ?: 'data'));
                flash('Database "' . $_POST['name'] . '" created.');
                redirect(['db' => $_POST['name']]);

            case 'drop_db':
                $mongo->dropDatabase($db);
                flash('Database "' . $db . '" dropped.');
                redirect([]);

            case 'create_collection':
                $ccName = trim($_POST['name']);
                $ccOpts = [];
                if (($_POST['type'] ?? 'standard') === 'capped') {
                    $ccOpts = ['capped' => true, 'size' => (int)($_POST['size'] ?? 0), 'max' => (int)($_POST['max'] ?? 0)];
                }
                $mongo->createCollection($db, $ccName, $ccOpts);
                flash('Collection "' . $ccName . '" created.');
                redirect(['db' => $db, 'collection' => $ccName]);

            case 'rename_db': {
                $to = trim($_POST['newName'] ?? '');
                if ($to === '') throw new RuntimeException('A new database name is required.');
                $n = $mongo->renameDatabase($db, $to);
                flash('Database renamed to "' . $to . '" (' . $n . ' collection(s) moved).');
                redirect(['db' => $to, 'action' => 'operations']);
            }
            case 'copy_db': {
                $to = trim($_POST['newName'] ?? '');
                if ($to === '') throw new RuntimeException('A target database name is required.');
                $n = $mongo->copyDatabase($db, $to, !empty($_POST['copyIndexes']));
                flash('Copied ' . $n . ' collection(s) to "' . $to . '".');
                redirect(['db' => $to, 'action' => 'operations']);
            }

            /* ---- bulk collection actions (database Structure page) ---- */
            case 'bulk_copy_collections': {
                $colls    = array_values(array_filter((array)($_POST['collections'] ?? []), 'strlen'));
                $targetDb = trim($_POST['targetDb'] ?? '') ?: $db;
                foreach ($colls as $c) $mongo->copyCollection($db, $c, $targetDb, $c, true);
                flash(count($colls) . ' collection(s) copied to "' . $targetDb . '".');
                redirect(['db' => $db]);
            }
            case 'bulk_copy_prefix': {
                $colls    = array_values(array_filter((array)($_POST['collections'] ?? []), 'strlen'));
                $targetDb = trim($_POST['targetDb'] ?? '') ?: $db;
                $prefix   = trim($_POST['prefix'] ?? '');
                foreach ($colls as $c) $mongo->copyCollection($db, $c, $targetDb, $prefix . $c, true);
                flash('Copied ' . count($colls) . ' collection(s) to "' . $targetDb . '" with prefix "' . $prefix . '".');
                redirect(['db' => $db]);
            }
            case 'bulk_empty': {
                $colls = array_values(array_filter((array)($_POST['collections'] ?? []), 'strlen'));
                $tot = 0; foreach ($colls as $c) $tot += $mongo->emptyCollection($db, $c);
                flash('Emptied ' . count($colls) . ' collection(s) — ' . $tot . ' document(s) removed.');
                redirect(['db' => $db]);
            }
            case 'bulk_drop': {
                $colls = array_values(array_filter((array)($_POST['collections'] ?? []), 'strlen'));
                foreach ($colls as $c) $mongo->dropCollection($db, $c);
                flash(count($colls) . ' collection(s) dropped.');
                redirect(['db' => $db]);
            }
            case 'bulk_add_prefix': {
                $colls  = array_values(array_filter((array)($_POST['collections'] ?? []), 'strlen'));
                $prefix = trim($_POST['prefix'] ?? '');
                if ($prefix === '') throw new RuntimeException('A prefix is required.');
                foreach ($colls as $c) $mongo->renameCollection($db, $c, $prefix . $c);
                flash('Added prefix "' . $prefix . '" to ' . count($colls) . ' collection(s).');
                redirect(['db' => $db]);
            }
            case 'bulk_replace_prefix': {
                $colls = array_values(array_filter((array)($_POST['collections'] ?? []), 'strlen'));
                $from  = trim($_POST['fromPrefix'] ?? '');
                $to    = trim($_POST['toPrefix'] ?? '');
                $n = 0;
                foreach ($colls as $c) {
                    if ($from !== '' && substr($c, 0, strlen($from)) === $from) {
                        $mongo->renameCollection($db, $c, $to . substr($c, strlen($from)));
                        $n++;
                    }
                }
                flash('Replaced prefix on ' . $n . ' of ' . count($colls) . ' collection(s).');
                redirect(['db' => $db]);
            }

            case 'insert_fields':
                $mongo->insertFields($db, $coll, (array)($_POST['keys'] ?? []), (array)($_POST['vals'] ?? []));
                flash('Document inserted.');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'browse']);

            case 'find_replace': {
                $field = trim($_POST['field'] ?? '');
                $n = $mongo->findReplace($db, $coll, $field, (string)($_POST['find'] ?? ''),
                                         (string)($_POST['replace'] ?? ''), !empty($_POST['whole']));
                flash('Replaced in ' . $n . ' document(s).');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'find']);
            }

            case 'drop_collection':
                $mongo->dropCollection($db, $coll);
                flash('Collection "' . $coll . '" dropped.');
                redirect(['db' => $db]);

            case 'insert':
                $mongo->insert($db, $coll, $_POST['document']);
                flash('Document inserted.');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'browse']);

            case 'update':
                $mongo->replaceById($db, $coll, $_POST['id'], $_POST['document']);
                flash('Document updated.');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'browse']);

            case 'update_fields': {
                $keys = (array) ($_POST['keys'] ?? []);
                $vals = (array) ($_POST['vals'] ?? []);
                $doc  = [];
                foreach ($keys as $i => $k) {
                    $k = trim((string) $k);
                    if ($k === '' || $k === '_id') continue;     // _id is preserved on replace
                    $doc[$k] = Mongo::parseScalarOrJson((string) ($vals[$i] ?? ''));
                }
                $mongo->replaceById($db, $coll, $_POST['id'] ?? '', json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                flash('Document updated.');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'browse']);
            }

            case 'delete':
                $mongo->deleteById($db, $coll, $_POST['id']);
                flash('Document deleted.');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'browse']);

            case 'import':
                $report = handle_import($mongo, $db, $_POST, $_FILES['file'] ?? null);
                flash($report);
                $coll = $_POST['collection'] ?? '';
                redirect($coll !== '' ? ['db' => $db, 'collection' => $coll, 'action' => 'import']
                                      : ['db' => $db, 'action' => 'import']);

            case 'appearance':
                $newLang  = $_POST['lang']  ?? 'en';
                $newTheme = $_POST['theme'] ?? 'light';
                if (isset($config['languages'][$newLang])) setcookie('pma_lang', $newLang, time() + 31536000, '/');
                if (isset($config['themes'][$newTheme]))   setcookie('pma_theme', $newTheme, time() + 31536000, '/');
                flash(t('appear.saved'));
                redirect([]);

            /* ---- collection operations ---- */
            case 'rename_collection': {
                $newName = trim($_POST['newName'] ?? '');
                $mongo->renameCollection($db, $coll, $newName);
                flash('Collection renamed to "' . $newName . '".');
                redirect(['db' => $db, 'collection' => $newName, 'action' => 'operations']);
            }
            case 'move_collection': {
                $targetDb = trim($_POST['targetDb'] ?? '');
                $newName  = trim($_POST['newName'] ?? '') ?: $coll;
                if ($targetDb === '') throw new RuntimeException('Target database is required.');
                $n = $mongo->moveCollection($db, $coll, $targetDb, $newName);
                flash($targetDb === $db
                    ? 'Collection renamed to "' . $newName . '".'
                    : 'Moved ' . $n . ' document(s) to ' . $targetDb . '.' . $newName . '.');
                redirect(['db' => $targetDb, 'collection' => $newName, 'action' => 'browse']);
            }
            case 'copy_collection': {
                $targetDb   = trim($_POST['targetDb'] ?? '') ?: $db;
                $newName    = trim($_POST['newName'] ?? '') ?: $coll;
                $copyIdx    = !empty($_POST['copyIndexes']);
                $n = $mongo->copyCollection($db, $coll, $targetDb, $newName, $copyIdx);
                flash('Copied ' . $n . ' document(s) to ' . $targetDb . '.' . $newName . '.');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'operations']);
            }
            case 'add_field': {
                $field = trim($_POST['field'] ?? '');
                $n = $mongo->addField($db, $coll, $field, $_POST['value'] ?? '', !empty($_POST['onlyMissing']));
                flash('Field "' . $field . '" added to ' . $n . ' document(s).');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'operations']);
            }
            case 'remove_field': {
                $field = trim($_POST['field'] ?? '');
                $n = $mongo->removeField($db, $coll, $field);
                flash('Field "' . $field . '" removed from ' . $n . ' document(s).');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'operations']);
            }

            /* ---- with-selected (bulk) document actions ---- */
            case 'bulk_delete': {
                $ids = array_values(array_filter((array)($_POST['ids'] ?? []), 'strlen'));
                $n = $mongo->deleteByIds($db, $coll, $ids);
                flash($n . ' document(s) deleted.');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'browse']);
            }
            case 'bulk_copy': {
                $ids      = array_values(array_filter((array)($_POST['ids'] ?? []), 'strlen'));
                $targetDb = trim($_POST['targetDb'] ?? '') ?: $db;
                $targetColl = trim($_POST['targetColl'] ?? '') ?: $coll;
                $n = $mongo->copyDocuments($db, $coll, $targetDb, $targetColl, $ids);
                flash('Copied ' . $n . ' document(s) to ' . $targetDb . '.' . $targetColl . '.');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'browse']);
            }
            case 'update_multi': {
                $ids  = (array)($_POST['ids'] ?? []);
                $docs = (array)($_POST['docs'] ?? []);
                $n = 0;
                foreach ($ids as $i => $idJson) {
                    if (!isset($docs[$i])) continue;
                    $mongo->replaceById($db, $coll, (string)$idJson, (string)$docs[$i]);
                    $n++;
                }
                flash($n . ' document(s) updated.');
                redirect(['db' => $db, 'collection' => $coll, 'action' => 'browse']);
            }
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
        // Fall back to a sensible page on error.
        $opDos = ['rename_collection','move_collection','copy_collection','add_field','remove_field'];
        $dbOpDos = ['rename_db','copy_db','create_collection'];
        $collBulk = ['bulk_copy_collections','bulk_copy_prefix','bulk_empty','bulk_drop','bulk_add_prefix','bulk_replace_prefix'];
        if (($_POST['do'] ?? '') === 'import') redirect(['db' => $db, 'action' => 'import']);
        if (($_POST['do'] ?? '') === 'insert_fields' && $coll !== '') redirect(['db' => $db, 'collection' => $coll, 'action' => 'insert']);
        if (($_POST['do'] ?? '') === 'find_replace' && $coll !== '') redirect(['db' => $db, 'collection' => $coll, 'action' => 'find']);
        if (($_POST['do'] ?? '') === 'update_fields' && $coll !== '') redirect(['db' => $db, 'collection' => $coll, 'action' => 'edit', 'id' => $_POST['id'] ?? '']);
        if (in_array($_POST['do'] ?? '', $collBulk, true) && $db !== '') redirect(['db' => $db]);
        if (in_array($_POST['do'] ?? '', $opDos, true) && $coll !== '') redirect(['db' => $db, 'collection' => $coll, 'action' => 'operations']);
        if (in_array($_POST['do'] ?? '', $dbOpDos, true) && $db !== '') redirect(['db' => $db, 'action' => 'operations']);
        if ($coll !== '') redirect(['db' => $db, 'collection' => $coll, 'action' => $_POST['return'] ?? 'browse']);
        if ($db !== '')   redirect(['db' => $db]);
        redirect([]);
    }
}
