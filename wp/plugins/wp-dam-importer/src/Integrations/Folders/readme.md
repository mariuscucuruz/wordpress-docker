# Folders

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Total Downloads][ico-downloads]][link-downloads]
[![Build Status][ico-travis]][link-travis]
[![StyleCI][ico-styleci]][link-styleci]

This is where your description should go. Take a look at [contributing.md](contributing.md) to see a to do list.

## Installation

Add via Composer

``` bash
$ composer require clickonmedia/folders
```

Setup autoload in composer.json for namespace

```
MariusCucuruz\DAMImporter\Integrations\\Folders
```

Publish the seeder

```
php artisan vendor:publish --tag=album-seeds
```

## Usage

### Migration and seeding

```
php artisan migrate

php artisan db:seed

php artisan db:seed --class=ExampleSeeder

php artisan db:seed --class=AlbumSeeder
```

## Change log

Please see the [changelog](changelog.md) for more information on what has changed recently.

## Testing

``` bash
$ composer test
```

## Contributing

Please see [contributing.md](contributing.md) for details and a todolist.

## Security

If you discover any security related issues, please email technical.services@medialake.ai instead of using the issue tracker.

## Credits

- [clickonmedia][link-author]
- [All Contributors][link-contributors]

## License

MIT. Please see the [license file](license.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/clickonmedia/folders.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/clickonmedia/folders.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/clickonmedia/folders/master.svg?style=flat-square
[ico-styleci]: https://styleci.io/repos/12345678/shield

[link-packagist]: https://packagist.org/packages/clickonmedia/folders
[link-downloads]: https://packagist.org/packages/clickonmedia/folders
[link-travis]: https://travis-ci.org/clickonmedia/folders
[link-styleci]: https://styleci.io/repos/12345678
[link-author]: https://github.com/clickonmedia
[link-contributors]: ../../contributors
