import { execFileSync } from 'node:child_process';

/**
 * The Docker stack the browser tests run against.
 *
 * A Compose project of its own, with its own image tag, containers, database
 * volume and ports, so a run never touches the stack somebody works in. The
 * tests raise tickets and submit inspections, and a developer's .env may send
 * real mail, so this stack gets a fresh copy of .env.example instead
 * (ADR-0015).
 */
export const stack = {
    project: 'atlas-browser',
    imageTag: 'browser-tests',
    port: 8100,
    dbPort: 5433,
    envFile: 'tests/Browser/.stack.env',
};

stack.url = `http://127.0.0.1:${stack.port}`;

/**
 * Runs `docker compose` against this stack and returns what it printed.
 *
 * Compose writes its progress to stderr, which passes through, so a slow image
 * build shows what it is doing. stdout comes back to the caller instead,
 * because it can hold the administrator's one-time password.
 */
export function compose(...args) {
    return execFileSync('docker', ['compose', ...args], {
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'inherit'],
        env: {
            ...process.env,
            COMPOSE_PROJECT_NAME: stack.project,
            ATLAS_IMAGE_TAG: stack.imageTag,
            ATLAS_PORT: String(stack.port),
            ATLAS_DB_PORT: String(stack.dbPort),
            ATLAS_ENV_FILE: `./${stack.envFile}`,
        },
    });
}

/** An artisan command inside the stack's app container. */
export function artisan(...args) {
    return compose('exec', '-T', 'app', 'php', 'artisan', ...args);
}
