import assert from "node:assert/strict";
import { mkdtemp, realpath, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "vite-plus";

// Run the existing production Vite pipeline without loading any project .env file.
// Laravel's Vite plugin also uses envDir, so both loaders see this empty directory.
const root = fileURLToPath(new URL("../", import.meta.url));
const temporaryRoot = await realpath(os.tmpdir());
const directory = await mkdtemp(path.join(temporaryRoot, "rlcp-public-build-"));
try {
    await build({ root, configFile: path.join(root, "vite.config.js"), envDir: directory });
} finally {
    const target = await realpath(directory);
    assert.equal(path.dirname(target), temporaryRoot);
    assert.ok(path.basename(target).startsWith("rlcp-public-build-"));
    await rm(target, { recursive: true, force: true });
}
