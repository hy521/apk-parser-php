<?php

namespace ApkParser;

/**
 * This file is part of the Apk Parser package.
 *
 * (c) Tufan Baris Yildirim <tufanbarisyildirim@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class ResourcesParser
{
    const RES_STRING_POOL_TYPE = 0x0001;
    const RES_TABLE_TYPE = 0x0002;
    const RES_TABLE_PACKAGE_TYPE = 0x0200;
    const RES_TABLE_TYPE_TYPE = 0x0201;
    const RES_TABLE_TYPE_SPEC_TYPE = 0x0202;
    // The 'data' holds a ResTable_ref, a reference to another resource table entry.
    const TYPE_REFERENCE = 0x01;
    // The 'data' holds an index into the containing resource table's global value string pool.
    const TYPE_STRING = 0x03;
    const FLAG_COMPLEX = 0x0001;

    /**
     * @var SeekableStream
     */
    private $stream;

    private $valueStringPool;
    private $typeStringPool;
    private $keyStringPool;

    private $packageId = 0;
    private $resources = array();

    /**
     * Config qualifier of every stored resource value, mirrors $resources.
     * @var array
     */
    private $resourceConfigs = array();

    /**
     * @param SeekableStream $stream
     * @throws \Exception
     */
    public function __construct(SeekableStream $stream)
    {
        $this->stream = $stream;
        $this->decompress();
    }

    /**
     * @param $key
     * @return mixed
     */
    public function getResources($key)
    {
        return isset($this->resources[strtolower($key)]) ? $this->resources[strtolower($key)] : null;
    }

    /**
     * Same as getResources() but each value comes with the config qualifier
     * (e.g. "hdpi", "en-rUS-v21", "" for the default config) it was defined for.
     *
     * @param $key
     * @return array|null list of array('value' => mixed, 'config' => string)
     */
    public function getResourcesWithConfig($key)
    {
        $key = strtolower($key);
        if (!isset($this->resources[$key])) {
            return null;
        }

        $result = array();
        foreach ($this->resources[$key] as $index => $value) {
            $result[] = array(
                'value' => $value,
                'config' => isset($this->resourceConfigs[$key][$index]) ? $this->resourceConfigs[$key][$index] : '',
            );
        }
        return $result;
    }

    /**
     * @throws \Exception
     */
    private function decompress()
    {
        $type = $this->stream->readInt16LE();
        $this->stream->readInt16LE(); // headerSize
        $size = $this->stream->readInt32LE();
        $packagesCount = $this->stream->readInt32LE();

        if ($type != self::RES_TABLE_TYPE) {
            throw new \Exception('No RES_TABLE_TYPE found');
        }
        if ($size != $this->stream->size()) {
            throw new \Exception('The buffer size not matches to the resource table size');
        }

        $realStringsCount = 0;
        $realPackagesCount = 0;
        while (true) {
            $pos = $this->stream->position();
            $chunkType = $this->stream->readInt16LE();
            $this->stream->readInt16LE(); // headerSize
            $chunkSize = $this->stream->readInt32LE();
            if ($chunkType == self::RES_STRING_POOL_TYPE) {
                // Only the first string pool is processed.
                if ($realStringsCount == 0) {
                    $this->stream->seek($pos);
                    $this->valueStringPool = $this->processStringPool($this->stream->copyBytes($chunkSize));
                }
                $realStringsCount++;
            } else if ($chunkType == self::RES_TABLE_PACKAGE_TYPE) {
                $this->stream->seek($pos);
                $this->processPackage($this->stream->copyBytes($chunkSize));
                $realPackagesCount++;
            } else {
                throw new \Exception('Unsupported Type');
            }

            $this->stream->seek($pos + $chunkSize);
            if ($this->stream->position() == $size) {
                break;
            }
        }
        if ($realStringsCount != 1) {
            throw new \Exception('More than 1 string pool found!');
        }
        if ($realPackagesCount != $packagesCount) {
            throw new \Exception('Real package count not equals the declared count.');
        }
    }

    /**
     * @param SeekableStream $data
     * @throws \Exception
     */
    private function processPackage(SeekableStream $data)
    {
        $data->readInt16LE(); // type
        $headerSize = $data->readInt16LE();
        $data->readInt32LE(); // size

        $this->packageId = $data->readInt32LE();
        $packageName = $data->read(256);
        // echo 'Package name: ', $packageName, PHP_EOL;

        $typeStringsStart = $data->readInt32LE();
        $data->readInt32LE(); // lastPublicType
        $keyStringsStart = $data->readInt32LE();
        $data->readInt32LE(); // lastPublicKey

        if ($typeStringsStart != $headerSize) {
            throw new \Exception('TypeStrings must immediately follow the package structure header.');
        }

        // echo 'Type strings', PHP_EOL;
        $data->seek($typeStringsStart);
        $this->typeStringPool = $this->processStringPool($data->copyBytes($data->size() - $data->position()));

        // echo 'Key strings', PHP_EOL;
        $data->seek($keyStringsStart);
        $data->readInt16LE(); // key_type
        $data->readInt16LE(); // key_headerSize
        $keySize = $data->readInt32LE();

        $data->seek($keyStringsStart);
        $this->keyStringPool = $this->processStringPool($data->copyBytes($data->size() - $data->position()));

        $data->seek($keyStringsStart + $keySize);

        // Iterate through all chunks
        while (true) {
            $pos = $data->position();
            $chunkType = $data->readInt16LE();
            $data->readInt16LE(); // headerSize
            $chunkSize = $data->readInt32LE();
            if ($chunkType == self::RES_TABLE_TYPE_SPEC_TYPE) {
                $data->seek($pos);
                $this->processTypeSpec($data->copyBytes($chunkSize));
            } else if ($chunkType == self::RES_TABLE_TYPE_TYPE) {
                $data->seek($pos);
                $this->processType($data->copyBytes($chunkSize));
            }

            $data->seek($pos + $chunkSize);
            if ($data->position() == $data->size()) {
                break;
            }
        }
    }

    /**
     * @param SeekableStream $data
     * @return array
     */
    private function processStringPool(SeekableStream $data)
    {
        $data->readInt16LE(); // type
        $data->readInt16LE(); // headerSize
        $data->readInt32LE(); // size
        $stringsCount = $data->readInt32LE();
        $data->readInt32LE(); // stylesCount
        $flags = $data->readInt32LE();
        $stringsStart = $data->readInt32LE();
        $data->readInt32LE(); // stylesStart

        $offsets = array();
        for ($i = 0; $i < $stringsCount; $i++) {
            $offsets[$i] = $data->readInt32LE();
        }
        $isUtf8 = ($flags & 256) != 0;

        $strings = array();
        for ($i = 0; $i < $stringsCount; $i++) {
            $strings[$i] = '';
            $data->seek($stringsStart + $offsets[$i]);

            if ($isUtf8) {
                // Skip the UTF-16 char count (1 byte, or 2 when the high bit is set),
                // then read the UTF-8 encoded byte length (same variable-size scheme).
                $val = $data->readByte();
                if (($val & 0x80) != 0) {
                    $data->readByte();
                }
                $length = $data->readByte();
                if (($length & 0x80) != 0) {
                    $length = (($length & 0x7F) << 8) | $data->readByte();
                }
                if ($length > 0) {
                    $strings[$i] = $data->read($length);
                }
            } else {
                // The length is a uint16 char count, extended to two uint16s
                // when the high bit is set.
                $length = $data->readInt16LE() & 0xFFFF;
                if (($length & 0x8000) != 0) {
                    $length = (($length & 0x7FFF) << 16) | ($data->readInt16LE() & 0xFFFF);
                }
                if ($length > 0) {
                    $strings[$i] = mb_convert_encoding($data->read($length * 2), 'UTF-8', 'UTF-16LE');
                }
            }
            // echo 'Parsed value: ', $strings[$i], PHP_EOL;
        }
        return $strings;
    }

    /**
     * @param SeekableStream $data
     */
    private function processTypeSpec(SeekableStream $data)
    {
        $data->readInt16LE(); // type
        $data->readInt16LE(); // headerSize
        $data->readInt32LE(); // size
        $id = $data->readByte();
        $data->readByte(); // res0
        $data->readInt16LE(); // res1
        $entriesCount = $data->readInt32LE();

        // echo 'Processing type spec ' . $this->typeStringPool[$id - 1], PHP_EOL;
        $flags = array();
        for ($i = 0; $i < $entriesCount; ++$i) {
            $flags[$i] = $data->readInt32LE();
        }
    }

    /**
     * @param SeekableStream $data
     * @throws \Exception
     */
    private function processType(SeekableStream $data)
    {
        $data->readInt16LE(); // type
        $headerSize = $data->readInt16LE();
        $data->readInt32LE(); // size
        $id = $data->readByte();
        $data->readByte(); // res0
        $data->readInt16LE(); // res1
        $entriesCount = $data->readInt32LE();
        $entriesStart = $data->readInt32LE();
        $config_size = $data->readInt32LE(); // config_size

        if ($headerSize + $entriesCount * 4 != $entriesStart) {
            throw new \Exception('HeaderSize, entriesCount and entriesStart are not valid.');
        }

        // Skip the header data
        $data->seek($headerSize - $config_size);
        // Process the config data this type chunk was defined for.
        $config = $this->processConfig($data->copyBytes($config_size));
        $configQualifier = $config['qualifier'];

        // Start to get entry indices
        $entryIndices = array();
        for ($i = 0; $i < $entriesCount; ++$i) {
            $entryIndices[$i] = $data->readInt32LE();
        }

        // Get entries
        for ($i = 0; $i < $entriesCount; ++$i) {
            if ($entryIndices[$i] == -1) {
                continue;
            }

            $resourceId = ($this->packageId << 24) | ($id << 16) | $i;

            $data->readInt16LE(); // entry_size
            $entryFlag = $data->readInt16LE();
            $entryKey = $data->readInt32LE();

            $resourceIdString = '0x' . dechex($resourceId);
            $entryKeyString = $this->keyStringPool[$entryKey];
            // echo 'Entry ' . $resourceIdString . ', key: ' . $entryKeyString;

            // Get the value (simple) or map (complex)
            if (($entryFlag & self::FLAG_COMPLEX) == 0) {
                // echo ', simple value type';
                // Simple case
                $data->readInt16LE(); // value_size
                $data->readByte(); // value_res0
                $valueDataType = $data->readByte();
                $valueData = $data->readInt32LE();

                if ($valueDataType == self::TYPE_STRING) {
                    $value = $this->valueStringPool[$valueData];
                    $this->putResource($resourceIdString, $value, $configQualifier);
                    // echo ', data: ' . $value;
                } else if ($valueDataType == self::TYPE_REFERENCE) {
                    $referenceIdString = '0x' . dechex($valueData);
                    $this->putReferenceResource($resourceIdString, $referenceIdString);
                    // echo ', reference: ' . $referenceIdString;
                } else {
                    $this->putResource($resourceIdString, $valueData, $configQualifier);
                    // echo ', data: ' . $valueData;
                }
                // echo PHP_EOL;
            } else {
                // echo ', complex value, not printed.', PHP_EOL;
                $data->readInt32LE(); // entry_parent
                $entryCount = $data->readInt32LE();
                for ($j = 0; $j < $entryCount; ++$j) {
                    $data->readInt32LE(); // ref_name
                    $data->readInt16LE(); // value_size
                    $data->readByte(); // value_res0
                    $data->readByte(); // value_data_type
                    $data->readInt32LE(); // value_data
                }
            }
        }
    }

    /**
     * @param SeekableStream $bytes
     * @return array
     */
    private function processConfig($bytes)
    {
        $size = $bytes->size();

        // 12
        $config['size'] = $bytes->readInt32LE();
        $config['mcc'] = $bytes->readInt16LE();
        $config['mnc'] = $bytes->readInt16LE();
        $bytes->backSeek(-4);
        $config['imsi'] = $bytes->readInt32LE();

        // 8
        $config['language'] = $bytes->read(2);
        $config['country'] = $bytes->read(2);
        $bytes->backSeek(-4);
        $config['locale'] = $bytes->read(4);

        // 8
        $config['orientation'] = $bytes->readByte();
        $config['touchscreen'] = $bytes->readByte();
        $config['density'] = $bytes->readInt16LE();
        $bytes->backSeek(-4);
        $config['screenType'] = $bytes->readInt32LE();

        // 8
        $config['keyboard'] = $bytes->readByte();
        $config['navigation'] = $bytes->readByte();
        $config['inputFlags'] = $bytes->readByte();
        $config['inputPad0'] = $bytes->readByte();
        $bytes->backSeek(-4);
        $config['input'] = $bytes->readInt32LE();

        // screenSize and version (offsets 20-27) already exist in the 28-byte
        // configs written by older aapt versions.
        if ($size >= 28) {
            // 4
            $config['screenWidth'] = $bytes->readInt16LE();
            $config['screenHeight'] = $bytes->readInt16LE();
            $bytes->backSeek(-4);
            $config['screenSize'] = $bytes->readInt32LE();

            // 4
            $config['sdVersion'] = $bytes->readInt16LE();
            $config['minorVersion'] = $bytes->readInt16LE();
            $bytes->backSeek(-4);
            $config['version'] = $bytes->readInt32LE();
        }

        if ($size >= 32) {
            // 4
            $config['screenLayout'] = $bytes->readByte();
            $config['uiMode'] = $bytes->readByte();
            $config['smallestScreenWidthDp'] = $bytes->readInt16LE();
            $bytes->backSeek(-4);
            $config['screenConfig'] = $bytes->readInt32LE();
        }

        if ($size >= 36) {
            // 4
            $config['screenWidthDp'] = $bytes->readInt16LE();
            $config['screenHeightDp'] = $bytes->readInt16LE();
            $bytes->backSeek(-4);
            $config['screenSizeDp'] = $bytes->readInt32LE();
        }

        if ($size >= 48) {
            // 4
            $config['localeScript'] = $bytes->read(4);

            // 8
            $config['localeVariant'] = $bytes->read(8);
        }

        if ($size >= 52) {
            // 4
            $config['screenLayout2'] = $bytes->readByte();
            $config['colorMode'] = $bytes->readByte();
            $bytes->readInt16LE(); // screenConfigPad2
        }

        $config['qualifier'] = $this->buildConfigQualifier($config);

        return $config;
    }

    /**
     * Build a human-readable resource qualifier (like the res folder suffixes,
     * e.g. "en-rUS-land-hdpi-v21") out of a parsed ResTable_config.
     * An empty string means the default configuration.
     *
     * @param array $config
     * @return string
     */
    private function buildConfigQualifier(array $config)
    {
        $parts = array();

        if ($config['mcc'] != 0) {
            $parts[] = 'mcc' . $config['mcc'];
        }
        if ($config['mnc'] != 0) {
            $parts[] = 'mnc' . $config['mnc'];
        }

        $language = rtrim($config['language'], "\x00");
        if ($language !== '' && ctype_alpha($language)) {
            $locale = strtolower($language);
            $country = rtrim($config['country'], "\x00");
            if ($country !== '' && ctype_alpha($country)) {
                $locale .= '-r' . strtoupper($country);
            }
            $parts[] = $locale;
        }

        $orientations = array(1 => 'port', 2 => 'land', 3 => 'square');
        if (isset($orientations[$config['orientation']])) {
            $parts[] = $orientations[$config['orientation']];
        }

        $densities = array(
            120 => 'ldpi',
            160 => 'mdpi',
            213 => 'tvdpi',
            240 => 'hdpi',
            320 => 'xhdpi',
            480 => 'xxhdpi',
            640 => 'xxxhdpi',
            0xFFFE => 'anydpi',
            0xFFFF => 'nodpi',
        );
        $density = $config['density'] & 0xFFFF;
        if ($density != 0) {
            $parts[] = isset($densities[$density]) ? $densities[$density] : $density . 'dpi';
        }

        if (isset($config['sdVersion']) && $config['sdVersion'] > 0) {
            $parts[] = 'v' . $config['sdVersion'];
        }

        return implode('-', $parts);
    }

    /**
     * @param $resourceId
     * @param $value
     * @param string $configQualifier
     */
    private function putResource($resourceId, $value, $configQualifier = '')
    {
        $key = strtolower($resourceId);
        if (array_key_exists($key, $this->resources) === false) {
            $this->resources[$key] = array();
            $this->resourceConfigs[$key] = array();
        }
        $this->resources[$key][] = $value;
        $this->resourceConfigs[$key][] = $configQualifier;
    }

    /**
     * @param $resourceId
     * @param $valueData
     */
    private function putReferenceResource($resourceId, $valueData)
    {
        $key = strtolower($resourceId);
        if (array_key_exists($key, $this->resources)) {
            $values = $this->resources[$key];
            foreach ($values as $index => $value) {
                $config = isset($this->resourceConfigs[$key][$index]) ? $this->resourceConfigs[$key][$index] : '';
                $this->putResource($valueData, $value, $config);
            }
        }
    }
}