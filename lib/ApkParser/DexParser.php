<?php

namespace ApkParser;

use ApkParser\Exceptions\DexParserException;

/**
 * This file is part of the Apk Parser package.
 *
 * (c) Evozi <email@evozi.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Pure PHP parser for the dex (Dalvik Executable) format, used to list the
 * classes defined in a dex file without shelling out to any external tool.
 *
 * Supported formats:
 *  - every published dex version: 035 (Android 1.0+), 036 (unused but
 *    accepted), 037 (Android 7.0), 038 (Android 8.0), 039 (Android 9.0),
 *    040 (Android 10) and 041 (Android 15+ dex containers holding several
 *    dex sections in one file)
 *  - byte-swapped (big-endian) dex files, legal per the spec's
 *    REVERSE_ENDIAN_CONSTANT
 *  - compact dex ("cdex001"), the ART-internal variant found in files pulled
 *    from a device; its header and the tables needed here match standard dex
 *  - odex ("dey\n"), the legacy optimized-dex wrapper with an embedded dex
 *
 * Files produced by packers/protectors are handled leniently: an unknown
 * version in the magic or a wiped endian tag doesn't abort parsing, counts
 * and offsets are clamped to the actual file bounds, individually corrupt
 * table entries are skipped, and when the data doesn't start with a dex
 * header at all the file is scanned for an embedded dex header (packer stubs
 * commonly prepend loader data to the real dex).
 *
 * The dex data is spooled to a php://temp stream and read in small windows,
 * so memory usage stays bounded regardless of the dex file size.
 *
 * Format reference: https://source.android.com/docs/core/runtime/dex-format
 */
class DexParser
{
    const FORMAT_DEX = 'dex';
    const FORMAT_CDEX = 'cdex';
    const FORMAT_ODEX = 'odex';

    const DEX_MAGIC = "dex\n";
    const CDEX_MAGIC = "cdex";
    const ODEX_MAGIC = "dey\n";

    const ENDIAN_CONSTANT = 0x12345678;
    const REVERSE_ENDIAN_CONSTANT = 0x78563412;
    const HEADER_SIZE = 0x70;

    // Dex containers (version 041, Android 15+) extend the header
    // with container_size and header_offset.
    const HEADER_SIZE_V41 = 0x78;
    const CONTAINER_VERSION = 41;

    // odex opt header: magic, dex_off, dex_len, deps_off, deps_len,
    // opt_off, opt_len, flags, checksum
    const ODEX_HEADER_SIZE = 40;
    const OFF_ODEX_DEX_OFF = 8;

    // header field offsets, relative to the start of the header
    const OFF_FILE_SIZE = 0x20;
    const OFF_HEADER_SIZE = 0x24;
    const OFF_ENDIAN_TAG = 0x28;
    const OFF_STRING_IDS_SIZE = 0x38;
    const OFF_STRING_IDS_OFF = 0x3C;
    const OFF_TYPE_IDS_SIZE = 0x40;
    const OFF_TYPE_IDS_OFF = 0x44;
    const OFF_CLASS_DEFS_SIZE = 0x60;
    const OFF_CLASS_DEFS_OFF = 0x64;
    const OFF_CONTAINER_SIZE = 0x70; // v41 only
    const OFF_CONTAINER_HEADER_OFF = 0x74; // v41 only

    // each class_def_item is 8 uint32 fields
    const CLASS_DEF_ITEM_SIZE = 32;

    // the spec caps type_ids at 65535 entries, and every class_def references
    // a distinct type_id, so both tables can never exceed this
    const MAX_TYPE_IDS = 65536;

    // read window over the backing stream
    const BUFFER_SIZE = 65536;

    // a class descriptor longer than this only occurs in corrupt files
    const MAX_STRING_LENGTH = 65536;

    /**
     * Seekable stream holding the dex file contents.
     * @var resource
     */
    private $stream;

    /**
     * Total size of the spooled data (which may include packer stub data
     * or an odex wrapper around the dex itself).
     * @var int
     */
    private $size;

    /**
     * Absolute offset of the dex header within the data: 0 for a plain dex,
     * the dex_off of an odex wrapper, or wherever the embedded dex header was
     * found inside packed data. All offsets stored in dex headers are relative
     * to this base.
     * @var int
     */
    private $base = 0;

