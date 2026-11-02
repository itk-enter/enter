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
services that answer a question about one coordinate. Examples are "the nearest address" and "the newest aerial photo
of this point". These services do not give a data set to import. They give one answer for each request.

Remote services have limits that data sets in the broker do not have:

- A service can allow only a number of requests in a period, and each service has a different limit.
- A service can be unavailable for some time.
- Each request takes time, so a run that asks about many entities can be slow.

The answer of a service can also change. A service that has no answer for a location today can have one later, for
example when a new photo is taken. A value that a service gave can become old, for example when an address changes.

A merge publishes each merged entity complete, and it deletes the entities that the run does not make (004). Thus, if
a run does not publish a value that a service gave before, the value is lost.

This ADR serves to decide how details from remote services reach merged entities, and how they stay current when a
service is slow, limited or unavailable.

### Drivers

#### Functional

- A merge can add some details from a service, without a data model for all of the data of the service.
- One service can give more than one detail from one request.
- A detail without a value is checked again after a period, because the service can get a value later.
- A detail with a value is checked again after a longer period, so that the value stays current.
- A merged entity keeps the value that it had, when the service is unavailable.
- Each value tells where it came from and when it was checked.

#### Non-functional

- The merge obeys the request limit of each service. Two merges that use one service share its limit.
- A slow or unavailable service does not stop or slow down the merge run.
- A failed request is retried, and a request that fails after all retries is logged for the service.
- The solution adds no database next to the broker. It uses the message broker that is the default in our projects.

### Options Considered

**Lookups in the merge run.** The pipeline asks the services about the location of each merged entity during the run,
after the records augment the entities. The values are available when the run publishes. But the run waits for each
request, so a run on many entities is slow. To stay inside the request limit, the run must stop after a number of
requests, and the other entities get their values on a later run. Each failure and each retry also make the run
slower.

**Lookups through a message queue, after the run.** The run publishes the merged entities without a request to a
service. Then it sends a message for each detail that must be checked. A worker takes the messages from a queue,
asks the service and updates the details on the merged entity in the broker. The run does not wait for a service,
and the queue obeys the request limit of each service. But the values from a service arrive after the run, and the
application needs a message broker and a worker process.

**A data set for each service.** A separate process asks the service about the location of each merged entity, and
imports the answers as a data set of their own. The merge then reads that data set as an augmenting data model, with
no change to augmentation. But each service needs a data model, a source and an import. The answers are about merged
entities, so the process must run between two runs of the merge, and a new entity gets its value only on the second
run. The broker also holds a copy of each answer as an entity of its own.

**Lookups at import, in each source.** Each source asks the services about each of its records during the import.
This needs no change to conflation. But a merged thing that several data sets describe causes a request for each
record. The request limits then apply to each import, and each source must know about the services.

**Leave it to the consumers.** Each consumer asks the services itself. This needs no work here, but each consumer must
know which services to use, and the merged data set is not the single picture that 003 aims for.

## Decision

Remote services supply details through a message queue, after the merge run. A service is a class that answers a
question about one coordinate. It declares the details that it can supply, how often it can be asked and when its
answers must be checked again. A merge names the services that it uses, and the details that it takes from each.

For example, a service gives an aerial photo and the date of the photo. A merge of toilets takes both details. The
configuration below is illustrative. The implementation settles its shape.

```php
// The service declares its id, its details, its limits and its periods.
#[AsLocationLookup(
    id: 'aerial-photos',
    attributes: ['image', 'imageDate'],
    limiter: 'photo_service',   // a rate limiter that the services of one account share
    recheckEmptyAfter: 'P7D',   // a detail without a value is checked again after 7 days
    refreshAfter: 'P90D',       // a detail with a value is checked again after 90 days
)]
final class NearestAerialPhoto implements LocationLookupInterface { /* … */ }

// The merge takes the details that it needs.
#[Conflation(
    id: 'merged-toilets',
    // …
    lookups: [NearestAerialPhoto::class => ['image', 'imageDate']],
)]
```

### The merge run

