<?php
/**
 * This file is part of the Apk Parser package.
 *
 * (c) Tufan Baris Yildirim <tufanbarisyildirim@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use ApkParser\AndroidPlatform;

class AndroidPlatformTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        // The ANDROID_API_* constants are defined in the class file,
        // so make sure it is autoloaded before referencing them.
        class_exists(AndroidPlatform::class);
    }

    public function testKnowsModernApiLevels()
    {
        $platform = new AndroidPlatform(ANDROID_API_BAKLAVA);
        $this->assertSame(36, $platform->getLevel());
        $this->assertSame('Android 16.0', $platform->platform);

        $tiramisu = new AndroidPlatform(ANDROID_API_TIRAMISU);
        $this->assertSame('Android 13.0', $tiramisu->platform);
    }

    public function testFromVersionResolvesPlatform()
    {
        $platform = AndroidPlatform::fromVersion('11.0');
        $this->assertInstanceOf(AndroidPlatform::class, $platform);
        $this->assertSame(ANDROID_API_R, $platform->getLevel());
    }

    public function testFromVersionReturnsNullForUnknownVersion()
    {
        $this->assertNull(AndroidPlatform::fromVersion('99.9'));
    }

    public function testThrowsOnUnknownApiLevel()
    {
        $this->expectException(\Exception::class);
        new AndroidPlatform(999);
    }
}
