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
class Parser
{
    private $apk;
    private $manifest;
    /**
     * @var ResourcesParser|null
     */
    private $resources;
    private $config;

    /**
     * @param $apkFile
     * @param array $config
     */
    public function __construct($apkFile, array $config = array())
    {
        $this->config = new Config($config);
        $this->apk = new Archive($apkFile);
        $this->manifest = new Manifest(new XmlParser($this->apk->getManifestStream()));

        if (!$this->config->manifest_only)
            $this->resources = new ResourcesParser($this->apk->getResourcesStream());
        else
            $this->resources = NULL;

    }

    /**
     * Get Manifest Object
     * @return \ApkParser\Manifest
     */
    public function getManifest()
    {
        return $this->manifest;
    }

    /**
     * Get the apk. Zip handler.
     * - Extract all(or sp. entries) files,
     * - add file,
     * - recompress
     * - and other ZipArchive features.
     *
     * @return \ApkParser\Archive
     */
    public function getApkArchive()
    {
        return $this->apk;
    }

    public function getResources($key)
    {
        return is_null($this->resources) ? FALSE : $this->resources->getResources($key);
    }

    /**
     * Get all resources as an array, indexed by hex resource id.
     * Empty when the parser runs with manifest_only enabled.
     *
     * @return array
     */
    public function getAllResources()
    {
        return is_null($this->resources) ? array() : $this->resources->getAllResources();
    }

    public function getStream($name)
    {
        return $this->apk->getStream($name);
    }

    /**
     * Extract apk content directly
     *
     * @param mixed $destination
     * @param array $entries
     * @return bool
     */
    public function extractTo($destination, $entries = NULL)
    {
        return $this->apk->extractTo($destination, $entries);
    }

    /**
     * List the classes defined in the apk's dex files (multidex included)
     * as dotted names, e.g. "com.example.app.MainActivity".
     *
     * @return array
     * @throws Exceptions\ApkException
     * @throws Exceptions\DexParserException
     */
    public function getClasses()
    {
        $dexNames = $this->apk->getDexNames();

        if (empty($dexNames)) {
            throw new Exceptions\ApkException("No dex file found in the apk");
        }

        $classLists = array();
        foreach ($dexNames as $dexName) {
            $stream = $this->apk->getStream($dexName);
            if (!is_resource($stream)) {
                throw new Exceptions\ApkException("Could not read " . $dexName . " from the apk");
            }
            $dexParser = new DexParser($stream);
            $classLists[] = $dexParser->getClassNames();
        }

        return array_values(array_unique(array_merge(...$classLists)));
    }
}