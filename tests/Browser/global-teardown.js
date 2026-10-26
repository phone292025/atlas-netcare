import { rmSync } from 'node:fs';
import { compose, stack } from './stack.js';

/** Removes the run's stack and its database. A failed test keeps its trace. */
export default function globalTeardown() {
    compose('down', '--volumes', '--remove-orphans');
    rmSync(stack.envFile, { force: true });
}
