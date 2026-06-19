<?php
/**
 * Minimal, dependency-free BSON codec.
 *
 * Reads/writes the BSON wire format using only the MongoDB\BSON\* *classes*
 * (ObjectId, UTCDateTime, ...) — never the optional procedural functions
 * (fromPHP/toPHP), which are missing from some ext-mongodb builds.
 *
 * Supported types cover everything mongodump normally emits. Decimal128 and a
 * few deprecated types (DBPointer, CodeWScope) are intentionally unsupported on
 * the *binary* path — use JSON/NDLJSON for those (Extended JSON handles them).
 */
final class Bson
{
    /* ============================================================ DECODE === */

    /** Decode every document in a concatenated BSON stream (a .bson dump file). */
    public static function decodeAll(string $bin): array
    {
        $docs = [];
        $pos  = 0;
        $len  = strlen($bin);
        while ($pos < $len) {
            if ($len - $pos < 5) break;                       // not enough for a doc
            $docs[] = self::decodeDocument($bin, $pos);
        }
        return $docs;
    }

    /** Decode one document starting at &$pos (advanced past it on return). */
    public static function decodeDocument(string $bin, int &$pos)
    {
        return (object) self::readElements($bin, $pos);
    }

    /** Read a BSON document body into an ordered associative array. */
    private static function readElements(string $bin, int &$pos): array
    {
        $size = self::readInt32($bin, $pos);
        $end  = $pos - 4 + $size;                             // index just past the doc
        $out  = [];
        while ($pos < $end - 1) {
            $type = ord($bin[$pos++]);
            $name = self::readCString($bin, $pos);
            $out[$name] = self::decodeValue($type, $bin, $pos);
        }
        $pos = $end;                                          // skip trailing 0x00
        return $out;
    }

    private static function decodeValue(int $type, string $bin, int &$pos)
    {
        switch ($type) {
            case 0x01: $v = unpack('e', substr($bin, $pos, 8))[1]; $pos += 8; return $v;            // double
            case 0x02: return self::readString($bin, $pos);                                          // string
            case 0x03: return (object) self::readElements($bin, $pos);                               // document
            case 0x04: return array_values(self::readElements($bin, $pos));                          // array
            case 0x05:                                                                               // binary
                $len = self::readInt32($bin, $pos);
                $sub = ord($bin[$pos++]);
                if ($sub === 0x02) { $inner = self::readInt32($bin, $pos); $len = $inner; }
                $data = substr($bin, $pos, $len); $pos += $len;
                return new \MongoDB\BSON\Binary($data, $sub);
            case 0x06: return null;                                                                  // undefined (dep)
            case 0x07: $oid = bin2hex(substr($bin, $pos, 12)); $pos += 12; return new \MongoDB\BSON\ObjectId($oid);
            case 0x08: return ord($bin[$pos++]) === 1;                                                // bool
            case 0x09: $ms = self::readInt64($bin, $pos); return new \MongoDB\BSON\UTCDateTime($ms);  // datetime
            case 0x0A: return null;                                                                  // null
            case 0x0B:                                                                               // regex
                $pattern = self::readCString($bin, $pos);
                $flags   = self::readCString($bin, $pos);
                return new \MongoDB\BSON\Regex($pattern, $flags);
            case 0x0D: return self::readString($bin, $pos);                                           // code -> string
            case 0x0E: return self::readString($bin, $pos);                                           // symbol -> string
            case 0x10: return self::readInt32($bin, $pos);                                            // int32
            case 0x11:                                                                               // timestamp
                $inc = self::readInt32($bin, $pos); $ts = self::readInt32($bin, $pos);
                return new \MongoDB\BSON\Timestamp($inc, $ts);
            case 0x12: return self::readInt64($bin, $pos);                                            // int64
            case 0xFF: return new \MongoDB\BSON\MinKey();
            case 0x7F: return new \MongoDB\BSON\MaxKey();
            case 0x13:
                throw new \RuntimeException('BSON Decimal128 (0x13) is not supported on the binary path — export the source as JSON/NDJSON instead.');
            default:
                throw new \RuntimeException(sprintf('Unsupported BSON type 0x%02X — export the source as JSON/NDJSON instead.', $type));
        }
    }

