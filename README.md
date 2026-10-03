# BelgranoWear (content repository)

This repository hosts the static information provisioning backend for the **BelgranoWear** application. The frontend can be found at [belgranowear/BelgranoWear](https://github.com/belgranowear/BelgranoWear).

**BelgranoWear** is an application that lets you travel through the **Belgrano Norte** network (maintained by **Ferrovias**).

## System requirements
- A GNU/Linux-based operating system
- Docker
- An internet connection

## Usage

- Clone this repository to your local machine:

    `git clone https://github.com/belgranowear/belgranowear.github.io`

- Navigate to the cloned directory:

    `cd belgranowear.github.io`

- Regenerate the files:

    `docker compose up --build`

## Configuration

The updater reads its settings from the `.env` file at the repository root.

### Request throttling and anti-bot backoff

Ferrovias' website answers with a JavaScript browser challenge (HTTP 403, "Checking your browser...") when it receives too many requests in a short time. The schedule updater spaces its requests and backs off when that happens:

| Variable | Default | Description |
| --- | --- | --- |
| `SCHEDULE_REQUEST_DELAY_MS` | `1500` | Minimum wait, in milliseconds, between the end of one request to Ferrovias and the start of the next one. |
| `SCHEDULE_REQUEST_JITTER_MS` | `500` | Random extra wait, from `0` up to this many milliseconds, added to every delay. |
| `SCHEDULE_BLOCK_BACKOFF_SECONDS` | `90` | Base wait after detecting the browser challenge. Retry *n* waits `n × SCHEDULE_BLOCK_BACKOFF_SECONDS`. |
| `SCHEDULE_BLOCK_MAX_RETRIES` | `2` | Retries for a challenged request before the whole run is aborted. |
| `SCHEDULE_USER_AGENT` | `belgranowear-updater/1.0 (+https://github.com/belgranowear/belgranowear.github.io)` | `User-Agent` header sent to Ferrovias. Optional; not set in `.env`. |

All values are optional; invalid or missing numbers fall back to the defaults and negative numbers are treated as `0`.

A run is aborted early, leaving the previously published files in `docs` untouched, when the challenge persists after every retry or when 5 consecutive queries fail for any reason. With the defaults, a full schedule update makes about 470 requests and takes around 20 minutes.

### Debugging

| Variable | Default | Description |
| --- | --- | --- |
| `DEBUG_SCHEDULE_QUERIES` | `false` | Log every schedule query, its parsed rows and skipped implausible rows. |
| `DEBUG_CHECKSUMS` | `false` | Log every checksum file written instead of a summary. |

### Scheduled rebuilds

The `rebuild` GitHub Actions workflow runs daily at 00:00 UTC. If that run fails, for example because Ferrovias blocked the runner, it is retried at 06:00 and 12:00 UTC; the retries are skipped when the workflow has already succeeded that day.

## Deployment

To deploy the site, push the contents of the `docs` directory to the `gh-pages` branch:

    git subtree push --prefix docs origin gh-pages

## License

**BelgranoWear** is open-sourced software licensed under the [MIT License](LICENSE).

## Contributing

Contributions to improve **BelgranoWear** are welcome. To contribute, please follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or bugfix.
3. Commit your changes with clear and descriptive messages.
4. Push your changes to your fork.
5. Open a pull request with a detailed description of your changes.