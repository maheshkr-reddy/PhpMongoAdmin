<?php
/**
 * Thin wrapper over the low-level ext-mongodb driver so the rest of the app
 * reads cleanly. No mongodb/mongodb Composer library required.
 */

use MongoDB\Driver\Manager;
use MongoDB\Driver\Command;
use MongoDB\Driver\Query;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Exception\Exception as MongoException;

class Mongo
{
    private $manager;

    public function __construct(string $uri, array $uriOptions = [], array $driverOptions = [])
    {
        // Decode arrays/objects to PHP arrays/stdClass so we can json_encode freely.
        $this->manager = new Manager($uri, $uriOptions, $driverOptions);
    }

    /** Force a server round-trip so invalid credentials surface immediately. */
    public function ping(): void
    {
        $this->manager->executeCommand('admin', new Command(['ping' => 1]));
    }

    /* ---------------------------------------------------------------- server */

    /**
     * List databases the connection may see.
     *  - A user with the cluster `listDatabases` privilege (or no auth) gets all.
     *  - A limited user gets only the databases they hold privileges on
     *    (via `authorizedDatabases: true`), instead of an authorization error.
     */
    public function listDatabases(): array
    {
        try {
            $res = $this->manager->executeCommand('admin', new Command(['listDatabases' => 1]))->toArray()[0] ?? null;
        } catch (MongoException $e) {
            // Not authorized to enumerate every database → ask for the authorized subset.
            $res = $this->manager->executeCommand('admin', new Command(['listDatabases' => 1, 'authorizedDatabases' => true]))->toArray()[0] ?? null;
        }
        $out = [];
        foreach (($res->databases ?? []) as $db) {
            $out[] = [
                'name'       => $db->name,
                'sizeOnDisk' => $db->sizeOnDisk ?? 0,
                'empty'      => $db->empty ?? false,
            ];
        }
        return $out;
    }

    public function serverInfo(): array
    {
        $info = [];
        try {
            $build = $this->manager->executeCommand('admin', new Command(['buildInfo' => 1]))->toArray()[0] ?? null;
            $info['version']     = $build->version ?? '?';
            $info['gitVersion']  = $build->gitVersion ?? '';
            $info['maxBson']     = $build->maxBsonObjectSize ?? null;
        } catch (MongoException $e) { /* ignore */ }
        try {
            $status = $this->manager->executeCommand('admin', new Command(['serverStatus' => 1]))->toArray()[0] ?? null;
            $info['host']        = $status->host ?? '';
            $info['uptime']      = $status->uptime ?? null;
            $info['connections'] = isset($status->connections->current) ? $status->connections->current : null;
            $info['process']     = $status->process ?? 'mongod';
        } catch (MongoException $e) { /* ignore – often needs privileges */ }
        return $info;
    }

    /* ----------------------------------------------------------- collections */

    public function listCollections(string $db): array
    {
        $res = $this->manager->executeCommand($db, new Command(['listCollections' => 1, 'nameOnly' => false]));
        $out = [];
        foreach ($res as $c) {
            $out[] = [
                'name' => $c->name,
                'type' => $c->type ?? 'collection',
            ];
        }
        usort($out, function($a, $b) { return strcmp($a['name'], $b['name']); });
        return $out;
    }

    public function collectionStats(string $db, string $coll): array
    {
        try {
            $res = $this->manager->executeCommand($db, new Command(['collStats' => $coll]))->toArray()[0] ?? null;
            return [
                'count'        => $res->count ?? 0,
                'size'         => $res->size ?? 0,
                'storageSize'  => $res->storageSize ?? 0,
                'avgObjSize'   => $res->avgObjSize ?? 0,
                'nindexes'     => $res->nindexes ?? 0,
                'totalIndexSize' => $res->totalIndexSize ?? 0,
            ];
        } catch (MongoException $e) {
            return ['count' => $this->count($db, $coll)];
        }
    }

    public function count(string $db, string $coll, $filter = []): int
    {
        $cmd = new Command(['count' => $coll, 'query' => self::toObject($filter)]);
        try {
            $res = $this->manager->executeCommand($db, $cmd)->toArray()[0] ?? null;
            return (int) ($res->n ?? 0);
        } catch (MongoException $e) {
            return 0;
        }
    }

