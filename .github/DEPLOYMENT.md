# CMS deployment (GitHub Actions)

This repo deploys the Winter CMS app to a server on push to `dev` or `main`. The same server can host the Next.js frontend at a different path.

## Required configuration

Configure these in the **gymdues-cms** GitHub repo: **Settings → Secrets and variables → Actions**.

### Secrets

| Name | Description |
|------|-------------|
| `DEPLOY_SSH_KEY` | Private SSH key used to connect to the server (same key as Next.js if same server). |

### Variables

| Name | Description |
|------|-------------|
| `DEPLOY_HOST` | Server hostname or IP (e.g. droplet). |
| `DEPLOY_USER` | SSH user (e.g. `root` or `deploy`). |
| `DEPLOY_PATH` | Path on the server where the CMS is deployed (e.g. `/var/www/cms.gymdues.com`). Must be different from the Next.js app path. |

## Server setup (one-time)

1. Ensure the server has PHP 8.1+ and required extensions for Winter CMS.
2. Add the **public** key that matches `DEPLOY_SSH_KEY` to `~/.ssh/authorized_keys` for `DEPLOY_USER`.
3. Create the deployment directory and ensure `DEPLOY_USER` can write to it:
   ```bash
   sudo mkdir -p $DEPLOY_PATH
   sudo chown $DEPLOY_USER:$DEPLOY_USER $DEPLOY_PATH
   ```
4. Put a `.env` file in `DEPLOY_PATH` on the server (database, `APP_KEY`, etc.). It is never overwritten by the workflow.
5. Run the first deploy (or push to `dev`/`main`). After that, `storage/` and `bootstrap/cache/` will exist and remain across deploys.

## What the workflow does

- **Package:** Checkout, run `composer install --no-dev`, build a tarball (excluding `.env`, `.git`, `storage` runtime files, `bootstrap/cache`).
- **Deploy:** Upload tarball via SCP, extract to a temp dir, rsync into `DEPLOY_PATH` (excluding `.env` and `storage`), ensure `storage/` and `bootstrap/cache/` exist and are writable, run `php artisan winter:up` (or `migrate`), then config/route/view cache. Server `.env` and `storage/` are preserved.

## Reproducible builds

The repo does not commit `composer.lock`. For reproducible installs, consider adding `composer.lock` to the repo and keeping it updated.

## Manual deploy

In the repo: **Actions → Build and Deploy CMS → Run workflow**.
