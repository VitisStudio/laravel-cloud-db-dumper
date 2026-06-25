# This is my package laravel-cloud-db-dumper

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vitisstudio/laravel-cloud-db-dumper.svg?style=flat-square)](https://packagist.org/packages/vitisstudio/laravel-cloud-db-dumper)
[![GitHub Tests Action Status](https://github.com/spatie/package-laravel-cloud-db-dumper-laravel/actions/workflows/run-tests.yml/badge.svg)](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/spatie/package-laravel-cloud-db-dumper-laravel/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/vitisstudio/laravel-cloud-db-dumper.svg?style=flat-square)](https://packagist.org/packages/vitisstudio/laravel-cloud-db-dumper)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/laravel-cloud-db-dumper.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/laravel-cloud-db-dumper)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require vitisstudio/laravel-cloud-db-dumper
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-cloud-db-dumper-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-cloud-db-dumper-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-cloud-db-dumper-views"
```

## Usage

```php
$laravelCloudDbDumper = new VitisStudio\LaravelCloudDbDumper();
echo $laravelCloudDbDumper->echoPhrase('Hello, VitisStudio!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Dan Poblete](https://github.com/acepoblete)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