    public function listIndexes(string $db, string $coll): array
    {
        try {
            $res = $this->manager->executeCommand($db, new Command(['listIndexes' => $coll]));
            $out = [];
            foreach ($res as $ix) {
                $out[] = [
                    'name' => $ix->name,
                    'key'  => json_encode($ix->key, JSON_UNESCAPED_SLASHES),
                    'unique' => $ix->unique ?? false,
                ];
            }
            return $out;
        } catch (MongoException $e) {
            return [];
        }
    }

    /* --------------------------------------------------------------- queries */

    /**
     * Run a find() and return decoded documents plus their canonical
     * Extended-JSON string (so ObjectId etc. round-trip safely).
     *
     * @return array{0: array<int,array{json:string, raw:object}>, 1:int}
     */
    public function find(string $db, string $coll, $filter = [], array $opts = []): array
    {
        $query = new Query(self::toObject($filter), $opts);
        $cursor = $this->manager->executeQuery("$db.$coll", $query);
        $cursor->setTypeMap(['root' => 'object', 'document' => 'object', 'array' => 'array']);

        $docs = [];
        foreach ($cursor as $doc) {
            // Every BSON type implements JsonSerializable, so json_encode()
            // produces canonical Extended JSON without needing the optional
            // procedural MongoDB\BSON\* functions (missing in some builds).
            $docs[] = [
                'json' => json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id'   => isset($doc->_id) ? self::idToString($doc->_id) : null,
                'raw'  => $doc,
            ];
        }
        return $docs;
    }

    public function insert(string $db, string $coll, string $json): void
    {
        $doc  = self::jsonToPhp($json);
        $bulk = new BulkWrite();
        $bulk->insert($doc);
        $this->manager->executeBulkWrite("$db.$coll", $bulk);
    }

    public function replaceById(string $db, string $coll, $id, string $json): void
    {
        $doc  = self::jsonToPhp($json);
        $bulk = new BulkWrite();
        $bulk->update(['_id' => self::resolveId($id)], $doc, ['multi' => false, 'upsert' => false]);
        $this->manager->executeBulkWrite("$db.$coll", $bulk);
    }

    public function deleteById(string $db, string $coll, $id): void
    {
        $bulk = new BulkWrite();
        $bulk->delete(['_id' => self::resolveId($id)], ['limit' => 1]);
        $this->manager->executeBulkWrite("$db.$coll", $bulk);
    }

    /* ----------------------------------------------------- import / export */

    /** Stream every matching document to a callback (stdClass per doc). */
    public function each(string $db, string $coll, callable $cb, $filter = [], array $opts = []): void
    {
        $query  = new Query(self::toObject($filter), $opts);
        $cursor = $this->manager->executeQuery("$db.$coll", $query);
        $cursor->setTypeMap(['root' => 'object', 'document' => 'object', 'array' => 'array']);
        foreach ($cursor as $doc) {
            $cb($doc);
        }
    }

    /** Union of top-level field names across a collection (first-seen order). */
    public function topLevelKeys(string $db, string $coll): array
    {
        $keys = [];
        $this->each($db, $coll, function ($doc) use (&$keys) {
            foreach ((array) $doc as $k => $_) { $keys[$k] = true; }
        });
        return array_keys($keys);
    }

    /** Insert many documents in batches. Returns the number inserted. */
    public function insertMany(string $db, string $coll, iterable $docs, int $batch = 1000): int
    {
        $count = 0; $n = 0;
        $bulk = new BulkWrite();
        foreach ($docs as $doc) {
            $bulk->insert($doc);
            if (++$n >= $batch) {
                $this->manager->executeBulkWrite("$db.$coll", $bulk);
                $count += $n; $n = 0; $bulk = new BulkWrite();
            }
        }
        if ($n > 0) {
            $this->manager->executeBulkWrite("$db.$coll", $bulk);
            $count += $n;
        }
        return $count;
    }

