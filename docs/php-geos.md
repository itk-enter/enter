# PHP module for GEOS

We want the use "[Advanced
Methods](https://geophp.net/api.html#user-content-methods-2:~:text=String-,Advanced%20Methods,-The%20GEOS%2Dphp)" in
[GeoPHP](https://geophp.net/) which require [the GEOS PHP extension](https://geophp.net/geos.html). Therefore we build a
custom phpfpm image, `enter-phpfpm:latest`, with [PHP module for GEOS](https://github.com/libgeos/php-geos) enabled (see
[docker-compose.override.yml](../docker-compose.override.yml) for details).

The image is built when running `task site:update`, but during image development the following
incantations may come in handy.

Build the image:

```shell name=php-geos-build
BUILDKIT_PROGRESS=plain docker compose build --no-cache
```

Check the image:

```shell name=php-geos-check
docker image ls enter-phpfpm:latest
docker compose run --rm phpfpm php -v
docker compose run --rm phpfpm php -m | grep -i geos
```

Test that the GEOS module is loaded and works in PHP:

```shell name=php-geos-test
docker compose exec phpfpm php bin/phpunit tests/Unit/GeosTest.php
```
