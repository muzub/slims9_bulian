<?php

use SLiMS\Config;
use SLiMS\DB;
use SLiMS\Csv\Reader;
use SLiMS\Csv\Row;
use SLiMS\Csv\Writer;
use SLiMS\Filesystems\Storage;

const SAFE_IMPORT_SETTING_NAME = 'safe_import_plugin';
const SAFE_IMPORT_SESSION_TABLE = 'plugin_safe_import_sessions';
const SAFE_IMPORT_ENTRY_TABLE = 'plugin_safe_import_entries';

function safeImportDefaultSettings(): array
{
    return [
        'preview_per_page' => 10,
        'history_limit' => 20,
        'stop_on_error' => 1,
        'auto_delete_temp' => 1
    ];
}

function safeImportSettings(): array
{
    $settings = config(SAFE_IMPORT_SETTING_NAME, []);
    if (!is_array($settings)) $settings = [];
    return array_merge(safeImportDefaultSettings(), $settings);
}

function safeImportSaveSettings(array $source): array
{
    $settings = [
        'preview_per_page' => max(5, min(100, (int)($source['preview_per_page'] ?? 10))),
        'history_limit' => max(5, min(100, (int)($source['history_limit'] ?? 20))),
        'stop_on_error' => empty($source['stop_on_error']) ? 0 : 1,
        'auto_delete_temp' => empty($source['auto_delete_temp']) ? 0 : 1
    ];

    Config::createOrUpdate(SAFE_IMPORT_SETTING_NAME, $settings);
    return $settings;
}

function safeImportHttpQuery(array $query = [], array $remove = []): string
{
    $current = $_GET;
    foreach ($remove as $key) unset($current[$key]);
    return http_build_query(array_merge($current, $query));
}

function safeImportUrl(array $query = [], array $remove = []): string
{
    $queryString = safeImportHttpQuery($query, $remove);
    return $_SERVER['PHP_SELF'] . ($queryString ? '?' . $queryString : '');
}

function safeImportStorageDirectory(): string
{
    return 'temp' . DS . 'safe_import';
}

function safeImportEnsureStorage(): void
{
    $disk = Storage::files();
    if (!$disk->isExists('temp')) $disk->makeDirectory('temp');
    if (!$disk->isExists(safeImportStorageDirectory())) $disk->makeDirectory(safeImportStorageDirectory());
}

function safeImportNow(): string
{
    return date('Y-m-d H:i:s');
}

function safeImportSerialize($value): string
{
    return serialize($value);
}

function safeImportUnserialize(?string $value, $default = [])
{
    if (empty($value)) return $default;
    $result = @unserialize($value);
    return $result === false && $value !== 'b:0;' ? $default : $result;
}

function safeImportColumns(string $type): array
{
    if ($type === 'item') {
        return [
            'header' => 'No.,item_code,call_number,coll_type_name,inventory_code,received_date,supplier_name,order_no,location_name,order_date,item_status_name,site,source,invoice,price,price_currency,invoice_date,input_date,last_update,title',
            'sample' => [
                'item_code','call_number','coll_type_name','inventory_code',
                'received_date','supplier_name','order_no','location_name',
                'order_date','item_status_name','site','source','invoice',
                'price','price_currency','invoice_date','input_date','last_update','title'
            ],
            'label' => __('Item / Exemplar Import')
        ];
    }

    return [
        'header' => 'No.,title,gmd_name,edition,isbn_issn,publisher_name,publish_year,collation,series_title,call_number,language_name,place_name,classification,notes,image,sor,authors,topics,item_code',
        'sample' => [
            'title','gmd_name','edition',
            'isbn_issn','publisher_name',
            'publish_year','collation',
            'series_title','call_number',
            'language_name','place_name',
            'classification','notes','image',
            'sor','authors','topics','item_code'
        ],
        'label' => __('Biblio Import')
    ];
}

function safeImportDownloadSample(string $type): void
{
    $columns = safeImportColumns($type);
    $csv = new Writer;
    $csv->add(new Row($columns['sample']));
    $csv->download($type === 'item' ? 'safe_item_sample_import' : 'safe_biblio_sample_import');
}