The run does all the steps of 004 without a request to a service. Before it publishes, it copies the details from
services, and the time of each check, from the entity that it published before. It copies them only if the entity has
the same identifier and the same location. Thus, a run does not remove a value that a service gave.

After the run publishes, it sends a message for each merged entity and service that is due for a check:

| Detail from the service | Time of the last check           | Message |
|-------------------------|----------------------------------|---------|
| No check yet            | None                             | Yes     |
| No value                | Older than `recheckEmptyAfter`   | Yes     |
| No value                | Younger than `recheckEmptyAfter` | No      |
| Value                   | Older than `refreshAfter`        | Yes     |
| Value                   | Younger than `refreshAfter`      | No      |

A service supplies only the details that the records of the thing do not give. If the records give a detail, the run
sends no message for it. This keeps the rule of 007 that augmentation never overwrites a value of the records.

### The worker

Each service has a queue of its own in the message broker. A worker takes a message, asks the service and updates
only the details of that service, and the time of the check, on the merged entity in the broker. If the entity does
not exist any more, or it has moved, the worker ignores the message.

A service can give one of three results:

- **Values.** The worker writes the values and the time of the check.
- **No answer for the location.** For example, no photo shows the point. The worker removes the details of the service
  from the entity and writes the time of the check. The run sends a new message after `recheckEmptyAfter`.
- **A failure.** For example, the service is unavailable. The queue retries the message after a delay. If all the
  retries fail, the worker logs the failure on the log channel of the service and drops the message. The entity keeps
  its values and the time of the last good check, so the next run sends a new message.

The rate limiter of the service controls how fast the worker takes messages from the queue. Thus, a long queue is
slow, but it obeys the limit of the service.

The merged entity stores the source and the time of each check. For example, one photo exists and one does not:

| Merged entity | image               | imageDate  | Service         | Time of the check    |
|---------------|---------------------|------------|-----------------|----------------------|
| T-1           | `…/photos/P-41.jpg` | 2023-04-20 | `aerial-photos` | 2026-11-02T10:15:00Z |
| T-2           |                     |            | `aerial-photos` | 2026-11-02T10:15:04Z |

On 2026-11-09, the run sends a message for T-2 again. It sends a message for T-1 on 2027-01-31.

The rationale is:

- The run does not wait for a service, so a slow, limited or unavailable service does not change the time of a run.
- The queue keeps the messages until the service can answer, and it retries a failure without new code.
- Two periods for each service keep values current, and find new values, without a request on each run.
- A service needs no data model, no source and no import.
- The broker already holds the merged entities, so the answers and the times of the checks need no database.
- A failure of a service changes no published value.
- The limits belong to the service, so they apply to all the merges that use it.
- A log channel for each service makes it easy to find the failures of one service.

## Consequences

### Positive

- A merge can take some details from a service, without a data model for the service.
- A new merged entity gets its values from a service soon after the run, not on the next run.
- The time of a merge run does not depend on the services.
- A run sends messages only for checks that are due. Most runs send few messages.
- A failure of a service does not stop the run, and it does not remove values that the entities had.
- Consumers can see the source of each value from a service, and when it was checked.

### Negative / Trade-offs

- The application needs a message broker and a worker process, in development and on the server.
- After a run, a new merged entity has no values from a service until the worker answers its message.
- If many entities are due at one time, the queue can take a long time to empty, because of the rate limiter.
- A run that starts before the worker writes an answer publishes the old values, because it copies them at its start.
  The worker writes the answer again after the next due check.
- If a run starts before the queue is empty, it can send a second message for one check. The worker must ignore a
  message for a check that is not due any more.
- A merged entity that gets a new identifier, or that moves, gets new answers, because the answers are on the entity.
- Two merges that ask about the same location each make a request, because each merged entity stores its own answers.
- If the broker loses the merged entities, all the answers are requested again.
- A value can be up to `refreshAfter` old, so a change at the service reaches the merged entity late.
- A failure after all the retries is only logged. Somebody must read the log channel of the service to find it.
- The worker writes to the merged entities outside of the merge run, so the run is not the only process that changes
  them. The worker changes only the details that the merge names for the service.