    /** $set a single field on one document; returns the resolved value. */
    public function setField(string $db, string $coll, string $idJson, string $field, string $valueText)
    {
        if ($field === '_id') {
            throw new \RuntimeException('The _id field cannot be edited.');
        }
        $value = self::parseScalarOrJson($valueText);
        $bulk  = new BulkWrite();
        $bulk->update(['_id' => self::resolveId($idJson)], ['$set' => [$field => $value]],
                      ['multi' => false, 'upsert' => false]);
        $this->manager->executeBulkWrite("$db.$coll", $bulk);
        return $value;
    }

    /** Parse an inline-edit value: Extended JSON if valid, otherwise a plain string. */
    public static function parseScalarOrJson(string $text)
    {
        $t = trim($text);
        if ($t === '') return '';
        try {
            return self::jsonToPhp($t);
        } catch (\Throwable $e) {
            return $text;                                    // not JSON -> keep as string
        }
    }

    /* ----------------------------------------------------------------- admin */

    public function createCollection(string $db, string $coll, array $options = []): void
    {
        $cmd = ['create' => $coll];
        if (!empty($options['capped'])) {
            $size = (int) ($options['size'] ?? 0);
            if ($size <= 0) throw new \RuntimeException('A capped collection needs a maximum size (bytes).');
            $cmd['capped'] = true;
            $cmd['size']   = $size;
            if (!empty($options['max'])) $cmd['max'] = (int) $options['max'];
        }
        $this->manager->executeCommand($db, new Command($cmd));
    }

    public function dropCollection(string $db, string $coll): void
    {
        $this->manager->executeCommand($db, new Command(['drop' => $coll]));
    }

    public function dropDatabase(string $db): void
    {
        $this->manager->executeCommand($db, new Command(['dropDatabase' => 1]));
    }

    /** Mongo creates a DB lazily, so we make it real by adding one collection. */
    public function createDatabase(string $db, string $firstCollection = 'data'): void
    {
        $this->manager->executeCommand($db, new Command(['create' => $firstCollection]));
    }

    /** Copy every (non-view) collection of one database into another. Returns collections copied. */
    public function copyDatabase(string $src, string $dst, bool $copyIndexes = true): int
    {
        if ($src === $dst) throw new \RuntimeException('Source and destination databases are the same.');
        $n = 0;
        foreach ($this->listCollections($src) as $c) {
            if (($c['type'] ?? 'collection') === 'view') continue;     // views aren't copyable as data
            $this->copyCollection($src, $c['name'], $dst, $c['name'], $copyIndexes);
            $n++;
        }
        return $n;
    }

    /** Rename a database = copy all collections to the new name, then drop the source. */
    public function renameDatabase(string $src, string $dst): int
    {
        $n = $this->copyDatabase($src, $dst, true);
        $this->dropDatabase($src);
        return $n;
    }

    /** List users defined on a database with their roles (needs privileges). */
    public function listUsers(string $db): array
    {
        $out = [];
        $res = $this->manager->executeCommand($db, new Command(['usersInfo' => 1]));
        $res->setTypeMap(['root' => 'object', 'document' => 'object', 'array' => 'array']);
        $doc = current(iterator_to_array($res)) ?: null;
        foreach (($doc->users ?? []) as $u) {
            $roles = [];
            foreach (($u->roles ?? []) as $r) {
                $roles[] = ($r->role ?? '?') . '@' . ($r->db ?? '?');
            }
            $out[] = [
                'user'  => $u->user ?? '',
                'db'    => $u->db ?? $db,
                'roles' => $roles,
            ];
        }
        return $out;
    }

    /** Run a raw command document (Extended-JSON parsed) and return result docs. */
    public function command(string $db, $commandDoc, int $cap = 200): array
    {
        $cursor = $this->manager->executeCommand($db, new Command($commandDoc));
        $cursor->setTypeMap(['root' => 'object', 'document' => 'object', 'array' => 'array']);
        $rows = [];
        foreach ($cursor as $doc) {
            $rows[] = [
                'json' => json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'raw'  => $doc,
            ];
            if (count($rows) >= $cap) break;
        }
        return $rows;
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * Parse Extended JSON (relaxed or canonical) into PHP/BSON values for the
     * driver. Uses only the BSON type classes — no procedural BSON functions,
     * so it works on every ext-mongodb build.
     */
    public static function jsonToPhp(string $json)
    {
        $json = trim($json);
        if ($json === '') {
            return new stdClass();
        }
        $decoded = json_decode($json);                 // objects as stdClass, arrays as arrays
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }
        return self::extJsonToBson($decoded);
    }

