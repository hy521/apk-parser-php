<?php

namespace ApkParser;

/**
 * This file is part of the Apk Parser package.
 *
 * (c) Tufan Baris Yildirim <tufanbarisyildirim@gmail.com>
 * (c) Evozi <email@evozi.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class Manifest extends Xml
{

    private $xmlParser;
    private $attrs = null;
    private $meta = null;

    /**
     * @param XmlParser $xmlParser
     */
    public function __construct(XmlParser $xmlParser)
    {
        $this->xmlParser = $xmlParser;
    }

    /**
     * @return Application
     * @throws Exceptions\XmlParserException
     */
    public function getApplication()
    {
        return $this->getXmlObject()->getApplication();
    }

    /**
     * Returns ManifestXml as a String.
     * @return string
     * @throws \Exception
     */
    public function getXmlString()
    {
        return $this->xmlParser->getXmlString();
    }

    /**
     * Get Application Permissions
     * @param string $lang
     * @return array
     * @throws Exceptions\XmlParserException
     */
    public function getPermissions($lang = 'en')
    {
        return $this->getXmlObject()->getPermissions($lang);
    }

    /**
     * Get Application Permissions as the raw android permission names,
     * e.g. "android.permission.INTERNET".
     * @return array
     * @throws Exceptions\XmlParserException
     */
    public function getPermissionsRaw()
    {
        return $this->getXmlObject()->getPermissionsRaw();
    }

    /**
     * Android Package Name
     * @return string
     * @throws \Exception
     */
    public function getPackageName()
    {
        return $this->getAttribute('package');
    }

    /**
     * Application Version Name
     * @return string
     * @throws \Exception
     */
    public function getVersionName()
    {
        return $this->getAttribute('versionName');
    }

    /**
     * Application Version Code
     * @return mixed
     * @throws \Exception
     */
    public function getVersionCode()
    {
        return hexdec($this->getAttribute('versionCode'));
    }

    /**
     * @return bool
     * @throws Exceptions\XmlParserException
     */
    public function isDebuggable()
    {
        // android:debuggable lives on the <application> element.
        $debuggable = $this->getApplication()->getAttr('debuggable');

        // Keep compatibility for manifests where debuggable is exposed on root attrs.
        if ($debuggable === null && $this->attrs !== null && array_key_exists('debuggable', $this->attrs)) {
            $debuggable = $this->attrs['debuggable'];
        }

        if ($debuggable === null) {
            return false;
        }

        return $this->parseBooleanValue($debuggable);
    }

    /**
     * Parse Android boolean-ish attribute values ("true", "0xffffffff", "1") safely.
     *
     * @param mixed $value
     * @return bool
     */
    private function parseBooleanValue($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int)$value) !== 0;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return false;
            }
            if (preg_match('/^0x[0-9a-f]+$/i', $value) === 1) {
                return hexdec($value) !== 0;
            }
            if (is_numeric($value)) {
                return ((float)$value) !== 0.0;
            }

            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) {
                return $bool;
            }
        }

        return (bool)$value;
    }

    /**
     * The minimum API Level required for the application to run.
     * @return int
     * @throws Exceptions\XmlParserException
     */
    public function getMinSdkLevel()
    {
        $xmlObj = $this->getXmlObject();
        if (!isset($xmlObj->{'uses-sdk'})) return null;
        $usesSdk = get_object_vars($xmlObj->{'uses-sdk'});
        if (!isset($usesSdk['@attributes']['minSdkVersion'])) return null;
        return hexdec($usesSdk['@attributes']['minSdkVersion']);
    }

    /**
     * @param $attributeName
     * @return mixed
     * @throws \Exception
     */
    private function getAttribute($attributeName)
    {
        if ($this->attrs === null) {
            $xmlObj = $this->getXmlObject();
            $vars = get_object_vars($xmlObj->attributes());
            $this->attrs = $vars['@attributes'];
        }

        if (!isset($this->attrs[$attributeName]))
            throw new \Exception("Attribute not found : " . $attributeName);

        return $this->attrs[$attributeName];
    }

    /**
     * @param $name
     * @return mixed
     * @throws Exceptions\XmlParserException
     */
    public function getMetaData($name)
    {
        if ($this->meta === null) {
            $xmlObj = $this->getXmlObject();
            $nodes = $xmlObj->xpath('//meta-data') ?: array();
            $this->meta = array();
            foreach ($nodes as $node) {
                $nodeAttrs = get_object_vars($node->attributes());
                if (!isset($nodeAttrs['@attributes']['name'])) {
                    continue;
                }
                $nodeName = $nodeAttrs['@attributes']['name'];
                if (array_key_exists('value', $nodeAttrs['@attributes'])) {
                    $this->meta[$nodeName] = $nodeAttrs['@attributes']['value'];
                } elseif (array_key_exists('resource', $nodeAttrs['@attributes'])) {
                    $this->meta[$nodeName] = $nodeAttrs['@attributes']['resource'];
                }
            }
        }
        return isset($this->meta[$name]) ? $this->meta[$name] : null;
    }

    /**
     * More Information About The minimum API Level required for the application to run.
     * @return AndroidPlatform
     * @throws \Exception
     */
    public function getMinSdk()
    {
        if ($this->getMinSdkLevel() !== null) return new AndroidPlatform($this->getMinSdkLevel());
        return null;
    }

    /**
     * More Information About The target API Level required for the application to run.
     * @return AndroidPlatform
     * @throws \Exception
     */
    public function getTargetSdk()
    {
        if ($this->getTargetSdkLevel()) return new AndroidPlatform($this->getTargetSdkLevel());
        return null;
    }

    /**
     * The target API Level required for the application to run.
     * @return float|int
     * @throws Exceptions\XmlParserException
     */
    public function getTargetSdkLevel()
    {
        $xmlObj = $this->getXmlObject();
        if (!isset($xmlObj->{'uses-sdk'})) return null;
        $usesSdk = get_object_vars($xmlObj->{'uses-sdk'});
        if (!isset($usesSdk['@attributes']['targetSdkVersion'])) return null;
        if (hexdec($usesSdk['@attributes']['targetSdkVersion'])) return hexdec($usesSdk['@attributes']['targetSdkVersion']);
        return null;
    }

    /**
     * get SimleXmlElement created from AndroidManifest.xml
     *
     * @param mixed $className
     * @return ManifestXmlElement|\SimpleXMLElement
     * @throws Exceptions\XmlParserException
     */
    public function getXmlObject($className = '\ApkParser\ManifestXmlElement')
    {
        return $this->xmlParser->getXmlObject($className);
    }

    /**
     * Basically string casting method.
     * @throws \Exception
     */
    public function __toString()
    {
        return $this->getXmlString();
    }
}