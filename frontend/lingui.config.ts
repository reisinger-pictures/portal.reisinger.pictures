import type { LinguiConfig } from "@lingui/conf"
import { formatter } from "@lingui/format-po"

const config: LinguiConfig = {
  locales: ["de"],
  sourceLocale: "de",
  catalogs: [
    {
      path: "<rootDir>/locale/{locale}/messages",
      include: ["src"],
    },
  ],
  format: formatter({lineNumbers: false}),
}

export default config
