# Planned ADRs

1. Conflation and identity
What happens during conflation needs to be decided and documented. Survivorship, referencing etc.

2. Lifecycle of records
Due to the upsert approach used in the broker, stale data has to be handled.
Data disappearing upstream could potentially affect conflation.
If data disappears upstream, does that actually mean that the location is gone/changed?
A record sorted into a model by what it holds changes model, and with it identifier, when its
content changes kind. Each import now deletes the entities of its source that it no longer yields,
which removes the entity under the old identifier; this settles the duplicate, not the questions above.
