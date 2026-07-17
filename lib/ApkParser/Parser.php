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

    public function getClasses()
    {
        $dexStream = $this->apk->getClassesDexStream();
        $apkName = $this->apk->getApkName();

        $cache_folder = $this->config->tmp_path . '/' . str_replace('.', '_', $apkName) . '/';

        // No folder means no cached data.
        if (!is_dir($cache_folder))
            mkdir($cache_folder, 0755, true);

        $dex_file = $cache_folder . '/classes.dex';
        $dexStream->save($dex_file);

        // run shell command to extract  dalvik compiled codes to the cache folder.
        // Template : java -jar dedexer.jar -d {destination_folder} {source_dex_file}
        $command = "java -jar " . escapeshellarg($this->config->jar_path)
            . " -d " . escapeshellarg($cache_folder)
            . " " . escapeshellarg($dex_file);
        $returns = shell_exec($command);

        $file_list = Utils::globRecursive($cache_folder . '*.ddx');

        // dedexer always writes something to stdout, so instead of inspecting the
        // output we verify that the decompiled class files were actually produced.
        if (empty($file_list)) {
            throw new \Exception("Couldn't decompile .dex file" . ($returns ? " : " . trim($returns) : ""));
        }

        //Make classnames more readable.
        foreach ($file_list as &$file) {
            $file = str_replace($cache_folder, '', $file);
            $file = str_replace('/', '.', $file);
            $file = str_replace('.ddx', '', $file);
            $file = trim($file, '.');
        }


        return $file_list;

    }
}