# Planned ADRs

1. Conflation and identity
What happens during conflation needs to be decided and documented. Survivorship, referencing etc.

2. Lifecycle of records
Due to the upsert approach used in the broker, stale data has to be handled.
Data disappearing upstream could potentially affect conflation.
If data disappears upstream, does that actually mean that the location is gone/changed?
