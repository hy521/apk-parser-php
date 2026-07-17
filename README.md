# APK Parser

[![CI](https://github.com/evozi/apk-parser-php/actions/workflows/ci.yml/badge.svg)](https://github.com/evozi/apk-parser-php/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/evozi/apk-parser-php/version.svg)](https://packagist.org/packages/evozi/apk-parser-php)
[![Total Downloads](https://poser.pugx.org/evozi/apk-parser-php/downloads.svg)](https://packagist.org/packages/evozi/apk-parser-php)

A pure-PHP library for reading Android application packages (`.apk` files). It parses the binary `AndroidManifest.xml` and resource table without any external tools, giving you access to the package name, version, SDK levels, permissions, activities, and more. It can also list every class defined in the app's dex files (multidex supported) using a native PHP dex parser — no Java required — and extract the APK's contents to a directory.

## Requirements

- PHP 8.1+ (tested on PHP 8.1 – 8.5)
- Extensions: `zip`, `simplexml`, `libxml`, `json`, `mbstring`

## Installation

```bash
composer require evozi/apk-parser-php
```

## Usage

### Reading basic information

```php
<?php

require 'vendor/autoload.php';

$apk = new \ApkParser\Parser('MyApp.apk');
$manifest = $apk->getManifest();

echo $manifest->getPackageName();     // com.example.app
echo $manifest->getVersionName();     // 1.2.3
echo $manifest->getVersionCode();     // 10203
echo $manifest->getMinSdkLevel();     // 21
echo $manifest->getTargetSdkLevel();  // 34
echo $manifest->getMinSdk()->platform;    // e.g. "5.0 Lollipop"
var_dump($manifest->isDebuggable());  // false
```

### Permissions

`getPermissions()` returns each permission with its description and cost/warning/danger flags:

```php
foreach ($manifest->getPermissions() as $permission => $detail) {
    echo $permission . ': ' . $detail['description'] . PHP_EOL;

    if ($detail['flags']['danger']) {
        echo "  (dangerous permission)" . PHP_EOL;
    }
}

// Or just the raw android permission names:
foreach ($manifest->getPermissionsRaw() as $permission) {
    echo $permission . PHP_EOL; // e.g. android.permission.INTERNET
}
```

### Activities and intent filters

```php
foreach ($manifest->getApplication()->activities as $activity) {
    echo $activity->name . ($activity->isLauncher ? ' (Launcher)' : '') . PHP_EOL;
}
```

### Listing classes from the dex files

Classes are read directly from `classes.dex` (and any `classes2.dex`, `classes3.dex`, … for multidex apps) with the built-in PHP dex parser:

```php
foreach ($apk->getClasses() as $className) {
    echo $className . PHP_EOL; // e.g. com.example.app.MainActivity
}
```

### Resources

```php
// Resolve resource references from the manifest, e.g. the app label and icon
$label = $apk->getResources($manifest->getApplication()->getLabel());
echo $label[0];

$icons = $apk->getResources($manifest->getApplication()->getIcon());
foreach ($icons as $icon) {
    file_put_contents(basename($icon), stream_get_contents($apk->getStream($icon)));
}

// Resources can also be looked up by symbolic name instead of hex id —
// "string/app_name" and "@string/app_name" both work:
$appName = $apk->getResources('@string/app_name');

// Or fetch the entire resource table at once, indexed by hex resource id:
$all = $apk->getAllResources();
```

### Extracting the APK contents

```php
$apk->extractTo('/path/to/output');            // everything
$apk->extractTo('/path/to/output', $entries);  // or only specific entries
```

### Raw manifest XML

```php
echo $manifest->getXmlString(); // decoded, human-readable AndroidManifest.xml
```

### Configuration

If you only need the manifest, skip parsing the resource table for a faster start-up:

```php
$apk = new \ApkParser\Parser('MyApp.apk', ['manifest_only' => true]);
```

More runnable examples are available in the [`examples/`](./examples) directory.

## Testing

Tests are powered by PHPUnit and run on every push via GitHub Actions:

```bash
composer install
composer test
```

Or run everything inside Docker without a local PHP installation:

```bash
make docker-build    # build the PHP image
make docker-install  # composer install inside the container
make docker-check    # php -l + the full test suite
```

## Credits

Originally based on [tufanbarisyildirim/php-apk-parser](https://github.com/tufanbarisyildirim/php-apk-parser) by Tufan Baris Yildirim, maintained by [Evozi](https://www.evozi.com).

## License

APK Parser is [MIT licensed](./LICENSE.md).
