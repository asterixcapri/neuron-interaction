# Store Session listing information with its history

A Session will retain one storage document containing its messages, ownership and
application metadata. Information needed to build SessionSummary objects will be
maintained in that same document when the history changes, so listing does not
need to reconstruct message objects or reread the Session for its title.

This keeps the generic StorageInterface and avoids coordinating publication and
cleanup across separate Session record and history documents. With
the current FileStorage format, listing still reads and decodes complete documents.
Concurrent operations on the same Session remain coordinated by the Host Application.
The exact derived fields and update rules remain to be decided.

The storage redesign targets newly created Sessions. Existing documents will not
be migrated or automatically backfilled, because those conversations are not
required by the Host Application.

The listing optimization is not implemented yet: SessionStore currently loads
messages to check for user content and rereads Session metadata for the title.
Keeping one document is already consistent with this decision; maintaining the
derived listing information remains future work.
