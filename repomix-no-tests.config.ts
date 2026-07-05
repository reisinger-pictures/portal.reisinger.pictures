import {MJS_PATCH_INSTRUCTION} from './repomix.config';
import {defineConfig} from "repomix";

export default defineConfig({
    output: {
        style: "markdown",
        filePath: "repomix-portal-no-tests.md",
        fileSummary: true,
        directoryStructure: true,
        headerText: MJS_PATCH_INSTRUCTION,
        removeComments: false,
        removeEmptyLines: false,
        topFilesLength: 10,
        showLineNumbers: true,
        copyToClipboard: false
    },
    include: [
        "*",
        ".run/**/*",
        "deployment/**/*",
        "features/**/*",
        "backend/app/**/*",
        "backend/bootstrap/**/*",
        "backend/config/**/*",
        "backend/database/**/*",
        "backend/lang/**/*",
        "backend/public/**/*",
        "backend/resources/**/*",
        "backend/routes/**/*",
        "backend/storage/**/*",
        "frontend/src/**/*",
        "frontend/public/**/*",
        "frontend/docs/**/*"
    ],
    ignore: {
        useGitignore: true,
        useDefaultPatterns: true,
        customPatterns: [
            "CLAUDE.md",
            "frontend/node_modules/**",
            "backend/vendor/**",
            "backend/tests/**",
            "frontend/tests/**",
            "frontend/test-results/**",
            "frontend/playwright-report/**",
            "**/*.test.*",
            "**/*.spec.*",
            "**/__tests__/**",
            "**/vitest.config.*",
            "**/playwright.config.*",
            "**/phpunit*",
            "**/ai_test_runner*",
            "**/ai_test_report*",
            "**/tsconfig.tests*",
            "**/.phpunit*"
        ]
    },
});
