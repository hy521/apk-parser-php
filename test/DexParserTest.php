<?php
/**
 * This file is part of the Apk Parser package.
 *
 * (c) Evozi <email@evozi.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use ApkParser\DexParser;
use ApkParser\Exceptions\DexParserException;

class DexParserTest extends \PHPUnit\Framework\TestCase
{
    const ENDIAN_CONSTANT = 0x12345678;
    const REVERSE_ENDIAN_CONSTANT = 0x78563412;

    /**
     * Build a minimal dex file (or one section of a v41 container) that
     * defines the given class descriptors.
     *
     * @param string[] $descriptors e.g. ["Lcom/example/Foo;"]
     * @param string $version three-digit version string, e.g. "035"
     * @param int $base offset of this section within the container file
     * @param int $containerSize total container size, v41 only
     * @param bool $bigEndian emit a byte-swapped (REVERSE_ENDIAN_CONSTANT) file
     * @return string
     */
    private function buildDex(array $descriptors, $version = '035', $base = 0, $containerSize = 0, $bigEndian = false)
    {
        $u4 = function ($value) use ($bigEndian) {
            return pack($bigEndian ? 'N' : 'V', $value);
        };

        $isContainer = (int)$version >= 41;
        $headerSize = $isContainer ? 0x78 : 0x70;
        $count = count($descriptors);

        $stringIdsOff = $base + $headerSize;
        $typeIdsOff = $stringIdsOff + 4 * $count;
        $classDefsOff = $typeIdsOff + 4 * $count;
        $stringDataOff = $classDefsOff + 32 * $count;

        $stringData = '';
        $stringIds = '';
        foreach ($descriptors as $descriptor) {
            $stringIds .= $u4($stringDataOff + strlen($stringData));
            // uleb128 utf16 length (descriptors are short here, so one byte is enough)
            $stringData .= chr(strlen($descriptor)) . $descriptor . "\x00";
        }

        $typeIds = '';
        $classDefs = '';
        for ($i = 0; $i < $count; $i++) {
            $typeIds .= $u4($i); // descriptor_idx
            $classDefs .= $u4($i) . str_repeat("\x00", 28); // class_idx + 7 unused fields
        }

        $fileSize = $headerSize + strlen($stringIds) + strlen($typeIds) + strlen($classDefs) + strlen($stringData);

        $header = "dex\n" . $version . "\x00";
        $header .= $u4(0); // checksum
        $header .= str_repeat("\x00", 20); // signature
        $header .= $u4($fileSize);
        $header .= $u4($headerSize);
        // the endian tag itself is stored little-endian either way; a swapped
        // file is exactly what makes it read back as REVERSE_ENDIAN_CONSTANT
        $header .= pack('V', $bigEndian ? self::REVERSE_ENDIAN_CONSTANT : self::ENDIAN_CONSTANT);
        $header .= $u4(0) . $u4(0); // link_size, link_off
        $header .= $u4(0); // map_off
        $header .= $u4($count) . $u4($stringIdsOff);
        $header .= $u4($count) . $u4($typeIdsOff);
        $header .= $u4(0) . $u4(0); // proto_ids
        $header .= $u4(0) . $u4(0); // field_ids
        $header .= $u4(0) . $u4(0); // method_ids
        $header .= $u4($count) . $u4($classDefsOff);
        $header .= $u4(0) . $u4(0); // data_size, data_off
        if ($isContainer) {
            $header .= $u4($containerSize) . $u4($base); // container_size, header_offset
        }

        return $header . $stringIds . $typeIds . $classDefs . $stringData;
    }

    public static function legacyVersionProvider()
    {
        return array(
            'v035 (Android 1.0+)' => array('035'),
            'v036' => array('036'),
            'v037 (Android 7.0)' => array('037'),
            'v038 (Android 8.0)' => array('038'),
            'v039 (Android 9.0)' => array('039'),
            'v040 (Android 10)' => array('040'),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyVersionProvider')]
    public function testParsesEveryLegacyDexVersion($version)
    {
        $dex = $this->buildDex(array('Lcom/example/Foo;', 'Lcom/example/bar/Baz;'), $version);
        $parser = new DexParser($dex);

        $this->assertSame((int)$version, $parser->getVersion());
        $this->assertSame(DexParser::FORMAT_DEX, $parser->getFormat());
        $this->assertSame(array('com.example.Foo', 'com.example.bar.Baz'), $parser->getClassNames());
    }

    public function testParsesV41Container()
    {
        // Two dex sections in one container (Android 15+ dex containers).
        $first = $this->buildDex(array('Lcom/example/First;'), '041');
        $firstLen = strlen($first);
        $second = $this->buildDex(array('Lcom/example/Second;'), '041', $firstLen);
        $containerSize = $firstLen + strlen($second);

        // Rebuild with the final container size known.
        $first = $this->buildDex(array('Lcom/example/First;'), '041', 0, $containerSize);
        $second = $this->buildDex(array('Lcom/example/Second;'), '041', $firstLen, $containerSize);

        $parser = new DexParser($first . $second);

        $this->assertSame(41, $parser->getVersion());
        $this->assertSame(array('com.example.First', 'com.example.Second'), $parser->getClassNames());
    }

    public function testCorruptContainerSectionKeepsEarlierSections()
    {
        $first = $this->buildDex(array('Lcom/example/First;'), '041');
        $firstLen = strlen($first);
        $garbage = str_repeat("\xAA", 0x200);
        $containerSize = $firstLen + strlen($garbage);
        $first = $this->buildDex(array('Lcom/example/First;'), '041', 0, $containerSize);

        $parser = new DexParser($first . $garbage);

        $this->assertSame(array('com.example.First'), $parser->getClassNames());
    }

    public function testParsesByteSwappedDex()
    {
        $dex = $this->buildDex(array('Lcom/example/Swapped;'), '035', 0, 0, true);
        $parser = new DexParser($dex);

        $this->assertSame(35, $parser->getVersion());
        $this->assertSame(array('com.example.Swapped'), $parser->getClassNames());
    }

    public function testParsesCompactDex()
    {
        // Compact dex shares the standard header layout for the tables used here.
        $dex = $this->buildDex(array('Lcom/example/Compact;'));
        $cdex = 'cdex001' . "\x00" . substr($dex, 8);

        $parser = new DexParser($cdex);

        $this->assertSame(DexParser::FORMAT_CDEX, $parser->getFormat());
        $this->assertSame(1, $parser->getVersion());
        $this->assertSame(array('com.example.Compact'), $parser->getClassNames());
    }

    public function testParsesOdexWrappedDex()
    {
        $dex = $this->buildDex(array('Lcom/example/Odexed;'));
        // 40-byte odex opt header: magic, dex_off, dex_len, deps_off,
        // deps_len, opt_off, opt_len, flags, checksum
        $odex = "dey\n036\x00" . pack('V8', 40, strlen($dex), 0, 0, 0, 0, 0, 0) . $dex;

        $parser = new DexParser($odex);

        $this->assertSame(DexParser::FORMAT_ODEX, $parser->getFormat());
        $this->assertSame(35, $parser->getVersion());
        $this->assertSame(array('com.example.Odexed'), $parser->getClassNames());
    }

    public function testFindsDexEmbeddedInPackedData()
    {
        // Packer stubs commonly prepend loader data to the real dex.
        $dex = $this->buildDex(array('Lcom/example/Packed;'));
        $blob = 'STUB' . str_repeat("\xAB", 300) . $dex . str_repeat("\xCD", 64);

        $parser = new DexParser($blob);

        $this->assertSame(array('com.example.Packed'), $parser->getClassNames());
    }

    public function testToleratesGarbageVersionDigits()
    {
        // Some packers stamp junk over the version part of the magic.
        $dex = $this->buildDex(array('Lcom/example/Foo;'));
        $dex = "dex\nzz!\x00" . substr($dex, 8);

        $parser = new DexParser($dex);

        $this->assertSame(0, $parser->getVersion());
        $this->assertSame(array('com.example.Foo'), $parser->getClassNames());
    }

    public function testFutureVersionOnLegacyFileIsNotTreatedAsContainer()
    {
        // A legacy-layout dex (0x70 header) whose magic claims a future
        // version must not be walked with v41 container logic.
        $dex = $this->buildDex(array('Lcom/example/Foo;'));
        $dex = substr_replace($dex, '042', 4, 3);

        $parser = new DexParser($dex);

        $this->assertSame(42, $parser->getVersion());
        $this->assertSame(array('com.example.Foo'), $parser->getClassNames());
    }

    public function testTruncatedV41HeaderDegradesGracefully()
    {
        $dex = substr($this->buildDex(array('Lcom/example/Foo;'), '041'), 0, 0x74);

        $parser = new DexParser($dex);

        $this->assertSame(array(), $parser->getClassNames());
    }

    public function testOverlongUleb128StringLengthIsSkippedCleanly()
    {
        // The uleb128 utf16 length is at most 5 bytes; a corrupt overlong one
        // must be consumed entirely so the string is read from the right spot.
        $descriptor = 'Lcom/example/Overlong;';
        $dex = $this->buildDex(array($descriptor));
        $stringDataOff = strlen($dex) - strlen($descriptor) - 2; // uleb byte + descriptor + NUL
        $overlong = "\xFF\xFF\xFF\xFF\xFF\x01"; // 6-byte encoding, continuation on byte 5
        $dex = substr($dex, 0, $stringDataOff) . $overlong . $descriptor . "\x00";
        // string_id[0] (at 0x70) still points at $stringDataOff, unchanged

        $parser = new DexParser($dex);

        $this->assertSame(array('com.example.Overlong'), $parser->getClassNames());
    }

    public function testToleratesWipedEndianTag()
    {
        $dex = $this->buildDex(array('Lcom/example/Foo;'));
        $dex = substr_replace($dex, pack('V', 0), 0x28, 4);

        $parser = new DexParser($dex);

        $this->assertSame(array('com.example.Foo'), $parser->getClassNames());
    }

    public function testClampsInflatedClassDefsCount()
    {
        // Inflated table counts are a common anti-decompiler trick.
        $dex = $this->buildDex(array('Lcom/example/Foo;'));
        $dex = substr_replace($dex, pack('V', 0x00FFFFFF), 0x60, 4);

        $parser = new DexParser($dex);

        $this->assertSame(array('com.example.Foo'), $parser->getClassNames());
    }

    public function testDecodesSupplementaryPlaneClassNames()
    {
        // Dex 040+ allows non-BMP characters in class names; MUTF-8 stores
        // them as surrogate pairs (U+1F600 here).
        $descriptor = "Lcom/example/\xED\xA0\xBD\xED\xB8\x80;";
        $parser = new DexParser($this->buildDex(array($descriptor), '040'));

        $this->assertSame(array("com.example.\u{1F600}"), $parser->getClassNames());
    }

    public function testAcceptsStreamInput()
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $this->buildDex(array('Lcom/example/Foo;')));
        rewind($stream);

        $parser = new DexParser($stream);

        $this->assertSame(array('com.example.Foo'), $parser->getClassNames());
    }

    public function testRejectsTruncatedDex()
    {
        $this->expectException(DexParserException::class);
        new DexParser(substr($this->buildDex(array('Lcom/example/Foo;')), 0, 40));
    }

    public function testRejectsDataWithNoDexHeader()
    {
        $this->expectException(DexParserException::class);
        new DexParser('PK' . str_repeat("\x00", 0x200));
    }

    public function testOutOfBoundsStringOffsetIsSkippedNotFatal()
    {
        $dex = $this->buildDex(array('Lcom/example/Foo;', 'Lcom/example/Bar;'));
        // Corrupt the first string_id entry (right after the 0x70 header)
        // to point far past the end of the file; the second class survives.
        $dex = substr_replace($dex, pack('V', 0x7FFFFFFF), 0x70, 4);

        $parser = new DexParser($dex);

        $this->assertSame(array('com.example.Bar'), $parser->getClassNames());
    }
}
