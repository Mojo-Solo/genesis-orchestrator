"use client";
import { useAppStore } from "@/lib/store";
import { Badge } from "@/components/ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Input } from "@/components/ui/input";

const weeks = Array.from({ length: 13 }, (_, i) => i + 1);
const quarters = ["Q1","Q2","Q3","Q4"] as const;
const statusColor: Record<string, string> = {
  "On Target": "bg-status-on",
  "At Risk": "bg-status-risk",
  "Off Track": "bg-status-off",
  "Not Started": "bg-status-not",
  "Done": "bg-emerald-600",
  "Blocked": "bg-rose-600",
};

export function ProjectStatusTable() {
  const projects = useAppStore((s) => s.projects);
  const update = useAppStore((s) => s.updateProjectWeek);

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr>
            <th className="w-20 text-left">Week</th>
            {quarters.map((q) => (
              <th key={q} className="min-w-[260px] text-left">
                <div className="bg-brand-brown text-white rounded-t px-3 py-2">{q}</div>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {weeks.map((w) => (
            <tr key={w} className="border-b">
              <td className="py-2">WK{w}</td>
              {quarters.map((q) => {
                const cell = projects[q][w - 1];
                return (
                  <td key={q+"-"+w} className="py-2 pr-4">
                    <div className="flex items-center gap-2">
                      <Input type="number" min={0} max={100} value={cell.percent}
                        onChange={(e)=>update(q,w-1,{ percent: Number(e.target.value) })}
                        className="w-16" />
                      <Select value={cell.status} onValueChange={(v)=>update(q,w-1,{ status: v as any })}>
                        <SelectTrigger className="w-32"><SelectValue /></SelectTrigger>
                        <SelectContent>
                          {Object.keys(statusColor).map((s)=>(<SelectItem key={s} value={s}>{s}</SelectItem>))}
                        </SelectContent>
                      </Select>
                      <Badge className={`${statusColor[cell.status]} text-white`}>{cell.status}</Badge>
                    </div>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>

      <div className="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        <section>
          <div className="bg-brand-brown text-white px-3 py-2 rounded-t">PROJECT ISSUES</div>
          <textarea className="w-full min-h-[120px] border p-3 rounded-b" placeholder="Add+" />
        </section>
        <section>
          <div className="bg-brand-brown text-white px-3 py-2 rounded-t">NEXT ACTIONS</div>
          <textarea className="w-full min-h-[120px] border p-3 rounded-b" placeholder="Add+" />
        </section>
      </div>
    </div>
  );
}