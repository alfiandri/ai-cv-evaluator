# Laravel AI Evaluation Service (CV & Project Scoring)

This project implements a backend service that accepts a candidate’s CV and Project Report, compares them with a Job Vacancy and Study Case Brief, and returns a structured evaluation with:

- **CV ↔ Job match rate (0–1)**
- **Project score (1–10)** based on a standardized rubric
- **Actionable feedback & summary**

It demonstrates **Laravel fundamentals + AI workflow** (prompt design, LLM chaining, RAG-like retrieval, resilience, async pipeline).

---

## Features

### API
- **POST** `/api/auth/token` → issue token  
- **POST** `/api/upload` → upload CV & project report (`txt` / `pdf` / `docx`)  
- **POST** `/api/evaluate` → enqueue evaluation pipeline; returns `{ id, status: "queued" }`  
- **GET** `/api/result/{id}` → poll status: `queued | processing | completed | failed`  

### Runtime & Pipeline
- **Async Processing** via Laravel Queues (database driver by default)  
- **LLM Chaining** (OpenAI by default) with retry + backoff + validation  
- **Simple Vector Store** for RAG  
  - `documents` table stores content + embedding (JSON float array)  
  - Cosine similarity in PHP (fine for small corpus)  
- **Robust Text Extraction** from PDF (Smalot/pdfparser), DOCX (PhpOffice), TXT  
- **Determinism Controls**: temperature, schema validation & correction  
- **Failure Simulation**: randomly throw LLM errors to exercise retries  
- **Standardized Rubric** for CV & Project scoring  

---

## Quick Start

### 1) Requirements
- PHP 8.2+  
- Composer  
- SQLite/PostgreSQL/MySQL (SQLite easiest for demo)

### 2) Install
```bash
composer create-project laravel/laravel ai-eval
cd ai-eval

# Install packages
composer install
```

### 3) Environment
Create `.env` entries:

```env
APP_NAME="AI Eval"
APP_KEY=
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

QUEUE_CONNECTION=database

# DB (use SQLite for quick start)
DB_CONNECTION=sqlite
# touch database/database.sqlite

# LLM provider
LLM_PROVIDER=openai
LLM_MODEL=gpt-4o-mini
LLM_TEMPERATURE=0.2
OPENAI_API_KEY=sk-...

# Embeddings model
EMBEDDING_MODEL=text-embedding-3-small
```

Initialize DB & queue tables:

```bash
php artisan key:generate
php artisan migrate
php artisan queue:table && php artisan migrate
```

### 4) Run
```bash
php artisan serve  # http://127.0.0.1:8000
php artisan queue:work --tries=3
```

Use `php artisan make:command`  
to get a demo tenant and user, issue an API token, and (optionally) seed vector docs. Prints curl examples.

### 5) Try the APIs

**Upload files (CV + Project Report):**
```bash
curl -F "cv=@/path/cv.pdf" -F "project_report=@/path/report.docx"   http://127.0.0.1:8000/api/upload
# => {"cv_file_id":"...","project_file_id":"..."}
```

**Enqueue evaluation:**
```bash
curl -X POST http://127.0.0.1:8000/api/evaluate   -H "Content-Type: application/json"   -d '{
    "cv_file_id": "<uuid>",
    "project_file_id": "<uuid>",
    "job_description": "We seek a Backend Engineer experienced in Laravel, REST APIs, PostgreSQL, Docker, cloud (GCP/AWS), and exposure to LLMs/RAG.",
    "study_case_brief": "Build a small service evaluating CV & project via LLM chaining, with retrieval and retries."
  }'
# => {"id":"<uuid>","status":"queued"}
```

**Poll result:**
```bash
curl http://127.0.0.1:8000/api/result/<uuid>
# => {"id":"<uuid>","status":"processing"}
# or completed => includes result payload
```

---

## API Contracts

### `POST /api/auth/token`
**Body (JSON):**
```json
{
  "email": "email",
  "password": "password"
}
```

### `POST /api/upload`
**Form-Data**  
- `cv` (required): file (`txt` / `pdf` / `docx`)  
- `project_report` (required): file (`txt` / `pdf` / `docx`) 

**Headers**
- `Authorization`: Bearer Token
- `X-Tenant-ID`: Tenant ID

**Response:**
```json
{
  "cv_file_id": "f4c3...",
  "project_file_id": "a6f1..."
}
```

