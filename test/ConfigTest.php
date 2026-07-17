<?php
/**
 * This file is part of the Apk Parser package.
 *
 * (c) Tufan Baris Yildirim <tufanbarisyildirim@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use ApkParser\Config;

class ConfigTest extends \PHPUnit\Framework\TestCase
{
    public function testGetReturnsConfiguredValues()
    {
        $config = new Config(array(
            'manifest_only' => true,
            'tmp_path' => '/custom/tmp',
        ));

        $this->assertTrue($config->get('manifest_only'));
        $this->assertSame('/custom/tmp', $config->get('tmp_path'));
    }

    public function testDefaultsAreApplied()
    {
        $config = new Config();

        $this->assertFalse($config->get('manifest_only'));
        $this->assertSame(sys_get_temp_dir(), $config->get('tmp_path'));
    }

    public function testGetReturnsNullForUnknownKey()
    {
        $config = new Config();

        $this->assertNull($config->get('unknown_key'));
    }

    public function testMagicGetReturnsNullForUnknownKey()
    {
        $config = new Config();

        $this->assertNull($config->unknown_key);
    }
}