    /**
     * One of the FORMAT_* constants.
     * @var string
     */
    private $format;

    /**
     * Dex format version parsed from the magic, e.g. 35 for "035", 1 for
     * compact dex "cdex001", or 0 when a packer replaced the version digits
     * with garbage.
     * @var int
     */
    private $version;

    /**
     * True for byte-swapped (REVERSE_ENDIAN_CONSTANT) dex files. Only the
     * fixed-width values are swapped in such files; uleb128 and string data
     * are byte streams and stay as-is.
     * @var bool
     */
    private $bigEndian = false;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @var int
     */
    private $bufferOffset = -1;

    /**
     * @var array|null
     */
    private $classNames = null;

    /**
     * @param resource|string $dex A stream resource or the raw dex file contents.
     * @throws DexParserException
     */
    public function __construct($dex)
    {
        // Zip entry streams are not seekable, and a string would keep the whole
        // file in memory: spool to php://temp, which holds small files in memory
        // and transparently moves larger ones to disk.
        $spool = fopen('php://temp/maxmemory:2097152', 'r+b');
        if (is_resource($dex)) {
            if (stream_copy_to_stream($dex, $spool) === false) {
                throw new DexParserException('Could not read the dex data from the given stream');
            }
        } elseif (is_string($dex)) {
            if (fwrite($spool, $dex) !== strlen($dex)) {
                throw new DexParserException('Could not spool the dex data');
            }
        } else {
            throw new DexParserException('Invalid dex input, expected a stream resource or a string'
                . (is_bool($dex) ? ' (did reading the dex entry from the apk fail?)' : ''));
        }

        $stat = fstat($spool);
        $this->stream = $spool;
        $this->size = $stat['size'];

        if ($this->size < self::HEADER_SIZE) {
            throw new DexParserException('Invalid dex data, expected at least a full dex header');
        }

        $this->locateDex();
    }

    /**
     * Dex format version, e.g. 35, 39 or 41. Compact dex "cdex001" reports 1,
     * for an odex wrapper this is the version of the embedded dex, and 0 means
     * the version digits in the magic were unreadable (packed/corrupt file).
     *
     * @return int
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Container format of the parsed data: one of the FORMAT_* constants
     * ('dex', 'cdex' or 'odex').
     *
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * List the classes defined in this dex file as dotted names,
     * e.g. "com.example.app.MainActivity". For dex containers (version 041)
     * the classes of every dex section in the container are listed.
     *
     * Corrupt table entries (common in packed apps, where parts of the dex
     * are encrypted or deliberately broken) are skipped, so this returns
     * every class name that is actually recoverable from the file.
     *
     * @return array
     * @throws DexParserException
     */
    public function getClassNames()
    {
        if ($this->classNames !== null) {
            return $this->classNames;
        }

        $lists = array();
        $headerOff = $this->base;
        while ($headerOff !== null) {
            $lists[] = $this->classNamesFromHeader($headerOff);
            try {
                $headerOff = $this->nextHeaderOffset($headerOff);
            } catch (DexParserException $e) {
                // truncated container chain, keep the classes found so far
                $headerOff = null;
            }
        }

        return $this->classNames = array_values(array_unique(array_merge(...$lists)));
    }

    /**
     * Find the dex header inside the spooled data and record its offset,
     * format, version and endianness.
     *
     * @throws DexParserException
     */
    private function locateDex()
    {
        // The common case: a plain dex or compact dex right at the start.
        $info = $this->inspectHeader(0, false);
        if ($info !== null) {
            $this->applyHeader(0, $info, $info['format']);
            return;
        }

        // Legacy odex wrapper: a 40-byte opt header pointing at the real dex.
        if ($this->readBytes(0, 4) === self::ODEX_MAGIC && $this->size >= self::ODEX_HEADER_SIZE) {
            $dexOff = $this->rawUInt32(self::OFF_ODEX_DEX_OFF, false);
            $info = $this->inspectHeader($dexOff, false);
            if ($info !== null) {
                $this->applyHeader($dexOff, $info, self::FORMAT_ODEX);
                return;
            }
        }

        // Packed/wrapped data: packer stubs and container formats often carry
        // the real dex at some inner offset. Scan for a plausible dex header.
        $off = $this->scanForHeader();
        if ($off !== null) {
            $info = $this->inspectHeader($off, true);
            $this->applyHeader($off, $info, $info['format']);
            return;
        }

        throw new DexParserException('Not a dex file, no dex header found');
    }