### `POST /api/evaluate`
**Body (JSON):**
```json
{
  "cv_file_id": "uuid",
  "project_file_id": "uuid",
  "job_description": "text...", 
  "study_case_brief": "text..."
}
```

**Headers**
- `Authorization`: Bearer Token
- `X-Tenant-ID`: Tenant ID

**Response:**
```json
{ "id": "uuid", "status": "queued" }
```

### `GET /api/result/{id}`
**Headers**
- `Authorization`: Bearer Token
- `X-Tenant-ID`: Tenant ID

**Response:**
```json
{ "id": "uuid", "status": "queued" }
```
**Or:**
```json
{ "id": "uuid", "status": "processing" }
```
**Or:**
```json
{
  "id": "uuid",
  "status": "completed",
  "result": {
    "cv_match_rate": 0.82,
    "cv_feedback": "Strong in backend and cloud, limited AI integration experience.",
    "project_score": 7.5,
    "project_feedback": "Meets prompt chaining requirements, lacks error handling robustness.",
    "overall_summary": "Good candidate fit, would benefit from deeper RAG knowledge."
  }
}
```
**Or failed:**
```json
{ "id": "uuid", "status": "failed", "error": "..." }
```

---

## Prompt Design (Concepts)

- **Extraction Prompt**: forces JSON schema + no commentary.  
- **Scoring Prompt**: includes rubric and JD via RAG retrieval; outputs per-criterion 1–5 + feedback.  
- **Refinement Prompt**: re-grades focusing on resilience (failures, retries, tests).  
- **Summary Prompt**: compresses findings to one actionable paragraph.  

We use `response_format: json_object` to nudge deterministic JSON, plus a fixer call when malformed.

---

## Resilience & Randomness Control

- Retries/Backoff in **RetryingLlmClient** (0.5s, 1.5s, 3.5s)  
- Timeouts & Queue Retries in job (`$tries`, `backoff()`)  
- Temperature via env (default `0.2`)  
- Validation: clamp scores to **1–5**, repair JSON if needed  

---

## Design Choices

- **Simple Vector DB**: using a DB table + JSON embeddings + cosine search in PHP. Given tiny corpus (JD, brief, rubric), this is simpler & reliable.  
- **Two-pass Project Scoring**: initial pass + refinement specifically emphasizing error handling.  
- **Normalization**: CV match rate computed from averaged 1–5 rubric → normalized to **0–1 (2 decimals)**.  
- **Extensibility**: swap LLM provider by implementing `LlmClientInterface` (e.g., Gemini/OpenRouter).  

---

## Notes

- For PDFs with heavy layout/scans, consider integrating OCR or `spatie/pdf-to-text` + **poppler**.  
- For larger corpora, replace VectorStore with **Qdrant/pgvector**.  
- Secure uploads & size limits in production.  

---

## Documentation — How it works & Trade-offs

This section explains the architecture, LLM/RAG design, auth & multi-tenancy, resilience controls, and the trade-offs taken in this project.

### A. Architecture Overview

**Core components**
- **API Layer (Laravel)** — `/upload`, `/evaluate`, `/result/{id}`.  
- **Queue Worker** — processes long-running evaluation (LLM + retrieval).  
- **Vector Store** — simple SQL table (`documents`) storing embeddings + metadata; cosine similarity in PHP.  
- **Storage** — raw files on disk; extracted text persisted in DB.  
- **LLM Client** — pluggable via `LlmClientInterface` (OpenAI or Gemini). *Gemini recommended.*

**High-level flow**
1. `POST /upload` → store files → extract text → return file UUIDs.  
2. `POST /evaluate` → persist evaluation row (queued) → dispatch job → return `{id, status:"queued"}`.  
3. Worker loads texts, upserts JD + study brief + rubric into vector store (and seeds rubric if missing).  
4. Worker runs LLM chain: **Extract CV structure → Score CV vs JD → Score project → Refine → Summarize**.  
5. Persist `final_result_json` → `GET /result/{id}` returns the outcome.

```
Client ──HTTP──> Laravel API ──DB(tx)──> evaluations (queued)
   │                         └─files→ storage; text_extracted
   └──poll /result/{id}        └─docs→ vector store (embeddings)
Queue Worker ─LLM/RAG──> Gemini/OpenAI
```

### B. API Usage & Headers

Protected endpoints (recommended): require **Bearer token** and **tenant** header.

```
Authorization: Bearer <token>
X-Tenant-ID: <tenant-uuid-or-slug>
```

