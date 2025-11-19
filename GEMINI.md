# GEMINI.md

## Project Overview

This project is **UNetLab (Unified Networking Lab)**, a web-based application for creating and managing virtual network labs. It allows users to design, build, and run network topologies using a variety of virtual network devices.

The architecture is a combination of several technologies:

*   **Frontend:** The user interface is built with HTML, CSS (including Tailwind CSS), and JavaScript. The main UI templates are located in `html/themes/adminLTE/`. The primary CSS file is `html/themes/adminLTE/unl_data/css/main.css`, which is compiled to `main.output.css`.
*   **Backend:** The backend is a REST API written in PHP using the Slim framework. The main API file is `html/api.php`.
*   **Device Configuration:** A collection of Python scripts in `config_scripts/` are used to automate the configuration of network devices running in the labs.
*   **Hypervisor Integration:** The platform interacts with various hypervisors like QEMU, Docker, and IOL (IOS on Linux) through wrapper scripts located in the `wrappers/` directory.
*   **Automation:** Various shell scripts in the `scripts/` directory provide automation for tasks like API documentation generation, lab updates, and system information gathering.

## Building and Running

### CSS

To watch for changes in the CSS and automatically recompile, run:

```bash
npm run watch:css
```

This command processes the main CSS file at `html/themes/adminLTE/unl_data/css/main.css` and outputs the compiled CSS to `html/themes/adminLTE/unl_data/css/main.output.css`.

### PHP

To quickly check the syntax of PHP files, you can use the `php -l` command. For example, to check the main API file:

```bash
php -l html/api.php
```

### Python

To ensure all Python configuration scripts are syntactically correct, you can compile them:

```bash
python3 -m compileall config_scripts
```

### API Documentation

To regenerate the API documentation, run the following script:

```bash
bash scripts/doc_api.sh
```

## Development Conventions

*   **PHP:** Follows PSR-style docblocks. New helper functions should be registered in `html/includes`.
*   **Python:** Uses four-space indents, snake_case for functions, and uppercase for constants.
*   **Bash:** Scripts should start with `#!/bin/bash` and use `set -euo pipefail`.
*   **Frontend:** When working on the frontend, use Tailwind CSS classes and avoid introducing legacy Bootstrap/AdminLTE markup. New templates should only load `main.output.css`.
*   **Commits:** Commit messages should have a short, imperative subject line (under 50 characters) and a body that describes the motivation and impact of the changes.

## Testing

There is no formal automated test suite. Testing is performed by creating and verifying labs.

1.  Create a lab definition file (`.unl`) in a subdirectory of `labs/`.
2.  Document any required external images in a README file within the same directory.
3.  Import the lab through the UI or using the API.
4.  Verify that the nodes boot, the network interfaces are correctly attached, and the configuration scripts run successfully.
5.  When reporting bugs or submitting pull requests, include the output of `bash scripts/eve-info.sh` and sample REST API data.