    /** Recursively turn decoded JSON into BSON-typed PHP values. */
    private static function extJsonToBson($value)
    {
        if (is_array($value)) {                        // JSON array -> BSON array
            return array_map([self::class, 'extJsonToBson'], $value);
        }
        if (!is_object($value)) {                      // scalar / null
            return $value;
        }

        $keys = array_keys((array) $value);
        $has  = function(string $k) use ($keys) { return in_array($k, $keys, true); };

        // --- Extended JSON wrappers -------------------------------------
        if ($keys === ['$oid'] && is_string($value->{'$oid'})) {
            return new \MongoDB\BSON\ObjectId($value->{'$oid'});
        }
        if ($keys === ['$date']) {
            $d = $value->{'$date'};
            if (is_object($d) && isset($d->{'$numberLong'})) {
                return new \MongoDB\BSON\UTCDateTime((int) $d->{'$numberLong'});
            }
            if (is_int($d) || is_float($d)) {
                return new \MongoDB\BSON\UTCDateTime((int) $d);
            }
            if (is_string($d)) {                       // ISO-8601 string
                $ts = strtotime($d);
                return new \MongoDB\BSON\UTCDateTime((($ts !== false ? $ts : time())) * 1000);
            }
        }
        if ($keys === ['$numberLong']) return (int) $value->{'$numberLong'};
        if ($keys === ['$numberInt'])  return (int) $value->{'$numberInt'};
        if ($keys === ['$numberDouble']) return (float) $value->{'$numberDouble'};
        if ($keys === ['$numberDecimal']) {
            return new \MongoDB\BSON\Decimal128((string) $value->{'$numberDecimal'});
        }
        if ($keys === ['$regularExpression'] && isset($value->{'$regularExpression'}->pattern)) {
            $r = $value->{'$regularExpression'};
            return new \MongoDB\BSON\Regex($r->pattern, $r->options ?? '');
        }
        if ($has('$regex')) {                          // legacy regex form
            return new \MongoDB\BSON\Regex((string) $value->{'$regex'}, (string) ($value->{'$options'} ?? ''));
        }
        if ($keys === ['$binary'] && isset($value->{'$binary'}->base64)) {
            $b = $value->{'$binary'};
            return new \MongoDB\BSON\Binary(base64_decode($b->base64), (int) hexdec($b->subType ?? '00'));
        }
        if ($has('$binary') && isset($value->{'$type'})) {   // legacy binary form
            return new \MongoDB\BSON\Binary(base64_decode((string) $value->{'$binary'}), (int) hexdec((string) $value->{'$type'}));
        }
        if ($keys === ['$timestamp'] && isset($value->{'$timestamp'}->t)) {
            $t = $value->{'$timestamp'};
            return new \MongoDB\BSON\Timestamp((int) $t->i, (int) $t->t);
        }
        if ($keys === ['$minKey'])    return new \MongoDB\BSON\MinKey();
        if ($keys === ['$maxKey'])    return new \MongoDB\BSON\MaxKey();
        if ($keys === ['$undefined']) return null;

        // --- plain sub-document: recurse into each property -------------
        $out = new stdClass();
        foreach ($value as $k => $v) {
            $out->$k = self::extJsonToBson($v);
        }
        return $out;
    }

    /** Accept array or Extended-JSON string filter and normalise to object. */
    private static function toObject($filter): object
    {
        if (is_string($filter)) {
            $filter = self::jsonToPhp($filter);
        }
        if (is_array($filter)) {
            $filter = (object) $filter;
        }
        return $filter ?: new stdClass();
    }

