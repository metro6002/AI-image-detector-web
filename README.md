# AI Image Detector Web

**A simple LAMP‑based web application that lets users upload an image and receive an AI‑generated vs. human‑made verdict.**

---

## Table of Contents
- [Overview](#overview)
- [Features](#features)
- [Directory Structure](#directory-structure)
- [Prerequisites](#prerequisites)
- [Installation & Quick Start (Docker)](#installation--quick-start-docker)
- [Environment Variables](#environment-variables)
- [Running the Application](#running-the-application)
- [Usage](#usage)
- [Database Schema](#database-schema)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Overview

`AI Image Detector Web` is a **LAMP** (Linux, Apache, MySQL, PHP) stack application that:
1. Accepts image uploads via a web UI.
2. Sends the image to an external AI inference service (e.g., Sightengine) to obtain a verdict.
3. Stores the scan result in a MySQL database and displays the latest scan on the same page.

The project ships with a **Dockerfile** and an **entry‑point script** (`start.sh`) that automatically start MySQL, apply the schema, and launch Apache.

---

## Features

- Simple upload form with drag‑and‑drop support.
- Verdict shown as a colored badge (AI‑Generated, Human‑Made, Notice).
- Self‑contained MySQL database created on first container start.
- Environment‑variable driven configuration (DB credentials, Sightengine API keys, mock‑fallback switch).
- Clean, responsive UI built with **Bootstrap 5** and **Bootstrap Icons**.
- No external dependencies required beyond Docker.

---

## Directory Structure

```
AI-image-detector-web/
│
├─ Dockerfile          # Builds the Ubuntu‑based LAMP image
├─ start.sh            # Entrypoint – starts MySQL, applies schema, runs Apache
├─ schema.sql          # Database schema (tables for scan logs)
├─ LICENSE             # Project license
├─ README.md           # *You are reading it now*
│
└─ src/                # Web‑app source
   ├─ index.php        # Main UI + verdict display logic
   ├─ upload.php       # Handles the actual file upload & API call
   └─ uploads/         # Runtime folder for uploaded images (mounted from host)
```

---

## Prerequisites

- **Docker Engine** (or Docker Desktop) installed on your machine.
- (Optional) `git` if you want to clone the repository.

---

## Installation & Quick Start (Docker)

1. **Clone / download the project** (skip if you already have the folder):
   ```bash
   git clone <repo‑url> AI-image-detector-web
   cd AI-image-detector-web
   ```
2. **Build the Docker image** (only needed when you change source code or the Dockerfile):
   ```bash
   docker build -t ai-image-detector-web .
   ```
3. **Run the container** (single command – the `start.sh` script runs automatically inside the image):
   ```bash
   docker run -d \
     --name ai-detector \
     -p 8080:80 \
     -e DB_USER=root \
     -e DB_PASS=secret_password \
     -e DB_NAME=aidb \
     -v "$(pwd)/src/uploads:/var/www/html/uploads" \
     ai-image-detector-web
   ```
   - `-p 8080:80` maps the web UI to `http://localhost:8080`.
   - The `-v` bind‑mount keeps uploaded images on the host for easy inspection.
4. Open your browser and navigate to **http://localhost:8080**.

---

## Environment Variables

| Variable | Description | Default (if not set) |
|----------|-------------|----------------------|
| `DB_HOST` | MySQL host; inside the container it is `localhost`. | `localhost` |
| `DB_PORT` | MySQL port. | `3306` |
| `DB_USER` | MySQL user (created by `start.sh`). | `root` |
| `DB_PASS` | Password for the MySQL user. **Important** – set a strong password in production. | `secret_password` |
| `DB_NAME` | Database name used by the application. | `aidb` |
| `SE_API_USER` | Sightengine API User ID. | *none* |
| `SE_API_SECRET` | Sightengine API Secret. | *none* |
| `SE_API_KEY` | Alternative Sightengine key (if your workflow uses it). | *none* |
| `SE_API_URL` | Custom endpoint for the inference provider. | *none* |
| `ENABLE_MOCK_FALLBACK` | When set to any non‑empty value the app will use a mock response if the real API is unreachable. | *unset* |

All variables prefixed with `SE_` are exported to Apache’s `envvars` file by `start.sh`, making them accessible from PHP via `getenv()`.

---

## Running the Application

The container runs **both** MySQL and Apache automatically:
- MySQL data lives inside the container (removed when the container is removed). Use the bind‑mount for `uploads/` if you need persistence.
- Apache serves the files from `/var/www/html/src/` (the `src/` directory of the project).

### Stopping / Restarting
```bash
# Stop the container
docker stop ai-detector
# Start it again (it will reuse the same MySQL data)
docker start ai-detector
```

### Removing the container completely (cleans the DB)
```bash
docker rm -f ai-detector
# Next run will re‑initialize the database from schema.sql
```

---

## Usage

1. **Upload an image** using the drag‑and‑drop box or the *Select Image* button.
2. Click **Analyze Image**.
3. The page refreshes and shows a colored badge:
   - **AI‑Generated** (orange badge with robot icon)
   - **Human‑Made** (green badge with person‑check icon)
   - **Notice** (yellow badge with info‑circle icon) – used for informational messages or errors.
4. Below the badge you’ll see a confidence bar, image metadata, and the raw JSON response from the inference provider.
5. The bottom of the page always lists the last 10 scans stored in MySQL.

---

## Database Schema

`schema.sql` creates a single table `scan_logs`:
```sql
CREATE TABLE IF NOT EXISTS scan_logs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    file_name        VARCHAR(255) NOT NULL,
    file_path        VARCHAR(255) NOT NULL,
    mime_type        VARCHAR(100) NOT NULL,
    file_size        BIGINT,
    verdict          VARCHAR(50),
    confidence_score FLOAT,
    raw_response     TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```
The table stores everything needed to display historical results.

---

## Troubleshooting

| Symptom | Likely Cause | Fix |
|---------|--------------|-----|
| **Cannot connect to MySQL** (error on page) | MySQL failed to start inside the container. | `docker logs ai-detector` – look for `MySQL service is ready.` If missing, restart container or remove it (`docker rm -f`) and run again. |
| **Blank page / 500 error** | PHP error (often missing env vars). | Ensure `SE_API_USER` and `SE_API_SECRET` are set or enable mock fallback (`ENABLE_MOCK_FALLBACK=1`). |
| **Uploads folder not writable** | Host bind‑mount permissions. | `chmod -R 775 src/uploads` on the host or delete the container and let `start.sh` recreate it. |
| **Old Docker image refuses to rebuild** | Cached layers. | Run `docker build --no-cache -t ai-image-detector-web .` |
| **Container keeps exiting** | `docker run` without `-d` may show the process exiting after an error. | Check `docker logs <container>` for details; usually an env‑var typo. |

---

## License

This project is licensed under the **MIT License** – see the `LICENSE` file for details.

---

*Happy hacking! If you encounter any issues, feel free to open an issue or submit a pull request.*