# ADR-0014: Strict stack — RAG, agent tools and the provider manager live in the Node service (TypeScript); no Python

- **Status:** Accepted — implements PLATFORM.md §0.10 (decision 2026-10-22); amends ADR-0010 (who holds the `ai` keys)
- **Date:** 2026-10-22

## Context

The user fixed the stack per product. PayFlow Guard = **Laravel + Vue SaaS** (`apps/web`), **Telegram bot with RAG**, **Node.js +
BullMQ/Redis**, **Playwright** — and **no FastAPI/Python service**. The foundation scaffold still contains `apps/ai` (FastAPI) from
before the decision; revision 2 of this plan assigned an `ai-domain` package to it (Python tools, pytest). PLATFORM §0.10 requires the
RAG to be implemented in TypeScript inside the Node service with the **same `/v1` HTTP contract** (PLATFORM §4.4), porting the TBO
provider manager and implementing §4.5–4.6 in TypeScript.

## Decision

1. **Location.** `apps/worker/src/rag/**` (foundation): `http/` (Fastify routes `/v1/chat`, `/v1/search`, `/v1/documents/*`,
   `/v1/workspaces/:id/reembed`, `/v1/admin/ai/*`), `ingest/` (PDF via `pdfjs-dist`, DOCX via `mammoth`, HTML/MD/TXT, OCR through the
   system Tesseract CLI `rus+eng`), `retrieval/` (pgvector HNSW cosine + Postgres FTS `simple`/`russian`/`english` + `unaccent`, RRF,
   role filter, confidence gate), `providers/` (TBO port: catalog, OpenAI-compatible client, Anthropic SDK, `mock`, fallback/retry rules,
   usage log to `rag.ai_usage`), `embeddings/` (`hash` 384-d for dev/tests, `openai-compatible`, `local` via `@huggingface/transformers`
   `multilingual-e5-small` with fallback to `hash`), `agent/` (loop ≤ 4 tool iterations, conversation memory in `rag.conversations`),
   `tools/` (registry + `search_documents`). DB access via `pg`; schema `rag` migrated by the worker (`node-pg-migrate`).
2. **Domain extension = TypeScript plugin, same contract as revision 2's Python design.** `ToolDefinition {name, description,
   parameters (JSON Schema), requiredPermission, dataTool, run(args, ctx)}`; `IntentRule {name, patterns, tools: [toolName, argsBuilder][],
   refusal?, requiresEntity?}`; `registerAnswerRenderer()`; explicit registration in `src/rag/tools/index.ts` (`buildRegistry()`) and
   `src/rag/agent/intents.ts`. PayFlow code lives in `apps/worker/src/rag/domain/payflow/**` (DOMAIN_CONTRACT G, K.4 F7). Tools call
   web's read-only F.3 endpoints; they never write.
3. **Process roles.** One package and image, two roles selected by `WORKER_ROLES`:
   `jobs,bot,ingress` (queues, schedulers, outbox relay, grammY bot, webhook ingress — port 8101, public ingress) and `rag`
   (`/v1/*` + document ingestion — port 8102, internal network only). Dev/E2E run `WORKER_ROLES=all` in one process on 8101 with
   `AI_URL=http://127.0.0.1:8101` (PLATFORM §0.10); production compose runs the two roles as separate containers (`worker`, `rag`) and
   `AI_URL=http://rag:8102`. The service name on the wire stays **`ai`** (`X-Service: ai`, `config/internal.php` `services.ai`), so web's
   `AiClient`, caller groups and the shared vectors do not change.
4. **Untrusted parsing is sandboxed.** Ingestion parses uploads in a child process (`child_process.fork`, `execArgv
   --max-old-space-size=256`, timeout 60 s per document, empty environment — no internal keys, DB URL or provider keys) that receives
   bytes on stdin and returns text/pages as JSON. A parser exploit therefore gains neither a key nor DB access.
5. **Keys (amends ADR-0010).** Pair keys stay `INTERNAL_KEY_WEB_WORKER`, `INTERNAL_KEY_WEB_AI`, `INTERNAL_KEY_WORKER_AI`. In production
   the `worker` container holds `WEB_WORKER` + `WORKER_AI`; the `rag` container holds `WEB_AI` + `WORKER_AI` (verify only). With
   `WORKER_ROLES=all` (dev only) one process holds all three; boot refuses `all` when `NODE_ENV=production`.
6. **Tests.** Vitest replaces pytest for every AI item (tools, intents RU/EN incl. negative vectors, headline answers under `mock` and an
   LLM fake, refusals, redaction, provider manager retry/fallback, RRF and confidence gate). `uv`, `ruff`, `mypy` leave the PayFlow CI.
7. **Removal of `apps/ai`.** The foundation step that ports the RAG deletes `apps/ai` and its compose/CI entries after the `/v1`
   contract tests pass against the Node implementation (shared fixtures `contracts/ai/*.json`). PayFlow domain work never targets `apps/ai`.

## Consequences

- One runtime less (no Python/uv in PayFlow), shared TS types between bot and RAG, one test runner.
- Local embeddings in Node are slower to warm up; dev/tests use `EMBEDDINGS_PROVIDER=hash` (already the rule).
- The trust boundary of ADR-0010 is preserved by the role split + parser sandbox rather than by a separate language runtime.

## Alternatives considered

- **Keep FastAPI** — rejected: violates PLATFORM §0.10.
- **RAG inside Laravel (PHP)** — rejected: the stack puts the bot and RAG in the Node service; PHP here lacks `intl`/`sodium`.
- **Single process in production** — rejected: a compromised RAG/ingest path would hold the worker key and could sign
  money-relevant bot decisions.