    /**
     * @param int $base
     * @param array $info as returned by inspectHeader()
     * @param string $format one of the FORMAT_* constants
     */
    private function applyHeader($base, array $info, $format)
    {
        $this->base = $base;
        $this->format = $format;
        $this->version = $info['version'];
        $this->bigEndian = $info['bigEndian'];
    }

    /**
     * Check whether a dex or cdex header starts at the given offset and
     * extract format, version and endianness from it.
     *
     * In lenient mode (used for the natural header positions: offset 0 and an
     * odex dex_off) garbage version digits and a wiped endian tag are
     * tolerated, since packers routinely corrupt header fields the Android
     * runtime doesn't verify. In strict mode (used when scanning arbitrary
     * data, where false positives matter) the magic, endian tag and table
     * offsets all have to check out.
     *
     * @param int $off
     * @param bool $strict
     * @param int|null $offsetBase table offsets in the header are relative to
     *        this position ($off itself for a standalone dex, the container
     *        start for a v41 section)
     * @return array|null array with keys format, version, bigEndian; null if no valid header
     * @throws DexParserException
     */
    private function inspectHeader($off, $strict, $offsetBase = null)
    {
        if ($off < 0 || $off + self::HEADER_SIZE > $this->size) {
            return null;
        }

        $magic = $this->readBytes($off, 8);
        if (substr($magic, 0, 4) === self::DEX_MAGIC) {
            $format = self::FORMAT_DEX;
        } elseif (substr($magic, 0, 4) === self::CDEX_MAGIC) {
            $format = self::FORMAT_CDEX;
        } else {
            return null;
        }

        $versionDigits = substr($magic, 4, 3);
        $version = ctype_digit($versionDigits) ? (int)$versionDigits : 0;
        if ($strict && ($version === 0 || $magic[7] !== "\x00")) {
            return null;
        }

        $endianTag = $this->rawUInt32($off + self::OFF_ENDIAN_TAG, false);
        if ($endianTag === self::ENDIAN_CONSTANT) {
            $bigEndian = false;
        } elseif ($endianTag === self::REVERSE_ENDIAN_CONSTANT) {
            $bigEndian = true;
        } elseif (!$strict) {
            // Packers sometimes wipe this field; every real-world dex is
            // little-endian, so assume that.
            $bigEndian = false;
        } else {
            return null;
        }

        if ($strict && !$this->plausibleHeader($off, $bigEndian, $offsetBase === null ? $off : $offsetBase)) {
            return null;
        }

        return array('format' => $format, 'version' => $version, 'bigEndian' => $bigEndian);
    }

