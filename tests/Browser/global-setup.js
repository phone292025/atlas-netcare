import { copyFileSync } from 'node:fs';
import { artisan, compose, stack } from './stack.js';

/**
 * Starts a fresh stack for the run, the way the README sets one up.
 *
 * 1. Whatever an earlier run left behind goes, volume included.
 * 2. The image is built from the working tree, so the run tests this code.
 * 3. Demo data is seeded, which makes the default accounts, and an
 *    administrator of its own is created with atlas:create-admin, as the
 *    README tells an owner to.
 *
 * The accounts reach the tests through ATLAS_BROWSER_ACCOUNTS, which Playwright
 * hands to every worker. The demo accounts are read from the app's own config,
 * so they are the ones the seeder used, whatever .env.example says.
 */
export default function globalSetup() {
    compose('down', '--volumes', '--remove-orphans');
    copyFileSync('.env.example', stack.envFile);
    compose('up', '--detach', '--build', '--wait', '--wait-timeout', '300');

    artisan('db:seed', '--force');

    const created = artisan('atlas:create-admin', '--email=owner@atlas.local');
    const admin = {
        email: created.match(/Administrator created: (\S+)/)?.[1],
        password: created.match(/Password: (\S+)/)?.[1],
    };

    if (!admin.email || !admin.password) {
        throw new Error('atlas:create-admin printed no email or password. Its output has changed.');
    }

    const demo = JSON.parse(artisan('tinker', '--execute=echo json_encode(config("atlas.demo_users"));'));
    const defaultAdmin = JSON.parse(artisan('tinker', '--execute=echo json_encode(config("atlas.first_admin"));'));

    process.env.ATLAS_BROWSER_ACCOUNTS = JSON.stringify({
        admin,
        defaultAdmin: { ...defaultAdmin, password: demo.password },
        engineer: { ...demo.engineer, password: demo.password },
        assistant: { ...demo.assistant, password: demo.password },
    });
}
