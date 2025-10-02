# Laravel AI Evaluation Service (CV & Project Scoring)

This project implements a backend service that accepts a candidate’s **CV** and **Project Report**, compares them with a **Job Vacancy** and **Study Case Brief**, and returns a structured evaluation with:

- **CV ↔ Job match rate** (0–1)
- **Project score** (1–10) based on a standardized rubric
- **Actionable feedback & summary**

It demonstrates **Laravel fundamentals + AI workflow** (prompt design, LLM chaining, RAG-like retrieval, resilience, async pipeline).

---

## Features

- **API**
  - `POST /api/auth/token` → issue token
  - `POST /api/upload` → upload CV & project report (txt/pdf/docx)
  - `POST /api/evaluate` → enqueue evaluation pipeline; returns `{ id, status: "queued" }`
  - `GET /api/result/{id}` → poll status: `queued | processing | completed | failed`
- **Async Processing** via Laravel Queues (database driver by default)
- **LLM Chaining** (OpenAI by default) with retry + backoff + validation
- **Simple Vector Store** for RAG
  - `documents` table stores content + embedding (JSON float array)
  - Cosine similarity in PHP (fine for small corpus)
- **Robust Text Extraction** from PDF (Smalot/pdfparser), DOCX (PhpOffice), TXT
- **Determinism Controls:** temperature, schema validation & correction
- **Failure Simulation:** randomly throw LLM errors to exercise retries
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
```
Install packages
```
composer install
```

### 3) Environment
Create .env entries:
```
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
```
php artisan key:generate
php artisan migrate
php artisan queue:table && php artisan migrate
```

### 4) Run
```
php artisan serve  # http://127.0.0.1:8000
php artisan queue:work --tries=3
```
To get a demo tenant and user, issue an API token, and (optionally) seed vector docs. Prints curl examples.
```
php artisan app:bootstrap-dem
```

### 5) Try the APIs
Upload files (CV + Project Report):
```
curl -F "cv=@/path/cv.pdf" -F "project_report=@/path/report.docx" \
  http://127.0.0.1:8000/api/upload
# => {"cv_file_id":"...","project_file_id":"..."}
```

Enqueue evaluation:
```
curl -X POST http://127.0.0.1:8000/api/evaluate \
  -H "Content-Type: application/json" \
  -d '{
    "cv_file_id": "<uuid>",
    "project_file_id": "<uuid>",
    "job_description": "We seek a Backend Engineer experienced in Laravel, REST APIs, PostgreSQL, Docker, cloud (GCP/AWS), and exposure to LLMs/RAG.",
    "study_case_brief": "Build a small service evaluating CV & project via LLM chaining, with retrieval and retries."
  }'
# => {"id":"<uuid>","status":"queued"}
```

Poll result:
```
curl http://127.0.0.1:8000/api/result/<uuid>
# => {"id":"<uuid>","status":"processing"}
# or completed => includes result payload
```

**API Contracts**