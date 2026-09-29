# Scorpio

We use the [Scorpio NGSI-LD Broker](https://github.com/ScorpioBroker/ScorpioBroker).

All API requests to Scorpio are proxied by nginx, i.e. there is *no direct access to Scorpio* from the outside. We
expose the `/ngsi-ld/v1/types` and `/ngsi-ld/v1/entities` endpoints only and allow anonymous `GET` (and `HEAD`)
requests. Any other method requires ["Basic" HTTP
authentication](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Authentication#basic_authentication_scheme).

See Scorpio's [API Walkthrough](https://github.com/ScorpioBroker/ScorpioBroker#api-walkthrough) for examples on how to
use the NGSI-LD API implemented by Scorpio.
