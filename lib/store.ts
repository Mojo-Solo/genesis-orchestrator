"use client";
import { create } from "zustand";
import { persist } from "zustand/middleware";
import type { AppState, Initiative, ProjectWeek, Quarter } from "@/lib/types";
import { seedState } from "@/lib/mock";

export const useAppStore = create<AppState>()(
  persist(
    (set, get) => ({
      ...seedState,
      setVision: (k, v) => set((s) => ({ vision: { ...s.vision, sections: { ...s.vision.sections, [k]: v } } })),
      updateInitiative: (id, patch) => set((s) => ({
        initiatives: s.initiatives.map((i) => (i.id === id ? { ...i, ...patch } : i)),
      })),
      addProject: ({ year, quarter, initiativeId, owner, title }) => {
        const weeks = Array.from({ length: 13 }, (_, i) => ({ week: i+1, percent: 0, status: "Not Started" as const }));
        set((s) => ({ projects: { ...s.projects, [quarter]: weeks } }));
      },
      updateProjectWeek: (q: Quarter, index: number, patch: Partial<ProjectWeek>) => set((s) => ({
        projects: { ...s.projects, [q]: s.projects[q].map((w, i) => (i === index ? { ...w, ...patch } : w)) },
      })),
      resetDemo: () => set(() => ({ ...seedState })),
    }),
    { name: "cothinkr-demo" }
  )
);