**Endpoints:**  
- `POST /upload` (multipart form: `cv`, `project_report`)  
- `POST /evaluate` (JSON: `cv_file_id`, `project_file_id`, `job_description`, `study_case_brief`)  
- `GET /result/{id}` (polling)

### C. LLM Provider (Gemini recommended)

Use header-based auth (no `?key=`):

```
POST https://generativelanguage.googleapis.com/v1beta/models/{MODEL}:generateContent
x-goog-api-key: <GEMINI_API_KEY>
```

**Suggested models:**  
- `gemini-2.0-flash` (fast, widely available), or  
- `gemini-2.5-flash` (if available to your key)

**.env snippet:**
```env
LLM_PROVIDER=gemini
LLM_MODEL=gemini-2.0-flash
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
EMBEDDING_MODEL=text-embedding-004
```

If you see **404 model not found**, list models and pick one available to your key; try `v1` base if `v1beta` fails.  
**Deterministic JSON:** set `generationConfig.responseMimeType = application/json`. The pipeline falls back to a “JSON fixer” call if the first reply isn’t valid JSON.

### D. RAG Strategy (Simple Vector Store)

- Persist **Job Description**, **Study Case Brief**, **Scoring Rubric** in `documents` with an embedding vector.  
- During evaluation, embed the query text (e.g., “criteria for cv scoring”), compute cosine similarity, and select the most relevant content.  
- This is intentionally simple for a tiny corpus. For larger scale, swap to **pgvector/Qdrant**.

### E. Scoring & Normalization

- **CV criteria:** `technical_skills_match`, `experience_level`, `relevant_achievements`, `cultural_fit` (**1–5** each).  
- **Project criteria:** `correctness`, `code_quality`, `resilience`, `documentation`, `creativity` (**1–5** each).  
- **Normalization guards:** if an LLM reply has no scores, avoid division-by-zero and return **0.0** for the normalized score. Scores are **clamped to [1,5]** before aggregation.

### F. Resilience & Error Handling

- LLM call retries with exponential backoff (**0.5s, 1.5s, 3.5s**).  
- Job retries (`tries=3`, backoff **[5, 15, 45]**).  
- Timeouts on HTTP calls.  
- Validation: **clamp scores → normalize**; JSON fixer pass; safe defaults if RAG returns no documents.

### G. Authentication & Multi-Tenancy

- **Sanctum personal access tokens:** `POST /auth/token` → returns Bearer token.  
- **Tenant resolution middleware** reads `X-Tenant-ID` and scopes all queries by `tenant_id`.  
- Job workers **restore tenant context** before running retrieval/LLM calls.  
- Models use a **BelongsToTenant** trait to auto-fill `tenant_id` and apply a global scope.

### H. Security & Privacy

- Validate file types (`txt`, `pdf`, `docx`) and set size limits.  
- Avoid logging raw CV content in production; mask or hash identifiers.  
- Store only the **minimum necessary text** for evaluation; consider **encryption at rest**.  
- Rotate API tokens; enforce **least-privilege** (abilities) if needed.

### I. Performance & Cost

- Use `gemini-2.0-flash` for speed/cost balance.  
- Keep prompts tight: include only the relevant RAG context.  
- Cache embeddings for repeated JD/brief; avoid re-embedding unchanged content.  
- Use queues to smooth spikes; **scale workers horizontally**.

### J. Trade-offs & Rationale

- Simple vector store vs. pgvector: chosen for minimal setup; swap to **ANN (HNSW)** when corpus grows.  
- Gemini JSON enforcement: simplifies parsing; still guarded with a fixer call.  
- Two-pass project scoring: increases reliability on resilience criteria.  
- Queue-based async: realistic UX for LLM pipelines; avoids HTTP timeouts.  
- Schema-light extraction: faster to implement; future work could use strict JSON schema validators.

### K. Troubleshooting

- **DivisionByZeroError:** ensure rubric is seeded; the pipeline now guards against empty score arrays.  
- **Empty text from PDF/docx:** some files are image-only; integrate OCR or request `.txt` for testing.  
- **Inconsistent scores:** lower temperature; ensure JD/brief are clear; keep prompts concise.

### L. Testing Checklist

- **Unit:** vector store ranking with fixed fake embeddings.  
- **Unit:** clamp/normalize logic with empty scores.  
- **Feature:** upload → evaluate → poll success path.  

---

## License

MIT
