---
name: Four types of "unknown" in npm run status
description: The 4 locations where "unknown" categorized items appear in the 333Method status report, all handled by the classifyUnknownErrors cron job
type: feedback
---

When the user mentions "unknowns" in `npm run status`, there are exactly 4 places they appear:

1. **Pipeline > ignore > Unknown** — sites with status `ignore` whose error_message doesn't match any terminal/retriable pattern
2. **Pipeline > failing > Unknown** — sites with status `failing` whose error_message doesn't match any pattern
3. **Outreach > failed > Unknown** — outbound messages with delivery_status `failed` whose error doesn't match outreach patterns
4. **Outreach > retry > Unknown** — outbound messages with delivery_status `retry_later` whose error doesn't match outreach patterns

All four are supposed to be categorized by the `classifyUnknownErrors` cron job (ID 61, runs every 4h). Don't ask the user to list these out — reference this memory.
