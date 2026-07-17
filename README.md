# [APK Parser](https://github.com/evozi/apk-parser-php)
[![Latest Stable Version](https://poser.pugx.org/evozi/apk-parser-php/version.svg)](https://packagist.org/packages/evozi/apk-parser-php) [![Latest Unstable Version](https://poser.pugx.org/evozi/apk-parser-php/v/unstable.svg)](//packagist.org/packages/evozi/apk-parser-php) [![Total Downloads](https://poser.pugx.org/evozi/apk-parser-php/downloads.svg)](https://packagist.org/packages/evozi/apk-parser-php)

This package can extract application package files in APK format used by devices running on Android OS.
It can open an APK file and extract the contained manifest file to parse it and retrieve the meta-information
it contains like the application name, description, device feature access permission it requires, etc..
The class can also extract the whole files contained in the APK file to a given directory.

### Requirements

PHP 8.1+

# Installation

## Composer

### Install Composer

```bash
curl -sS https://getcomposer.org/installer | php
```

### Install via composer by hand

Create a composer.json into your project and add to your composer.json file by hand.

```javascript
{
    ...
    "require": {
        ...
        "evozi/apk-parser-php":"dev-master"
    }
    ...
}
```


Once you have added this, just run:

```bash
php composer.phar update evozi/apk-parser-php
```
or

```bash
composer install
```

### Install using composer

```bash
php composer.phar require evozi/apk-parser-php:dev-master
```


# Testing

Tests are powered by PHPUnit. Install dependencies (requires [Composer](https://getcomposer.org/download)),
then run the test suite:

```bash
composer install
composer test
```


# License

APK Parser is [MIT licensed](./LICENSE.md).