function safeImportCreateSession(array $data): int
{
    $query = DB::getInstance()->prepare('INSERT INTO '.SAFE_IMPORT_SESSION_TABLE.' (batch_name, import_type, file_name, temp_file, format_options, status, notes, uid, created_at, updated_at) VALUES (:batch_name, :import_type, :file_name, :temp_file, :format_options, :status, :notes, :uid, :created_at, :updated_at)');
    $query->execute([
        'batch_name' => $data['batch_name'],
        'import_type' => $data['import_type'],
        'file_name' => $data['file_name'],
        'temp_file' => $data['temp_file'],
        'format_options' => safeImportSerialize($data['format_options'] ?? []),
        'status' => $data['status'] ?? 'prepared',
        'notes' => $data['notes'] ?? null,
        'uid' => $data['uid'] ?? null,
        'created_at' => safeImportNow(),
        'updated_at' => safeImportNow()
    ]);

    return (int)DB::getInstance()->lastInsertId();
}

function safeImportUpdateSession(int $sessionId, array $data): void
{
    if (empty($data)) return;

    $fields = [];
    $params = ['id' => $sessionId];
    foreach ($data as $column => $value) {
        $fields[] = "`{$column}` = :{$column}";
        $params[$column] = $value;
    }

    DB::getInstance()->prepare('UPDATE '.SAFE_IMPORT_SESSION_TABLE.' SET '.implode(', ', $fields).' WHERE id = :id')->execute($params);
}

function safeImportGetSession(int $sessionId): ?array
{
    $query = DB::getInstance()->prepare('SELECT * FROM '.SAFE_IMPORT_SESSION_TABLE.' WHERE id = :id LIMIT 1');
    $query->execute(['id' => $sessionId]);
    $session = $query->fetch(PDO::FETCH_ASSOC);
    if (!$session) return null;
    $session['format_options'] = safeImportUnserialize($session['format_options'], []);
    return $session;
}

