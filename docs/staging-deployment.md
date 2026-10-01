# Staging Deployment

Merging to `main` deploys to staging through `.github/workflows/ci.yml`. The deployment job runs only after the `style`, `analyse`, and `tests` CI jobs pass, uses the GitHub `staging` environment for credentials, allows only one staging deployment at a time, and fails if the deployed `/health` endpoint does not return HTTP 200.

## Target Choice

Use a small Ubuntu VPS for staging instead of a managed PHP platform. The VPS keeps the deployment sequence explicit and close to production operations: SSH into one host, reset the working tree to the merge commit, run Composer without dev dependencies, migrate, cache config, restart queues, and verify the app over HTTP. The trade-off is that the team owns OS patching, PHP/web server setup, queue supervisor config, backups, and SSH hardening; a managed platform would reduce that operational work but hide more of the deployment mechanics this workflow is meant to prove.

## GitHub Environment

Create a GitHub environment named `staging` and store these values there:

- Environment secrets:
  - `STAGING_HOST`: staging server hostname or IP.
  - `STAGING_USER`: unprivileged deploy user.
  - `STAGING_SSH_KEY`: private key for a deploy-only SSH key.
- Environment variables:
  - `STAGING_PATH`: absolute path to the checked-out app on the server.
  - `STAGING_HEALTH_URL`: full URL to the health endpoint, for example `https://staging.example.com/health`.

The deploy key should be authorized only for the deploy user on the staging host. Do not give it broad account access or commit it to the repository.

## Server Expectations

The staging host should already have PHP, Composer, the web server, queue worker supervisor, and application `.env` configured. The deploy user needs permission to run these commands inside `STAGING_PATH`:

```bash
git fetch --prune origin main
git checkout main
git reset --hard "$DEPLOY_SHA"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
```

## PR Evidence Checklist

Include this in the PR once the first deployment has run:

- Staging URL:
- Green GitHub Actions run:
- Before response from `STAGING_HEALTH_URL`:
- After response from `STAGING_HEALTH_URL`:
