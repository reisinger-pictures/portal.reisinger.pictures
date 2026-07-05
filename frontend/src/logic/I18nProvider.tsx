import { i18n } from "@lingui/core"
import { I18nProvider as LinguiI18nProvider } from "@lingui/react"
import { messages } from "../../locale/de/messages.po"

i18n.load("de", messages)
i18n.activate("de")

export function I18nProvider({ children }: { children: React.ReactNode }) {
  return <LinguiI18nProvider i18n={i18n}>{children}</LinguiI18nProvider>
}