function safeImportRecentSessions(int $limit): array
{
    $query = DB::getInstance()->prepare('SELECT * FROM '.SAFE_IMPORT_SESSION_TABLE.' ORDER BY id DESC LIMIT :limit');
    $query->bindValue(':limit', $limit, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function safeImportCreateReader(array $format): Reader
{
    return new Reader([
        'separator' => trim($format['fieldSep'] ?? config('csv.separator')),
        'enclosed_with' => trim($format['fieldEnc'] ?? config('csv.enclosed_with')),
        'record_separator' => [
            'newline' => "\n",
            'return' => "\t"
        ]
    ]);
}

function safeImportPreviewRows(array $session, int $limit): array
{
    $disk = Storage::files();
    if (empty($session['temp_file']) || !$disk->isExists($session['temp_file'])) return [];

    $stream = $disk->readStream($session['temp_file']);
    $reader = safeImportCreateReader($session['format_options']);
    $reader->readFromStream($stream)->setLimit($limit);
    $rows = $reader->getFields();
    fclose($stream);
    return $rows;
}

function safeImportCountRows(array $session): int
{
    $disk = Storage::files();
    if (empty($session['temp_file']) || !$disk->isExists($session['temp_file'])) return 0;

    $stream = $disk->readStream($session['temp_file']);
    $reader = safeImportCreateReader($session['format_options']);
    $total = $reader->readFromStream($stream)->getTotalLine();
    fclose($stream);
    return (int)$total;
}

function safeImportDeleteTempFile(?string $tempFile): void
{
    if (empty($tempFile)) return;
    $disk = Storage::files();
    if ($disk->isExists($tempFile)) $disk->delete($tempFile);
}

function safeImportRecordEntry(int $sessionId, string $entityType, string $actionType, ?int $entityId = null, ?string $entityKey = null, $snapshot = null): void
{
    $query = DB::getInstance()->prepare('INSERT INTO '.SAFE_IMPORT_ENTRY_TABLE.' (session_id, entity_type, action_type, entity_id, entity_key, snapshot, created_at) VALUES (:session_id, :entity_type, :action_type, :entity_id, :entity_key, :snapshot, :created_at)');
    $query->execute([
        'session_id' => $sessionId,
        'entity_type' => $entityType,
        'action_type' => $actionType,
        'entity_id' => $entityId,
        'entity_key' => $entityKey,
        'snapshot' => $snapshot === null ? null : safeImportSerialize($snapshot),
        'created_at' => safeImportNow()
    ]);
}

function safeImportEntries(int $sessionId): array
{
    $query = DB::getInstance()->prepare('SELECT * FROM '.SAFE_IMPORT_ENTRY_TABLE.' WHERE session_id = :session_id ORDER BY id DESC');
    $query->execute(['session_id' => $sessionId]);
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function safeImportRegisterShutdown(int $sessionId): void
{
    register_shutdown_function(static function () use ($sessionId) {
        $error = error_get_last();
        if (!$error) return;
        if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;

        safeImportUpdateSession($sessionId, [
            'status' => 'failed',
            'error_message' => ($error['message'] ?? 'Fatal error').' on line '.($error['line'] ?? 0),
            'updated_at' => safeImportNow(),
            'finished_at' => safeImportNow()
        ]);
    });
}

function safeImportNormalizeField($value)
{
    if ($value === null) return null;
    $value = str_replace('\\', '', trim((string)$value));
    return $value === '' ? null : $value;
}

function safeImportCsvRow($stream, array $format, int $maxChars = 102400)
{
    return fgetcsv($stream, $maxChars, trim($format['fieldSep']), trim($format['fieldEnc']));
}

function safeImportBiblioStatements(): array
{
    return [
        'biblio' => DB::getInstance()->prepare('INSERT INTO biblio (title, gmd_id, edition, isbn_issn, publisher_id, publish_year, collation, series_title, call_number, language_id, publish_place_id, classification, notes, image, sor, input_date, last_update, uid) VALUES (:title, :gmd_id, :edition, :isbn_issn, :publisher_id, :publish_year, :collation, :series_title, :call_number, :language_id, :publish_place_id, :classification, :notes, :image, :sor, :input_date, :last_update, :uid)'),
        'author' => DB::getInstance()->prepare('INSERT IGNORE INTO biblio_author (biblio_id, author_id, level) VALUES (:biblio_id, :author_id, :level)'),
        'topic' => DB::getInstance()->prepare('INSERT IGNORE INTO biblio_topic (biblio_id, topic_id, level) VALUES (:biblio_id, :topic_id, :level)'),
        'item' => DB::getInstance()->prepare('INSERT IGNORE INTO item (biblio_id, item_code, input_date, last_update, uid) VALUES (:biblio_id, :item_code, :input_date, :last_update, :uid)')
    ];
}

function safeImportItemStatements(): array
{
    return [
        'find_biblio' => DB::getInstance()->prepare('SELECT biblio_id FROM biblio WHERE title = :title LIMIT 1'),
        'find_item' => DB::getInstance()->prepare('SELECT * FROM item WHERE item_code = :item_code LIMIT 1'),
        'insert_item' => DB::getInstance()->prepare('INSERT INTO item (biblio_id, item_code, call_number, coll_type_id, inventory_code, received_date, supplier_id, order_no, location_id, order_date, item_status_id, site, source, invoice, price, price_currency, invoice_date, input_date, last_update, uid) VALUES (:biblio_id, :item_code, :call_number, :coll_type_id, :inventory_code, :received_date, :supplier_id, :order_no, :location_id, :order_date, :item_status_id, :site, :source, :invoice, :price, :price_currency, :invoice_date, :input_date, :last_update, :uid)'),
        'update_item' => DB::getInstance()->prepare('UPDATE item SET biblio_id = :biblio_id, item_code = :item_code, call_number = :call_number, coll_type_id = :coll_type_id, inventory_code = :inventory_code, received_date = :received_date, supplier_id = :supplier_id, order_no = :order_no, location_id = :location_id, order_date = :order_date, item_status_id = :item_status_id, site = :site, source = :source, invoice = :invoice, price = :price, price_currency = :price_currency, invoice_date = :invoice_date, input_date = :input_date, last_update = :last_update, uid = :uid WHERE item_id = :item_id'),
        'restore_item' => DB::getInstance()->prepare('UPDATE item SET biblio_id = :biblio_id, item_code = :item_code, call_number = :call_number, coll_type_id = :coll_type_id, inventory_code = :inventory_code, received_date = :received_date, supplier_id = :supplier_id, order_no = :order_no, location_id = :location_id, order_date = :order_date, item_status_id = :item_status_id, site = :site, source = :source, invoice = :invoice, price = :price, price_currency = :price_currency, invoice_date = :invoice_date, input_date = :input_date, last_update = :last_update, uid = :uid WHERE item_id = :item_id'),
        'delete_item' => DB::getInstance()->prepare('DELETE FROM item WHERE item_id = :item_id')
    ];
}

function safeImportPrepareItemPayload(array $field, mysqli $dbs): array
{
    $field = array_pad($field, 19, null);
    $ctCache = [];
    $locCache = [];
    $statusCache = [];
    $supplierCache = [];

    $inputDate = safeImportNormalizeField($field[16]) ?? safeImportNow();
    $lastUpdate = safeImportNormalizeField($field[17]) ?? safeImportNow();

    return [
        'item_code' => safeImportNormalizeField($field[0]),
        'call_number' => safeImportNormalizeField($field[1]),
        'coll_type_id' => ($value = safeImportNormalizeField($field[2])) ? utility::getID($dbs, 'mst_coll_type', 'coll_type_id', 'coll_type_name', $value, $ctCache) : null,
        'inventory_code' => safeImportNormalizeField($field[3]),
        'received_date' => safeImportNormalizeField($field[4]),
        'supplier_id' => ($value = safeImportNormalizeField($field[5])) ? utility::getID($dbs, 'mst_supplier', 'supplier_id', 'supplier_name', $value, $supplierCache) : null,
        'order_no' => safeImportNormalizeField($field[6]),
        'location_id' => ($value = safeImportNormalizeField($field[7])) ? utility::getID($dbs, 'mst_location', 'location_id', 'location_name', $value, $locCache) : null,
        'order_date' => safeImportNormalizeField($field[8]),
        'item_status_id' => ($value = safeImportNormalizeField($field[9])) ? utility::getID($dbs, 'mst_item_status', 'item_status_id', 'item_status_name', $value, $statusCache) : null,
        'site' => safeImportNormalizeField($field[10]),
        'source' => safeImportNormalizeField($field[11]) ?? 0,
        'invoice' => safeImportNormalizeField($field[12]),
        'price' => safeImportNormalizeField($field[13]),
        'price_currency' => safeImportNormalizeField($field[14]),
        'invoice_date' => safeImportNormalizeField($field[15]),
        'input_date' => $inputDate,
        'last_update' => $lastUpdate,
        'title' => safeImportNormalizeField($field[18]),
    ];
}

function safeImportRun(int $sessionId, array $settings, mysqli $dbs, $indexer = null): array
{
    $session = safeImportGetSession($sessionId);
    if (!$session) throw new RuntimeException(__('Import session not found'));

    $disk = Storage::files();
    if (empty($session['temp_file']) || !$disk->isExists($session['temp_file'])) {
        throw new RuntimeException(__('Import file is missing. Please upload the CSV again.'));
    }

    $format = $session['format_options'];
    $format['fieldSep'] = trim($format['fieldSep'] ?? config('csv.separator'));
    $format['fieldEnc'] = trim($format['fieldEnc'] ?? config('csv.enclosed_with'));
    $offset = max(1, (int)($format['recordOffset'] ?? 1));
    $recordNum = max(0, (int)($format['recordNum'] ?? 0));
    $hasHeader = !empty($format['header']);
    $uid = $_SESSION['uid'] ?? null;

    safeImportUpdateSession($sessionId, [
        'status' => 'running',
        'started_at' => safeImportNow(),
        'updated_at' => safeImportNow(),
        'error_message' => null
    ]);

    safeImportRegisterShutdown($sessionId);

    $file = $disk->readStream($session['temp_file']);
    $totalLine = max(1, safeImportCountRows($session));
    $rowNumber = 0;
    $processed = 0;
    $success = 0;
    $skipped = 0;
    $errors = [];
    $headerSkipped = false;
    $biblioCaches = ['gmd' => [], 'publisher' => [], 'language' => [], 'place' => [], 'author' => [], 'topic' => []];
    $biblioStatements = $session['import_type'] === 'biblio' ? safeImportBiblioStatements() : [];
    $itemStatements = $session['import_type'] === 'item' ? safeImportItemStatements() : [];

    set_time_limit(0);
    ob_implicit_flush();

    try {
        while (($field = safeImportCsvRow($file, $format)) !== false) {
            if ($field === null || $field === [null]) continue;

            $rowNumber++;
            if ($rowNumber < $offset) continue;
            if ($hasHeader && !$headerSkipped) {
                $headerSkipped = true;
                continue;
            }
            if ($recordNum > 0 && $processed >= $recordNum) break;

            $processed++;

            try {
                if ($session['import_type'] === 'item') {
                    $payload = safeImportPrepareItemPayload($field, $dbs);
                    if (empty($payload['item_code'])) {
                        throw new RuntimeException(__('Item code is required'));
                    }
                    if (empty($payload['title'])) {
                        throw new RuntimeException(__('Title is required to match the bibliography record'));
                    }

                    $itemStatements['find_biblio']->execute(['title' => $payload['title']]);
                    $biblio = $itemStatements['find_biblio']->fetch(PDO::FETCH_ASSOC);
                    if (!$biblio) {
                        throw new RuntimeException(sprintf(__('Title "%s" was not found in bibliography data'), $payload['title']));
                    }

                    $payload['biblio_id'] = (int)$biblio['biblio_id'];
                    $payload['uid'] = $uid;

                    $itemStatements['find_item']->execute(['item_code' => $payload['item_code']]);
                    $existingItem = $itemStatements['find_item']->fetch(PDO::FETCH_ASSOC);

                    if ($existingItem) {
                        safeImportRecordEntry($sessionId, 'item', 'update', (int)$existingItem['item_id'], $existingItem['item_code'], $existingItem);
                        $payload['item_id'] = (int)$existingItem['item_id'];
                        $itemStatements['update_item']->execute($payload);
                    } else {
                        $itemStatements['insert_item']->execute($payload);
                        $itemId = (int)DB::getInstance()->lastInsertId();
                        safeImportRecordEntry($sessionId, 'item', 'insert', $itemId, $payload['item_code'], ['item_id' => $itemId, 'item_code' => $payload['item_code']]);
                    }
                } else {
                    $field = array_pad($field, 18, null);
                    $title = safeImportNormalizeField($field[0]);
                    if (empty($title)) throw new RuntimeException(__('Title is required'));

                    $payload = [
                        'title' => $title,
                        'gmd_id' => ($value = safeImportNormalizeField($field[1])) ? utility::getID($dbs, 'mst_gmd', 'gmd_id', 'gmd_name', $value, $biblioCaches['gmd']) : null,
                        'edition' => safeImportNormalizeField($field[2]),
                        'isbn_issn' => safeImportNormalizeField($field[3]),
                        'publisher_id' => ($value = safeImportNormalizeField($field[4])) ? utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $value, $biblioCaches['publisher']) : null,
                        'publish_year' => safeImportNormalizeField($field[5]),
                        'collation' => safeImportNormalizeField($field[6]),
                        'series_title' => safeImportNormalizeField($field[7]),
                        'call_number' => safeImportNormalizeField($field[8]),
                        'language_id' => ($value = safeImportNormalizeField($field[9])) ? utility::getID($dbs, 'mst_language', 'language_id', 'language_name', $value, $biblioCaches['language']) : null,
                        'publish_place_id' => ($value = safeImportNormalizeField($field[10])) ? utility::getID($dbs, 'mst_place', 'place_id', 'place_name', $value, $biblioCaches['place']) : null,
                        'classification' => safeImportNormalizeField($field[11]),
                        'notes' => safeImportNormalizeField($field[12]),
                        'image' => safeImportNormalizeField($field[13]),
                        'sor' => safeImportNormalizeField($field[14]),
                        'input_date' => safeImportNow(),
                        'last_update' => safeImportNow(),
                        'uid' => $uid
                    ];

                    $authors = safeImportNormalizeField($field[15]);
                    $topics = safeImportNormalizeField($field[16]);
                    $items = safeImportNormalizeField($field[17]);

                    $biblioStatements['biblio']->execute($payload);
                    $biblioId = (int)DB::getInstance()->lastInsertId();
                    safeImportRecordEntry($sessionId, 'biblio', 'insert', $biblioId, (string)$biblioId, ['biblio_id' => $biblioId, 'title' => $payload['title']]);

                    if (!empty($authors)) {
                        foreach (explode('><', $authors) as $author) {
                            $author = trim(str_replace(['<', '>'], '', $author));
                            if (empty($author)) continue;
                            $authorId = utility::getID($dbs, 'mst_author', 'author_id', 'author_name', $author, $biblioCaches['author']);
                            $biblioStatements['author']->execute(['biblio_id' => $biblioId, 'author_id' => $authorId, 'level' => 2]);
                        }
                    }

                    if (!empty($topics)) {
                        foreach (explode('><', $topics) as $topic) {
                            $topic = trim(str_replace(['<', '>'], '', $topic));
                            if (empty($topic)) continue;
                            $topicId = utility::getID($dbs, 'mst_topic', 'topic_id', 'topic', $topic, $biblioCaches['topic']);
                            $biblioStatements['topic']->execute(['biblio_id' => $biblioId, 'topic_id' => $topicId, 'level' => 2]);
                        }
                    }

                    if (!empty($items)) {
                        foreach (explode('><', $items) as $itemCode) {
                            $itemCode = trim(str_replace(['<', '>'], '', $itemCode));
                            if (empty($itemCode)) continue;
                            $biblioStatements['item']->execute([
                                'biblio_id' => $biblioId,
                                'item_code' => $itemCode,
                                'input_date' => safeImportNow(),
                                'last_update' => safeImportNow(),
                                'uid' => $uid
                            ]);
                        }
                    }

                    if ($indexer) {
                        $indexer->makeIndex($biblioId);
                    }
                }

                $success++;
            } catch (Throwable $error) {
                $skipped++;
                $errors[] = __('Row').' '.$rowNumber.': '.$error->getMessage();
                if ($settings['stop_on_error']) {
                    throw new RuntimeException(end($errors));
                }
            }

            safeImportUpdateSession($sessionId, [
                'processed_rows' => $processed,
                'success_rows' => $success,
                'skipped_rows' => $skipped,
                'updated_at' => safeImportNow()
            ]);

            importProgress((int)round(($rowNumber / $totalLine) * 100));
        }
    } finally {
        fclose($file);
    }

    $status = $skipped > 0 ? 'completed_with_errors' : 'completed';
    $errorMessage = implode("\n", array_slice($errors, 0, 20));

    safeImportUpdateSession($sessionId, [
        'status' => $status,
        'processed_rows' => $processed,
        'success_rows' => $success,
        'skipped_rows' => $skipped,
        'error_message' => $errorMessage ?: null,
        'updated_at' => safeImportNow(),
        'finished_at' => safeImportNow()
    ]);

    if (!empty($settings['auto_delete_temp'])) {
        safeImportDeleteTempFile($session['temp_file']);
        safeImportUpdateSession($sessionId, ['temp_file' => null, 'updated_at' => safeImportNow()]);
    }

    return [
        'status' => $status,
        'processed_rows' => $processed,
        'success_rows' => $success,
        'skipped_rows' => $skipped,
        'error_message' => $errorMessage
    ];
}

function safeImportRollback(int $sessionId, mysqli $dbs, $indexer = null): array
{
    require_once SIMBIO.'simbio_DB/simbio_dbop.inc.php';
    $sqlOp = new simbio_dbop($dbs);
    $session = safeImportGetSession($sessionId);
    if (!$session) throw new RuntimeException(__('Rollback session not found'));
    if ($session['status'] === 'rolled_back') throw new RuntimeException(__('This import session has already been rolled back'));

    $entries = safeImportEntries($sessionId);
    $rollbackCount = 0;

    $restoreStatements = safeImportItemStatements();

    foreach ($entries as $entry) {
        $snapshot = safeImportUnserialize($entry['snapshot'], []);

        if ($entry['entity_type'] === 'item') {
            if ($entry['action_type'] === 'insert' && !empty($entry['entity_id'])) {
                $restoreStatements['delete_item']->execute(['item_id' => (int)$entry['entity_id']]);
                $rollbackCount += $restoreStatements['delete_item']->rowCount();
            }

            if ($entry['action_type'] === 'update' && !empty($snapshot['item_id'])) {
                $restoreStatements['restore_item']->execute([
                    'biblio_id' => $snapshot['biblio_id'],
                    'item_code' => $snapshot['item_code'],
                    'call_number' => $snapshot['call_number'],
                    'coll_type_id' => $snapshot['coll_type_id'],
                    'inventory_code' => $snapshot['inventory_code'],
                    'received_date' => $snapshot['received_date'],
                    'supplier_id' => $snapshot['supplier_id'],
                    'order_no' => $snapshot['order_no'],
                    'location_id' => $snapshot['location_id'],
                    'order_date' => $snapshot['order_date'],
                    'item_status_id' => $snapshot['item_status_id'],
                    'site' => $snapshot['site'],
                    'source' => $snapshot['source'],
                    'invoice' => $snapshot['invoice'],
                    'price' => $snapshot['price'],
                    'price_currency' => $snapshot['price_currency'],
                    'invoice_date' => $snapshot['invoice_date'],
                    'input_date' => $snapshot['input_date'],
                    'last_update' => $snapshot['last_update'],
                    'uid' => $snapshot['uid'],
                    'item_id' => $snapshot['item_id']
                ]);
                $rollbackCount += $restoreStatements['restore_item']->rowCount();
            }

            continue;
        }

        if ($entry['entity_type'] === 'biblio' && $entry['action_type'] === 'insert' && !empty($entry['entity_id'])) {
            $biblioId = (int)$entry['entity_id'];
            $sqlOp->delete('item', 'biblio_id='.$biblioId);
            $sqlOp->delete('biblio_topic', 'biblio_id='.$biblioId);
            $sqlOp->delete('biblio_author', 'biblio_id='.$biblioId);
            $sqlOp->delete('biblio_attachment', 'biblio_id='.$biblioId);
            $sqlOp->delete('biblio_relation', 'biblio_id='.$biblioId);

            $serialQuery = $dbs->query('SELECT serial_id FROM serial WHERE biblio_id='.$biblioId);
            while ($serialQuery && ($serial = $serialQuery->fetch_assoc())) {
                $sqlOp->delete('kardex', 'serial_id='.(int)$serial['serial_id']);
            }
            $sqlOp->delete('serial', 'biblio_id='.$biblioId);
            $sqlOp->delete('biblio', 'biblio_id='.$biblioId);
            if ($indexer) $indexer->deleteIndex($biblioId);
            $rollbackCount++;
        }
    }

    safeImportUpdateSession($sessionId, [
        'status' => 'rolled_back',
        'rollback_rows' => $rollbackCount,
        'rolled_back_at' => safeImportNow(),
        'updated_at' => safeImportNow()
    ]);

    if (!empty($session['temp_file'])) safeImportDeleteTempFile($session['temp_file']);

    return ['rollback_rows' => $rollbackCount];
}

function safeImportStatusLabel(string $status): string
{
    return match ($status) {
        'prepared' => __('Prepared'),
        'running' => __('Running'),
        'completed' => __('Completed'),
        'completed_with_errors' => __('Completed with errors'),
        'failed' => __('Failed'),
        'canceled' => __('Canceled'),
        'rolled_back' => __('Rolled back'),
        default => ucfirst(str_replace('_', ' ', $status))
    };
}

function safeImportStatusClass(string $status): string
{
    return match ($status) {
        'completed' => 'success',
        'completed_with_errors' => 'warning',
        'failed' => 'danger',
        'rolled_back' => 'secondary',
        'running' => 'info',
        default => 'light'
    };
}
