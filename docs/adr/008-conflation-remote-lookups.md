# 008: Conflation - remote lookups through a message queue

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath                                     |
| **Date**           | 2026-11-02                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

Augmentation (007) adds details to merged entities from other data sets in the broker. Some details come from remote
services that answer a question about one coordinate, for example "the nearest address" or "the newest aerial photo
of this point". These services give one answer for each request, not a data set to import.

A service can allow only a number of requests in a period, it can be unavailable for some time, and each request takes
time. If the merge run asks the services, a run on many entities is slow, and a failure of a service makes it slower.
The answers can also change, so a value must be checked again after some time.

This ADR serves to decide how details from remote services reach merged entities and stay current.

## Decision

Remote services supply details through a message queue, after the merge run. A merge names the services that it uses
and the details that it takes from each. Each service declares its request limit and two periods: one to check again
a detail without a value, and a longer one to check again a detail with a value.

After the run publishes, it sends a message for each merged entity and service that is due for a check. A worker takes
the message, asks the service and writes the result to the merged entity in the broker. Each service has a queue of
its own in RabbitMQ, and its request limit controls how fast the worker takes messages. A lookup that makes no slow or
limited request, for example one that builds a link from the location, does not use the queue. The run asks it before
it publishes.

The merged entity stores the time of each check in a property of the service. The worker always makes a partial
update of the details of the service and this property, and never writes the full entity. The run keeps these values
from the entity that it published before, if the entity has the same identifier and location.

When the run sends a message, it marks the check as requested in the same property. The run does not send the check
again while the mark is younger than the request timeout of the service. Thus a long queue gets no second message for
one check. The answer of the worker removes the mark.

The rules of 007 apply. A service only fills details that the records of the thing do not give. A service also never
removes a value, because an empty answer can come from a service that is down.

If a request fails after the retries, the worker logs the failure on the log channel of the service. The application
does not store the failed message. The worker writes nothing, so the run sends the check again when the mark expires.

For example, a merge of toilets takes an aerial photo from a service. The service checks again after 7 days when it
has no photo, and after 90 days when it has one:

| Merged entity | image               | Result of the check | Time of the check    | Next check |
|---------------|---------------------|---------------------|----------------------|------------|
| T-1           | `…/photos/P-41.jpg` | found               | 2026-11-02T10:15:00Z | 2027-01-31 |
| T-2           |                     | none                | 2026-11-02T10:15:04Z | 2026-11-09 |

If the check of T-1 on 2027-01-31 gives no answer, T-1 keeps its photo.

## Consequences

### Positive

- A merge can take details from a service, without a data model, a source or an import for the service.
- The time of a merge run does not depend on the services, and a failure of a service does not stop the run.
- A failure or an empty answer of a service does not remove a published value.
- The answers and the times of the checks are on the merged entities, so they need no database.
- Consumers can see the source of each value from a service, and when it was checked.

### Negative / Trade-offs

- The application needs RabbitMQ and a worker process, in development and on the server.
- A new merged entity gets its values from a service after the run, when the worker answers the message.
- If many checks are due at one time, the queue takes a long time to empty, because of the request limit.
- A value can be up to the longer period old. A value that the service removes stays on the merged entity.
- A merged entity that gets a new identifier or moves gets new answers, because the answers are on the entity.
- A failed check waits until its mark expires, and somebody must read the log channel of the service to find it.
- The worker changes the merged entities outside of the merge run.
- Each merged entity gets one property for each service, which the data model does not define.
