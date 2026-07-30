import { defineConfig } from "vitest/config";
import { config } from "dotenv";

config({ path: ".env.test" });
config({ path: ".env.local" });

export default defineConfig({
  test: {
    include: ["tests/**/*.test.ts"],
    testTimeout: 180_000,
    hookTimeout: 180_000,
    // The two suites share tenant fixtures against a live database; run them
    // one after the other.
    fileParallelism: false,
  },
});
