# Configure automatic Session title generation on Conversation

Automatic title generation is enabled by configuring a SessionTitleGenerator on
Conversation through setSessionTitleGenerator(), just as input history is enabled
through setInputHistory(). Conversation owns message turn execution; configuring it once also preserves the
behavior when the selected Session changes, without configuring each created or
reopened Session.

SessionTitleGenerator receives the provider at construction and the Session when
generate() is called. It reads saved user messages plus the pending user message, preserves existing titles, and
persists the generated title and attempt count through Session. Session retains
responsibility for saved data without holding the generator configuration.

Generation runs synchronously before the Agent response starts, avoiding an extra
wait after its last chunk. The title agent decides whether a concrete subject has
emerged. Generation is limited to five attempts per Session by default, counting
no-topic results and failed requests. Title generation failures must not prevent the Agent response from executing. The Host
Application continues to coordinate concurrent operations on the same Session.
