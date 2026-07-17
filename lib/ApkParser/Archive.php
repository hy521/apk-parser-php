<?php

namespace ApkParser;

use ApkParser\Exceptions\ApkException;
use ApkParser\Exceptions\FileNotFoundException;

/**
 * This file is part of the Apk Parser package.
 *
 * (c) Tufan Baris Yildirim <tufanbarisyildirim@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class Archive extends \ZipArchive
{
    /**
     * @var string
     */
    private $filePath;

    /**
     * @var string
     */
    private $fileName;

    /**
     * @param string|bool $file
     * @throws ApkException
     * @throws FileNotFoundException
     */
    public function __construct($file = false)
    {
        if (!$file || !is_file($file)) {
            throw new FileNotFoundException($file . " not a regular file");
        }

        $result = $this->open($file);
        if ($result !== true) {
            throw new ApkException(
                sprintf(
                    'Unable to open APK archive "%s": %s',
                    $file,
                    self::getOpenErrorMessage((int)$result)
                )
            );
        }

        $this->fileName = basename($this->filePath = $file);
    }

    /**
     * @param int $errorCode
     * @return string
     */
    private static function getOpenErrorMessage($errorCode)
    {
        $messages = array(
            self::ER_MULTIDISK => 'Multi-disk zip archives are not supported.',
            self::ER_RENAME => 'Failed to rename temporary file.',
            self::ER_CLOSE => 'Failed to close zip archive.',
            self::ER_SEEK => 'Seek error.',
            self::ER_READ => 'Read error.',
            self::ER_WRITE => 'Write error.',
            self::ER_CRC => 'CRC error.',
            self::ER_ZIPCLOSED => 'Containing zip archive was closed.',
            self::ER_NOENT => 'No such file.',
            self::ER_EXISTS => 'File already exists.',
            self::ER_OPEN => 'Cannot open file.',
            self::ER_TMPOPEN => 'Failure to create temporary file.',
            self::ER_ZLIB => 'Zlib error.',
            self::ER_MEMORY => 'Memory allocation failure.',
            self::ER_CHANGED => 'Entry has been changed.',
            self::ER_COMPNOTSUPP => 'Compression method not supported.',
            self::ER_EOF => 'Premature EOF.',
            self::ER_INVAL => 'Invalid argument.',
            self::ER_NOZIP => 'Not a zip archive.',
            self::ER_INTERNAL => 'Internal error.',
            self::ER_INCONS => 'Zip archive inconsistent.',
            self::ER_REMOVE => 'Cannot remove file.',
            self::ER_DELETED => 'Entry has been deleted.',
        );

        return array_key_exists($errorCode, $messages)
            ? $messages[$errorCode]
            : 'Unknown error code ' . $errorCode;
    }

    /**
     * Get a file from apk Archive by name.
     *
     * @param string $name
     * @param int $length
     * @param int $flags
     * @return string|false
     */
    public function getFromName(string $name, int $length = 0, int $flags = 0): string|false
    {
        if (strtolower(substr($name, -4)) == '.xml') {
            $xmlParser = new XmlParser(new Stream($this->getStream($name)));
            return $xmlParser->getXmlString();
        } else {
            return parent::getFromName($name, $length, $flags);
        }
    }

    /**
     * Returns an ApkStream which contains AndroidManifest.xml
     * @return Stream
     */
    public function getManifestStream()
    {
        return new Stream($this->getStream('AndroidManifest.xml'));
    }

    /**
     * @return SeekableStream
     */
    public function getResourcesStream()
    {
        return new SeekableStream($this->getStream('resources.arsc'));
    }

    /**
     * Returns an \ApkParser\Stream instance which contains classes.dex file
     * @returns \ApkParser\Stream
     */
    public function getClassesDexStream()
    {
        return new Stream($this->getStream('classes.dex'));
    }

    /**
     * List every dex entry in the apk (classes.dex, classes2.dex, ... for multidex).
     *
     * @return array entry names
     */
    public function getDexNames()
    {
        $names = array();
        for ($i = 0; $i < $this->numFiles; $i++) {
            $name = $this->getNameIndex($i);
            if (preg_match('/^classes\d*\.dex$/', $name)) {
                $names[] = $name;
            }
        }
        sort($names, SORT_NATURAL);
        return $names;
    }

    /**
     * Apk file path.
     * @return string
     */
    public function getApkPath()
    {
        return $this->filePath;
    }

    /**
     * Apk file name
     * @return string
     */
    public function getApkName()
    {
        return $this->fileName;
    }


    public function extractTo(string $destination, array|string|null $entries = null): bool
    {
        if ($extResult = parent::extractTo($destination, $entries)) {

            $xmlFiles = Utils::globRecursive($destination . '/*.xml');

            foreach ($xmlFiles as $xmlFile) {
                if ($xmlFile == ($destination . "/AndroidManifest.xml")) {
                    XmlParser::decompressFile($xmlFile);
                }
            }
        }

        return $extResult;

    }

}
