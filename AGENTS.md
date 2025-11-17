# Repository Guidelines

## Project Structure & Module Organization
`html/` contains the Slim-based REST API, UI templates, and assets served by Apache; keep new PHP helpers there. Vendor onboarding scripts and import/export logic belong in `config_scripts/`. Hypervisor wrappers (QEMU, IOL, Docker) live in `wrappers/`, while shared binaries and init tasks sit under `platform/` and `hypervisor/`. Automation utilities live in `scripts/`. Sample labs and working directories stay in `labs/`; treat them as disposable fixtures unless you are curating `.unl` templates.

## Build, Test, and Development Commands
- `php -l html/api.php` — quick syntax check for REST routers (swap any PHP file path as needed).
- `python3 -m compileall config_scripts` — ensures every auto-configuration helper still imports under Python3.
- `bash scripts/doc_api.sh` — regenerates the API how-to page whenever endpoints or authentication change.
- `php scripts/update_labs.php labs/example.unl` — round-trips a lab definition to catch schema drift after manual edits.
- `bash scripts/eve-info.sh` — collects OS, hypervisor, and bridge data; attach the output to bug reports and PRs.

## Coding Style & Naming Conventions
PHP follows the vim modeline already committed (tabs, no expandtab) and PSR-style docblocks; register new helpers under `html/includes` and mirror existing naming such as `api_nodes.php`. Python in `config_scripts/` uses four-space indents, snake_case functions, and uppercase constants for credentials and timeouts. Bash utilities declare `/bin/bash`, prefer `set -euo pipefail`, and log long-running operations to `/opt/unetlab/data/Logs/`. Keep filenames descriptive (`config_vendorfeature.py`, `wrapper_qemu`), and align new REST routes with the current `/api/<resource>` convention.

## Testing Guidelines
No dedicated test harness exists, so rely on reproducible labs. Create verification labs in `labs/<feature>/<case>.unl`, clear stale `.unl.lock` files, and document external images in a README. Lint PHP and Python as described above, then import the lab through the UI or `curl` against `html/api.php` to confirm nodes boot, wrappers attach NICs, and config scripts reach their prompts. Capture `bash scripts/eve-info.sh` output and sample REST data such as `curl -s http://127.0.0.1/api/status | jq '.'` when reporting results.

## Commit & Pull Request Guidelines
History favors short imperative subjects (e.g., `fix vios login prompt`), so keep titles under ~50 characters and describe motivation plus impact in the body. Reference touched files explicitly (`html/api.php`, `config_scripts/config_vios.py`) and list the validation commands you ran. Pull requests must link to an issue or lab ID, outline any environment setup (images, firmware, topology), and include screenshots or REST payloads when UI or API behavior changes. Tag reviewers when cross-team coordination is required.