    /**
     * Sanity-check the table offsets of a candidate header found by scanning,
     * to rule out a stray "dex\n" byte sequence in unrelated data.
     *
     * @param int $headerOff
     * @param bool $bigEndian
     * @param int $offsetBase what the header's table offsets are relative to
     * @return bool
     * @throws DexParserException
     */
    private function plausibleHeader($headerOff, $bigEndian, $offsetBase)
    {
        $limit = $this->size - $offsetBase;
        $pairs = array(self::OFF_STRING_IDS_SIZE, self::OFF_TYPE_IDS_SIZE, self::OFF_CLASS_DEFS_SIZE);
        foreach ($pairs as $sizeOff) {
            $count = $this->rawUInt32($headerOff + $sizeOff, $bigEndian);
            $off = $this->rawUInt32($headerOff + $sizeOff + 4, $bigEndian);
            if ($count > 0 && ($off < self::HEADER_SIZE || $off >= $limit)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Scan the data for an embedded dex or cdex header and return the offset
     * of the first plausible one, or null when there is none.
     *
     * @return int|null
     * @throws DexParserException
     */
    private function scanForHeader()
    {
        $magics = array(self::DEX_MAGIC, self::CDEX_MAGIC);
        $overlap = 3; // magic length - 1, so a magic split across chunks is still seen

        $pos = 1; // offset 0 was already inspected
        while ($pos + self::HEADER_SIZE <= $this->size) {
            $chunkLen = (int)min(self::BUFFER_SIZE, $this->size - $pos);
            $chunk = (string)stream_get_contents($this->stream, $chunkLen, $pos);

            $candidates = array();
            foreach ($magics as $magic) {
                $search = 0;
                while (($found = strpos($chunk, $magic, $search)) !== false) {
                    $candidates[] = $pos + $found;
                    $search = $found + 1;
                }
            }
            sort($candidates);

            foreach ($candidates as $candidate) {
                if ($this->inspectHeader($candidate, true) !== null) {
                    return $candidate;
                }
            }

            $pos += $chunkLen - $overlap;
        }

        return null;
    }

    /**
     * List the classes defined by the dex header at the given absolute offset.
     * Table counts are clamped to the file bounds and entries pointing outside
     * the file are skipped, so inflated or corrupted headers (a favourite
     * packer trick) degrade to a partial listing instead of a hard failure.
     *
     * @param int $headerOff
     * @return array
     * @throws DexParserException
     */
    private function classNamesFromHeader($headerOff)
    {
        $stringIdsSize = $this->readUInt32($headerOff + self::OFF_STRING_IDS_SIZE);
        $stringIdsOff = $this->base + $this->readUInt32($headerOff + self::OFF_STRING_IDS_OFF);
        $typeIdsSize = $this->readUInt32($headerOff + self::OFF_TYPE_IDS_SIZE);
        $typeIdsOff = $this->base + $this->readUInt32($headerOff + self::OFF_TYPE_IDS_OFF);
        $classDefsSize = $this->readUInt32($headerOff + self::OFF_CLASS_DEFS_SIZE);
        $classDefsOff = $this->base + $this->readUInt32($headerOff + self::OFF_CLASS_DEFS_OFF);

        // In dex containers (v41) all header offsets are relative to the start
        // of the container; for plain and embedded dex they are relative to the
        // dex header. Both cases are covered by adding $this->base above.

        $stringIdsSize = min($stringIdsSize, $this->itemsWithin($stringIdsOff, 4));
        $typeIdsSize = min($typeIdsSize, $this->itemsWithin($typeIdsOff, 4), self::MAX_TYPE_IDS);
        $classDefsSize = min($classDefsSize, $this->itemsWithin($classDefsOff, self::CLASS_DEF_ITEM_SIZE), self::MAX_TYPE_IDS);

        // The four tables involved sit megabytes apart in a real dex, so they
        // are walked one at a time in ascending offset order; interleaving the
        // lookups would evict the read window on almost every access.

        // The first field of a class_def_item is class_idx, an index into type_ids.
        $classIdxs = array();
        for ($i = 0; $i < $classDefsSize; $i++) {
            $classIdx = $this->readUInt32($classDefsOff + $i * self::CLASS_DEF_ITEM_SIZE);
            if ($classIdx < $typeIdsSize) {
                $classIdxs[] = $classIdx;
            }
        }

        // A type_id_item is a single descriptor_idx, an index into string_ids.
        $descriptorIdxByType = array();
        $wanted = array_values(array_unique($classIdxs));
        sort($wanted);
        foreach ($wanted as $classIdx) {
            $descriptorIdx = $this->readUInt32($typeIdsOff + $classIdx * 4);
            if ($descriptorIdx < $stringIdsSize) {
                $descriptorIdxByType[$classIdx] = $descriptorIdx;
            }
        }

        // A string_id_item is the offset of the string data.
        $dataOffByDescriptor = array();
        $wanted = array_values(array_unique($descriptorIdxByType));
        sort($wanted);
        foreach ($wanted as $descriptorIdx) {
            $dataOffByDescriptor[$descriptorIdx] = $this->base + $this->readUInt32($stringIdsOff + $descriptorIdx * 4);
        }

        $nameByDescriptor = array();
        asort($dataOffByDescriptor);
        foreach ($dataOffByDescriptor as $descriptorIdx => $dataOff) {
            try {
                $descriptor = $this->readString($dataOff);
            } catch (DexParserException $e) {
                continue; // this entry points outside the file, skip it
            }

            // Class descriptors look like "Lcom/example/Foo;". Control bytes
            // never appear in a real descriptor, only in mangled string data.
            if (strlen($descriptor) > 2
                && $descriptor[0] === 'L'
                && substr($descriptor, -1) === ';'
                && !preg_match('/[\x00-\x1F]/', $descriptor)
            ) {
                $descriptor = self::mutf8ToUtf8($descriptor);
                $nameByDescriptor[$descriptorIdx] = str_replace('/', '.', substr($descriptor, 1, -1));
            }
        }

        // Emit in class_def order, dropping the entries lost to corruption.
        $classNames = array();
        foreach ($classIdxs as $classIdx) {
            if (isset($descriptorIdxByType[$classIdx])
                && isset($nameByDescriptor[$descriptorIdxByType[$classIdx]])
            ) {
                $classNames[] = $nameByDescriptor[$descriptorIdxByType[$classIdx]];
            }
        }

        return $classNames;
    }

    /**
     * How many items of the given size fit between an offset and the end of
     * the file; 0 when the offset is outside the file entirely.
     *
     * @param int $offset
     * @param int $itemSize
     * @return int
     */
    private function itemsWithin($offset, $itemSize)
    {
        if ($offset < self::HEADER_SIZE || $offset >= $this->size) {
            return 0;
        }
        return intdiv($this->size - $offset, $itemSize);
    }

    /**
     * Offset of the next dex header inside a v41 container, or null when there
     * is no further dex section (always null for pre-container versions, which
     * hold exactly one dex per file). A corrupt section chain ends the
     * iteration instead of failing, so the classes of the intact sections
     * are still returned.
     *
     * @param int $headerOff
     * @return int|null
     * @throws DexParserException
     */
    private function nextHeaderOffset($headerOff)
    {
        if ($this->format === self::FORMAT_CDEX || $this->version < self::CONTAINER_VERSION) {
            return null;
        }

        // A real container header is 0x78 bytes long and says so in its
        // header_size field. Anything else (an unknown future version, or a
        // packer that stamped ">= 041" digits onto a legacy file) has no
        // container fields to read, so treat it as a single dex.
        if ($this->base + self::HEADER_SIZE_V41 > $this->size
            || $this->readUInt32($this->base + self::OFF_HEADER_SIZE) !== self::HEADER_SIZE_V41
        ) {
            return null;
        }

        $containerSize = min($this->base + $this->readUInt32($this->base + self::OFF_CONTAINER_SIZE), $this->size);

        // In v41 file_size holds the distance from this header to the next
        // header, or to the end of the container for the last section.
        $sectionSize = $this->readUInt32($headerOff + self::OFF_FILE_SIZE);
        if ($sectionSize < self::HEADER_SIZE) {
            return null;
        }

        $nextOff = $headerOff + $sectionSize;
        if ($nextOff + self::HEADER_SIZE > $containerSize) {
            return null;
        }

        // Sections are strictly validated: unlike a whole packed file, a
        // corrupt section buys nothing by being parsed leniently, it would
        // only pollute the result of the intact sections before it. Table
        // offsets in a section are container-relative, hence the base.
        $info = $this->inspectHeader($nextOff, true, $this->base);
        if ($info === null || $info['format'] !== self::FORMAT_DEX) {
            return null;
        }

        // Each section carries its own endian tag.
        $this->bigEndian = $info['bigEndian'];

        return $nextOff;
    }

    /**
     * Bounds-checked read of a byte range, served from a small sliding window
     * over the backing stream.
     *
     * @param int $offset
     * @param int $length
     * @return string
     * @throws DexParserException
     */
    private function readBytes($offset, $length)
    {
        if ($offset < 0 || $length < 0 || $offset + $length > $this->size) {
            throw new DexParserException('Dex offset out of bounds: ' . $offset);
        }
        if ($length === 0) {
            return '';
        }

        if ($this->bufferOffset >= 0
            && $offset >= $this->bufferOffset
            && $offset + $length <= $this->bufferOffset + strlen($this->buffer)
        ) {
            return substr($this->buffer, $offset - $this->bufferOffset, $length);
        }

        if ($length > self::BUFFER_SIZE) {
            $data = stream_get_contents($this->stream, $length, $offset);
            if ($data === false || strlen($data) < $length) {
                throw new DexParserException('Unexpected end of dex data at offset ' . $offset);
            }
            return $data;
        }

        $this->buffer = (string)stream_get_contents($this->stream, self::BUFFER_SIZE, $offset);
        $this->bufferOffset = $offset;
        if (strlen($this->buffer) < $length) {
            throw new DexParserException('Unexpected end of dex data at offset ' . $offset);
        }

        return substr($this->buffer, 0, $length);
    }

    /**
     * @param int $offset
     * @return int
     * @throws DexParserException
     */
    private function readUInt32($offset)
    {
        return $this->rawUInt32($offset, $this->bigEndian);
    }

    /**
     * @param int $offset
     * @param bool $bigEndian
     * @return int
     * @throws DexParserException
     */
    private function rawUInt32($offset, $bigEndian)
    {
        $unpacked = unpack($bigEndian ? 'N' : 'V', $this->readBytes($offset, 4));
        $value = $unpacked[1];
        // On 32-bit PHP unpack() returns a signed int; lift values with the
        // high bit set back into the positive range (as a float there, which
        // is fine: they only ever feed comparisons and always exceed any real
        // file size, so the bounds checks reject them).
        return $value < 0 ? $value + 4294967296 : $value;
    }

    /**
     * Read a string_data_item: a uleb128 utf16 code unit count followed by
     * MUTF-8 encoded bytes, null terminated. MUTF-8 encodes U+0000 as 0xC0 0x80,
     * so a plain NUL byte always marks the end of the string. Neither part is
     * affected by the file's endianness.
     *
     * @param int $offset
     * @return string
     * @throws DexParserException
     */
    private function readString($offset)
    {
        $this->readUleb128($offset); // skip the utf16 length, $offset is advanced

        $string = '';
        while (true) {
            $chunkLen = min(256, $this->size - $offset);
            if ($chunkLen <= 0) {
                return $string; // string runs to the end of the file, unterminated
            }
            $chunk = $this->readBytes($offset, $chunkLen);
            $nul = strpos($chunk, "\x00");
            if ($nul !== false) {
                return $string . substr($chunk, 0, $nul);
            }
            $string .= $chunk;
            $offset += $chunkLen;
            if (strlen($string) > self::MAX_STRING_LENGTH) {
                throw new DexParserException('Unterminated string in dex file at offset ' . $offset);
            }
        }
    }

    /**
     * Read an unsigned little-endian base-128 value and advance the offset
     * past it.
     *
     * @param int $offset
     * @return int
     * @throws DexParserException
     */
    private function readUleb128(&$offset)
    {
        $result = 0;
        $shift = 0;
        do {
            $byte = ord($this->readBytes($offset, 1));
            $offset++;
            if ($shift < 35) {
                // a uleb128 is at most 5 bytes; bytes past that (corrupt data)
                // are still consumed below so the offset lands after the value,
                // but no longer contribute to it
                $result |= ($byte & 0x7F) << $shift;
            }
            $shift += 7;
        } while (($byte & 0x80) != 0 && $shift < 70);

        return $result;
    }

    /**
     * Convert MUTF-8 to proper UTF-8. The two encodings only differ for
     * characters outside the Basic Multilingual Plane, which MUTF-8 stores as
     * a CESU-8 style surrogate pair (two 3-byte sequences) where UTF-8 uses
     * one 4-byte sequence. Dex version 040+ allows such characters in class
     * names.
     *
     * @param string $string
     * @return string
     */
    private static function mutf8ToUtf8($string)
    {
        if (strpos($string, "\xED") === false) {
            return $string; // no surrogates, already valid UTF-8
        }

        return preg_replace_callback(
            '/\xED[\xA0-\xAF][\x80-\xBF]\xED[\xB0-\xBF][\x80-\xBF]/',
            function ($m) {
                $high = 0xD000 | ((ord($m[0][1]) & 0x3F) << 6) | (ord($m[0][2]) & 0x3F);
                $low = 0xD000 | ((ord($m[0][4]) & 0x3F) << 6) | (ord($m[0][5]) & 0x3F);
                $cp = 0x10000 + (($high - 0xD800) << 10) + ($low - 0xDC00);
                return chr(0xF0 | ($cp >> 18))
                    . chr(0x80 | (($cp >> 12) & 0x3F))
                    . chr(0x80 | (($cp >> 6) & 0x3F))
                    . chr(0x80 | ($cp & 0x3F));
            },
            $string
        );
    }
}
