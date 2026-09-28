/**
 * Fixture for the D-7 sentence-grouping rule: the shape of the real
 * `Impressum.tsx:43` externals-link paragraph — a bare URL sitting between two
 * `<Trans>`-wrapped prose nodes, all children of one `<p>`.
 *
 * Used to pin that the bare URL is folded into ONE grouped run together with
 * its neighbouring prose instead of being reported standalone. It exists to
 * characterise the rule, not to bless the source: the finding is real, see
 * `AGENTS.todo.md`.
 */
export const LinkParagraph = () => (
    <p><Trans>Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit, die Sie unter</Trans> <a href="https://ec.europa.eu/consumers/odr/" target="_blank" rel="noopener noreferrer">https://ec.europa.eu/consumers/odr/</a> <Trans>finden. Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</Trans></p>
);