    /**
     * Encode an _id (of any BSON type) as Extended JSON for use in a URL,
     * so its type survives the round trip:
     *   ObjectId -> {"$oid":"..."}   int 2 -> 2   string "2" -> "2"
     */
    private static function idToString($id): string
    {
        return (string) json_encode($id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Turn the _id we got from the URL back into the right BSON/PHP type. */
    private static function resolveId(string $id)
    {
        $decoded = json_decode($id);
        if (json_last_error() === JSON_ERROR_NONE) {
            return self::extJsonToBson($decoded);          // typed: int, string, ObjectId, ...
        }
        // Fallback for bare / hand-typed ids (older links):
        if (preg_match('/^[0-9a-fA-F]{24}$/', $id)) {
            return new \MongoDB\BSON\ObjectId($id);
        }
        return $id;
    }

    /** Fetch a single document by its _id (encoded by idToString). */
    public function findById(string $db, string $coll, string $idJson): ?array
    {
        $docs = $this->find($db, $coll, ['_id' => self::resolveId($idJson)], ['limit' => 1]);
        return $docs[0] ?? null;
    }

    /* --------------------------------------------------- operations / bulk */

    /** Rename a collection within the same database (metadata-only, fast). */
    public function renameCollection(string $db, string $from, string $to): void
    {
        if ($to === '' || $from === '') throw new \RuntimeException('Collection name is required.');
        if ($to === $from) return;
        $this->manager->executeCommand('admin', new Command([
            'renameCollection' => "$db.$from",
            'to'               => "$db.$to",
            'dropTarget'       => false,
        ]));
    }

    /** Copy all documents (and, best-effort, indexes) into another namespace. */
    public function copyCollection(string $sDb, string $sColl, string $dDb, string $dColl, bool $copyIndexes = true): int
    {
        if ($sDb === $dDb && $sColl === $dColl) {
            throw new \RuntimeException('Source and destination are the same collection.');
        }
        $buf = []; $count = 0;
        $flush = function () use (&$buf, &$count, $dDb, $dColl) {
            if ($buf) { $count += $this->insertMany($dDb, $dColl, $buf); $buf = []; }
        };
        $this->each($sDb, $sColl, function ($doc) use (&$buf, $flush) {
            $buf[] = $doc;
            if (count($buf) >= 1000) $flush();
        });
        $flush();
        if ($copyIndexes) { try { $this->copyIndexes($sDb, $sColl, $dDb, $dColl); } catch (\Throwable $e) { /* best effort */ } }
        return $count;
    }

    private function copyIndexes(string $sDb, string $sColl, string $dDb, string $dColl): void
    {
        $res = $this->manager->executeCommand($sDb, new Command(['listIndexes' => $sColl]));
        $specs = [];
        foreach ($res as $ix) {
            if (($ix->name ?? '') === '_id_') continue;       // auto-created on the target
            $spec = ['key' => $ix->key, 'name' => $ix->name];
            foreach (['unique', 'sparse', 'expireAfterSeconds', 'partialFilterExpression'] as $opt) {
                if (isset($ix->$opt)) $spec[$opt] = $ix->$opt;
            }
            $specs[] = $spec;
        }
        if ($specs) {
            $this->manager->executeCommand($dDb, new Command(['createIndexes' => $dColl, 'indexes' => $specs]));
        }
    }

    /** Move a collection. Same db → rename; cross db → copy + drop source. Returns docs moved. */
    public function moveCollection(string $sDb, string $sColl, string $dDb, string $dColl): int
    {
        if ($sDb === $dDb) { $this->renameCollection($sDb, $sColl, $dColl); return 0; }
        $n = $this->copyCollection($sDb, $sColl, $dDb, $dColl, true);
        $this->dropCollection($sDb, $sColl);
        return $n;
    }

    protected function updateMany(string $db, string $coll, array $filter, array $update): int
    {
        $bulk = new BulkWrite();
        $bulk->update(self::toObject($filter), $update, ['multi' => true, 'upsert' => false]);
        $res = $this->manager->executeBulkWrite("$db.$coll", $bulk);
        return (int) $res->getModifiedCount();
    }

    /** Add a field to documents (by default only where it is missing). Returns modified count. */
    public function addField(string $db, string $coll, string $field, string $valueText, bool $onlyMissing = true): int
    {
        if (trim($field) === '') throw new \RuntimeException('Field name is required.');
        $value  = self::parseScalarOrJson($valueText);
        $filter = $onlyMissing ? [$field => ['$exists' => false]] : [];
        return $this->updateMany($db, $coll, $filter, ['$set' => [$field => $value]]);
    }

    /** Remove a field from every document. Returns modified count. */
    public function removeField(string $db, string $coll, string $field): int
    {
        if ($field === '_id')      throw new \RuntimeException('The _id field cannot be removed.');
        if (trim($field) === '')   throw new \RuntimeException('Field name is required.');
        return $this->updateMany($db, $coll, [], ['$unset' => [$field => '']]);
    }

    private static function quoteRegex(string $s): string
    {
        return preg_replace('/[.\\\\+*?\[^\]$(){}=!<>|:#\/-]/', '\\\\$0', $s);
    }

    /**
     * Find & replace within a single field. Whole-value mode replaces the field
     * where it equals $find; substring mode rewrites occurrences inside string
     * values via $replaceAll (MongoDB 4.4+). Returns modified count.
     */
    public function findReplace(string $db, string $coll, string $field, string $find, string $replace, bool $whole = false): int
    {
        if (trim($field) === '' || $field === '_id') throw new \RuntimeException('Choose a field other than _id.');
        if ($whole) {
            return $this->updateMany($db, $coll, [$field => $find], ['$set' => [$field => self::parseScalarOrJson($replace)]]);
        }
        if ($find === '') throw new \RuntimeException('Enter the text to find.');
        $filter   = [$field => ['$type' => 'string', '$regex' => self::quoteRegex($find)]];
        $pipeline = [['$set' => [$field => ['$replaceAll' => ['input' => '$' . $field, 'find' => $find, 'replacement' => $replace]]]]];
        return $this->updateMany($db, $coll, $filter, $pipeline);
    }

    /** Build an {_id:{$in:[…]}} filter from typed _id JSON strings. */
    public function idInFilter(array $idJsons): array
    {
        $ids = [];
        foreach ($idJsons as $j) {
            if ($j === '' || $j === null) continue;
            $ids[] = self::resolveId((string) $j);
        }
        return ['_id' => ['$in' => $ids]];
    }

    /** Delete documents by a list of typed _id JSON strings. Returns deleted count. */
    public function deleteByIds(string $db, string $coll, array $idJsons): int
    {
        $bulk = new BulkWrite(); $n = 0;
        foreach ($idJsons as $j) {
            if ($j === '' || $j === null) continue;
            $bulk->delete(['_id' => self::resolveId((string) $j)], ['limit' => 1]);
            $n++;
        }
        if ($n === 0) return 0;
        $res = $this->manager->executeBulkWrite("$db.$coll", $bulk);
        return (int) $res->getDeletedCount();
    }

    /** Copy specific documents (by id) into another namespace. Returns copied count. */
    public function copyDocuments(string $sDb, string $sColl, string $dDb, string $dColl, array $idJsons): int
    {
        $docs = $this->find($sDb, $sColl, $this->idInFilter($idJsons));
        $raw  = array_map(function($d) { return $d['raw']; }, $docs);
        return $raw ? $this->insertMany($dDb, $dColl, $raw) : 0;
    }

    /** Fetch raw documents (+ json) for a list of ids (used by multi-edit). */
    public function findByIds(string $db, string $coll, array $idJsons): array
    {
        return $idJsons ? $this->find($db, $coll, $this->idInFilter($idJsons)) : [];
    }

    /** Remove every document from a collection (keeps the collection and its indexes). */
    public function emptyCollection(string $db, string $coll): int
    {
        $bulk = new BulkWrite();
        $bulk->delete([], ['limit' => 0]);
        $res = $this->manager->executeBulkWrite("$db.$coll", $bulk);
        return (int) $res->getDeletedCount();
    }

    /** Insert one document built from parallel field/value arrays (values typed via parseScalarOrJson). */
    public function insertFields(string $db, string $coll, array $keys, array $vals): void
    {
        $doc = [];
        foreach ($keys as $i => $k) {
            $k = trim((string) $k);
            $v = (string) ($vals[$i] ?? '');
            if ($k === '' || trim($v) === '') continue;       // skip blank rows
            $doc[$k] = self::parseScalarOrJson($v);
        }
        if (!$doc) throw new \RuntimeException('Add at least one field with a name and value.');
        $bulk = new BulkWrite();
        $bulk->insert(self::toObject($doc));
        $this->manager->executeBulkWrite("$db.$coll", $bulk);
    }
}
