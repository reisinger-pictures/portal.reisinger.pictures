import {defineConfig} from "repomix";

export const MJS_PATCH_INSTRUCTION = `
File Modification Rule (CRITICAL):
- Multi-line Regex for search-and-replace in code is STRICTLY FORBIDDEN. It is too brittle.
- When patching, use exact string replacement or rewrite the entire file.
- YOU MUST OUTPUT A STANDALONE \`patch.mjs\` SCRIPT containing the Node.js code to apply these changes (using the \`fs\` module).
- Do not just output the raw code blocks. Act strictly as a script-generator for patches.
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
            "CLAUDE.md",
            "frontend/node_modules/**",
            "backend/vendor/**"
        ]
    }
});
