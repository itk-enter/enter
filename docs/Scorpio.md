# Scorpio

We use the [Scorpio NGSI-LD Broker](https://github.com/ScorpioBroker/ScorpioBroker).

All API requests to Scorpio are proxied by nginx, i.e. there is *no direct access to Scorpio* from the outside. We
expose the `/ngsi-ld/v1/types` and `/ngsi-ld/v1/entities` endpoints only and allow anonymous `GET` (and `HEAD`)
requests. Any other method requires ["Basic" HTTP
authentication](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Authentication#basic_authentication_scheme).

See Scorpio's [API Walkthrough](https://github.com/ScorpioBroker/ScorpioBroker#api-walkthrough) for examples on how to
use the NGSI-LD API implemented by Scorpio.

## History

Scorpio does not record the history of the entities. The `scorpio` service sets
`SCORPIO_HISTORY_AUTORECORDING=false`, so Scorpio adds nothing to the temporal API (`/ngsi-ld/v1/temporal/`).

The variable sets the property `scorpio.history.autorecording`, which is `true` in Scorpio's
[`application.properties`][scorpio-properties]. If the property is `false`, the [history manager][scorpio-history]
records nothing. Scorpio is a Quarkus application, and Quarkus maps an environment variable to a property: it changes
each `.` to `_` and each letter to upper case ([Quarkus: Environment variables][quarkus-env]).

When the recording is on, each upsert adds a copy of each attribute, also when the value did not change. Scorpio has
no setting that skips the copies or deletes old ones. The app keeps its own history of the imports in the database
instead.

[scorpio-properties]: https://github.com/ScorpioBroker/ScorpioBroker/blob/ca019cf5f13654aa5723462369b195bcc3ac30c2/AllInOneRunner/src/main/resources/application.properties#L29
[scorpio-history]: https://github.com/ScorpioBroker/ScorpioBroker/blob/ca019cf5f13654aa5723462369b195bcc3ac30c2/HistoryEntityManager/src/main/java/eu/neclab/ngsildbroker/historyentitymanager/messaging/HistoryMessagingBase.java#L107
[quarkus-env]: https://quarkus.io/guides/config-reference#environment-variables
