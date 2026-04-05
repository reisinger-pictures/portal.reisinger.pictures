import { defineConfig } from "repomix";

export const MJS_PATCH_INSTRUCTION = `
File Modification Rule (CRITICAL):
- Multi-line Regex for search-and-replace in code is STRICTLY FORBIDDEN. It is too brittle.
- When patching, use exact string replacement or rewrite the entire file.
- Patch-Scripts must be standalone .mjs files.
`.trim();

export default defineConfig({
    output: {
        style: "markdown",
        filePath: "repomix-portal.md",
        fileSummary: true,
        directoryStructure: true,
        headerText: MJS_PATCH_INSTRUCTION
    },
    ignore: {
        useGitignore: true,
        useDefaultPatterns: true,
        customPatterns: [
            "frontend/node_modules/**",
            "backend/vendor/**"
        ]
    }
});