    private static function readInt32(string $bin, int &$pos): int
    {
        $v = unpack('V', substr($bin, $pos, 4))[1]; $pos += 4;
        if ($v >= 0x80000000) $v -= 0x100000000;             // to signed
        return $v;
    }

    private static function readInt64(string $bin, int &$pos): int
    {
        $v = unpack('P', substr($bin, $pos, 8))[1]; $pos += 8; // 'P' = unsigned 64 LE; PHP int is signed 64 -> correct two's complement
        return $v;
    }

    private static function readCString(string $bin, int &$pos): string
    {
        $end = strpos($bin, "\0", $pos);
        $s = substr($bin, $pos, $end - $pos);
        $pos = $end + 1;
        return $s;
    }

    private static function readString(string $bin, int &$pos): string
    {
        $len = self::readInt32($bin, $pos);
        $s = substr($bin, $pos, $len - 1);                   // exclude trailing NUL
        $pos += $len;
        return $s;
    }

    /* ============================================================ ENCODE === */

    /** Encode a document (array/stdClass) to BSON bytes. */
    public static function encodeDocument($doc): string
    {
        $body = '';
        foreach ((array) $doc as $key => $value) {
            $body .= self::encodeElement((string) $key, $value);
        }
        $body .= "\0";
        return pack('V', strlen($body) + 4) . $body;
    }

    private static function encodeElement(string $name, $value): string
    {
        [$type, $payload] = self::encodeValue($value);
        return chr($type) . $name . "\0" . $payload;
    }

    private static function encodeValue($value): array
    {
        if ($value === null)  return [0x0A, ''];
        if (is_bool($value))  return [0x08, chr($value ? 1 : 0)];
        if (is_int($value)) {
            if ($value >= -2147483648 && $value <= 2147483647) {
                return [0x10, pack('V', $value & 0xFFFFFFFF)];
            }
            return [0x12, pack('P', $value)];
        }
        if (is_float($value)) return [0x01, pack('e', $value)];
        if (is_string($value)) {
            return [0x02, pack('V', strlen($value) + 1) . $value . "\0"];
        }
        if (is_array($value)) {
            return ($value === array_values($value))
                ? [0x04, self::encodeDocument(self::listToObjectKeys($value))]
                : [0x03, self::encodeDocument($value)];
        }
        if ($value instanceof \MongoDB\BSON\ObjectId) {
            return [0x07, hex2bin((string) $value)];
        }
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return [0x09, pack('P', (int) (string) $value->__toString())];
        }
        if ($value instanceof \MongoDB\BSON\Binary) {
            $data = $value->getData(); $sub = $value->getType();
            return [0x05, pack('V', strlen($data)) . chr($sub) . $data];
        }
        if ($value instanceof \MongoDB\BSON\Regex) {
            return [0x0B, $value->getPattern() . "\0" . $value->getFlags() . "\0"];
        }
        if ($value instanceof \MongoDB\BSON\Timestamp) {
            return [0x11, pack('V', (int) (string) $value->getIncrement()) . pack('V', (int) (string) $value->getTimestamp())];
        }
        if (class_exists('\MongoDB\BSON\Int64', false) && $value instanceof \MongoDB\BSON\Int64) {
            return [0x12, pack('P', (int) (string) $value)];
        }
        if ($value instanceof \MongoDB\BSON\MinKey) return [0xFF, ''];
        if ($value instanceof \MongoDB\BSON\MaxKey) return [0x7F, ''];
        if ($value instanceof \MongoDB\BSON\Decimal128) {
            throw new \RuntimeException('Decimal128 cannot be written on the BSON path — use JSON/NDJSON export.');
        }
        if (is_object($value)) {                              // generic object -> document
            return [0x03, self::encodeDocument((array) $value)];
        }
        throw new \RuntimeException('Cannot encode value of type ' . gettype($value));
    }

    private static function listToObjectKeys(array $list): array
    {
        $out = [];
        foreach ($list as $i => $v) $out[(string) $i] = $v;
        return $out;
    }
}
