export async function smartifyInitiative(input: { title: string; description: string }) {
  const t = input.title?.trim() || "Untitled";
  const d = input.description?.trim() || "";
  return {
    title: `${t} — achieve measurable outcome (e.g., +20% by Dec 31, 2025)`,
    description: d || `Deliver ${t} with a clear KPI and a deadline (SMART).`,
    smart: { specific: true, measurable: true, achievable: true, relevant: true, timeBound: true }
  };
}

export async function journalSummarize(prompt: string, _notes: string[]) {
  return `Journal Summary — ${prompt}\n\nThe strongest Initiatives have two-sentence titles with explicit outcomes and dates.`;
}