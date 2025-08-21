"use client";
import { InitiativeCard } from "@/components/plan/InitiativeCard";
import { useAppStore } from "@/lib/store";

export default function InitiativesPage() {
  const initiatives = useAppStore((s) => s.initiatives);
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
      {initiatives.map((it) => (<InitiativeCard key={it.id} initiativeId={it.id} />))}
    </div>
  );
}