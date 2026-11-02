# 008: Conflation - remote lookups in the augmentation step

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

A merge publishes each merged entity complete, and it deletes the entities that the run does not make (004). Thus, if
a service is unavailable during a run and the run publishes no value, the value that the entity had before is lost.

This ADR serves to decide how details from remote services reach merged entities, and how the merge behaves when a
service is slow, limited or unavailable.

### Drivers

#### Functional

- A merge can add some details from a service, without a data model for all of the data of the service.
- One service can give more than one detail from one request.
- A merged entity keeps the value that it had, when the service is unavailable.
- Each value tells where it came from, as the values from other data sets do (007).

#### Non-functional

- The merge obeys the request limit of each service. Two merges that use one service share its limit.
- A failure of a service does not stop the run.
- The solution adds no database next to the broker.

### Options Considered

**Lookups in the augmentation step.** The merge names the services and the details that it takes from each. After
the records augment the entities, the pipeline asks the services about the location of each merged entity. The values
go through the same limits and conflict rules as other augmenting values. The merge stays the only place that decides
which details a merged entity gets. Each service needs code that controls the rate of requests, and that stores the
answers, so the merge does not ask again on each run.

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

Remote services supply details in the augmentation step. A service is a class that answers a question about one
coordinate. It declares what it can supply and how often it can be asked. A merge names the services that it uses, and
the details that it takes from each.

For example, a service gives an aerial photo and the date of the photo. A merge of toilets takes both details. The
configuration below is illustrative. The implementation settles its shape.

```php
// The service declares its id, its details and its limits.
#[AsLocationLookup(
    id: 'aerial-photos',
    attributes: ['image', 'imageDate'],
    limiter: 'photo_service',   // a rate limiter that the services of one account share
    maxCallsPerRun: 500,        // the other entities get their value on a later run
    maxAge: 'P90D',             // a stored answer is used for 90 days
)]
final class NearestAerialPhoto implements LocationLookupInterface { /* … */ }

// The merge takes the details that it needs.
#[Conflation(
    id: 'merged-toilets',
    // …
    lookups: [NearestAerialPhoto::class => ['image', 'imageDate']],
)]
```

The rules of 007 apply to the values from a service:

- A service adds only the details that the merge names, and only to merged entities that do not have them.
- When a data set and a service give different values for one detail, the rules from 006 settle the conflict.
- The address of the answer at the service is added to the list of records that the merged entity was built from.

The merged entity stores the time of each answer, for example:

| Merged entity | image                  | imageDate  | Answer from     | Time of the answer   |
|---------------|------------------------|------------|-----------------|----------------------|
| T-1           | `…/photos/P-41.jpg`    | 2023-04-20 | `aerial-photos` | 2026-11-02T10:15:00Z |

On the next run, the merge reads the entities that it published before. It uses an answer again if the answer is
younger than the `maxAge` of the service, and if the entity has the same location. Otherwise, it asks the service.

A service can give one of three results:

- **Values.** The merge uses and stores them.
- **No answer for the location.** For example, no photo shows the point. The merge stores this result too, and asks
  again only after `maxAge`.
- **A failure.** For example, the service is unavailable, or the limit stops the request. The merge keeps the stored
  answer. If there is no stored answer, the entity does not get the detail in this run. The merge logs a warning and
  continues.

The rationale is:

- The merge stays the only place that decides the details of a merged entity, and the limits of 007 apply to all of
  them.
- A service needs no data model, no source and no import.
- The broker already holds the merged entities, so the stored answers need no database.
- A failure of a service changes no published value, because the merge keeps the stored answer.
- The limits belong to the service, so they apply to all the merges that use it.

## Consequences

### Positive

- A merge can take some details from a service, without a data model for the service.
- A run makes requests only for answers that are missing or too old. Most runs make few requests.
- A failure of a service does not stop the run, and it does not remove values that the entities had.
- Consumers can see the age and the origin of each value from a service.

### Negative / Trade-offs

- The first run on many entities can take several runs to give all the values, because of `maxCallsPerRun`.
- A merged entity that gets a new identifier, or that moves, gets new answers, because the stored answers are on the
  entity.
- Two merges that ask about the same location each make a request, because each merged entity stores its own answers.
- If the broker loses the merged entities, all the answers are requested again.
- An answer can be up to `maxAge` old, so a change at the service reaches the merged entity late.
- The rate limiter adds a dependency, and it keeps its state in the cache of the application.
- Augmentation now has two kinds of input, data sets in the broker and services. Both must obey the limits of 007.
