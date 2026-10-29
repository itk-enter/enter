# Scorpio

We use the [Scorpio NGSI-LD Broker](https://github.com/ScorpioBroker/ScorpioBroker).

All API requests to Scorpio are proxied by nginx, i.e. there is *no direct access to Scorpio* from the outside. We
expose the `/ngsi-ld/v1/types` and `/ngsi-ld/v1/entities` endpoints only and allow anonymous `GET` (and `HEAD`)
requests. Any other method requires ["Basic" HTTP
authentication](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Authentication#basic_authentication_scheme).

See Scorpio's [API Walkthrough](https://github.com/ScorpioBroker/ScorpioBroker#api-walkthrough) for examples on how to
use the NGSI-LD API implemented by Scorpio.

## Docker compose setup

We run Scorpio and its [PostGIS](https://postgis.net/) database using a compose setup based on
[docker-compose-java-aaio.yml](https://github.com/ScorpioBroker/ScorpioBroker/blob/development-quarkus/compose-files/docker-compose-java-aaio.yml)
from the [Scorpio NGSI-LD Broker repository](https://github.com/ScorpioBroker/ScorpioBroker).

For development, run

```shell
docker compose config scorpio
```

to see the actual compose config.

For production, run

```shell
docker compose --env-file .env.docker.local --file docker-compose.server.yml --file docker-compose.server.override.yml config scorpio
```

The development and production setups are basically identical, but the production containers have `unless-stopped` as
restart policy (cf. <https://docs.docker.com/reference/compose-file/services/#restart>) and the PostGIS database files
are stored in `.docker/data/scorpio/db` in the project root.
