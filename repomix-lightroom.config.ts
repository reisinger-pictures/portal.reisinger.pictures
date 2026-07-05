import {MJS_PATCH_INSTRUCTION} from './repomix.config';
import {defineConfig} from "repomix";

export default defineConfig({
    output: {
        style: "markdown",
        filePath: "repomix-portal-lightroom.md",
        fileSummary: true,
        directoryStructure: true,
        headerText: MJS_PATCH_INSTRUCTION
    },
    include: [
        "*",
        ".run/**/*",
        "deployment/**/*",
        "admin.lrplugin/**/*",
        "backend/**/*"
    ],
    ignore: {
        useGitignore: true,
        useDefaultPatterns: true,
        customPatterns: [
            "CLAUDE.md",
            "backend/vendor/**",
            "frontend/**"
        ]
    },
});
