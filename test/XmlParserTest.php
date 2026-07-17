<?php
/**
 * This file is part of the Apk Parser package.
 *
 * (c) Evozi <email@evozi.com>
 * (c) Fred Cox <fred@ekreative.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class XmlParserTest extends \PHPUnit\Framework\TestCase
{
    public function testXmlObject()
    {
        $mock = $this->getMockBuilder(\ApkParser\XmlParser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('getXmlString'))
            ->getMock();

        $file = __DIR__ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'invalid.xml';
        $mock->expects($this->once())->method('getXmlString')->willReturn(file_get_contents($file));

        $this->expectException(\ApkParser\Exceptions\XmlParserException::class);

        $mock->getXmlObject();
    }
}
