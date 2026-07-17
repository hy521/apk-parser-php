<?php
/**
 * This file is part of the Apk Parser package.
 *
 * (c) Tufan Baris Yildirim <tufanbarisyildirim@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ApkParser;
/**
 * Class Config
 * @package ApkParser
 * @property $tmp_path string
 * @property $manifest_only boolean
 */
class Config
{
    /**
     * @var array
     */
    private $config;

    /**
     * @param array $config
     */
    public function __construct(array $config = array())
    {
        if (array_key_exists('jar_path', $config)) {
            // Dex parsing is built in now (see DexParser), a custom dedexer jar can no longer be used.
            trigger_error('The jar_path config option is no longer supported and will be ignored.', E_USER_DEPRECATED);
        }

        $this->config = array_merge(array(
            'tmp_path' => sys_get_temp_dir(),
            'manifest_only' => false
        ), $config);
    }

    /**
     * @param $key
     * @return mixed
     */
    public function get($key)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : null;
    }

    /**
     * @param $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->get($key);
    }

    /**
     * @param $name
     * @param $value
     * @return mixed
     * @internal param $key
     */
    public function __set($name, $value)
    {
        return $this->config[$name] = $value;
    }
}