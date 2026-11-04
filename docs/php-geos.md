# PHP module for GEOS

We want to use "[Advanced
Methods](https://geophp.net/api.html#user-content-methods-2:~:text=String-,Advanced%20Methods,-The%20GEOS%2Dphp)" in
[GeoPHP](https://geophp.net/) which require [the GEOS PHP extension](https://geophp.net/geos.html). Therefore we build
custom phpfpm images, `enter-fpm:latest` (for development) and `enter-fpm:alpine` (for production), with [PHP module for
GEOS](https://github.com/libgeos/php-geos) enabled (see [docker-compose.override.yml](../docker-compose.override.yml)
for details).

The development images is built when running `task site:update`, but during image development the following incantations
come in handy.

Build both images:

```shell
BUILDKIT_PROGRESS=plain task image:phpfpm:build
```

Test, for both images, that the GEOS module is loaded and works in PHP:

```shell
task image:phpfpm:test
```
