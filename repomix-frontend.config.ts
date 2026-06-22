import {MJS_PATCH_INSTRUCTION} from './repomix.config';
import {defineConfig} from "repomix";

export default defineConfig({
    output: {
        style: "markdown",
        filePath: "repomix-portal-frontend.md",
        fileSummary: true,
        directoryStructure: true,
        headerText: MJS_PATCH_INSTRUCTION
    },
    include: [
        "*",
        ".run/**/*",
        "deployment/**/*",
        "frontend/**/*",
        "backend/**/*"
    ],
    ignore: {
        useGitignore: true,
        useDefaultPatterns: true,
        customPatterns: [
            "CLAUDE.md",
            "frontend/node_modules/**",
            "backend/vendor/**",
            "admin.lrplugin/**"
        ]
    }
});
