/**
 * Fixture for the D-7 sentence-grouping rule. A JSX run that React splits into
 * three adjacent children — text, expression, text — with no intervening JSX
 * element. This mirrors the real `ContractSignView.tsx` shape closely enough to
 * be the regression guard: before D-7 the run produced several node-level
 * findings; now it is ONE logical unit.
 */
export const AgeLine = ({ age, birthDate }: { age: number; birthDate: string }) => (
  <span>
    {age} Jahre (geb. {birthDate})
  </span>
);
