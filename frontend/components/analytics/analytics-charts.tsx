"use client";

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { statusColor, type AnalyticsReport } from "@/lib/analytics";
import { instanceStatusLabel } from "@/lib/execution";

function Tip({
  active,
  payload,
  label,
}: {
  active?: boolean;
  payload?: Array<{ name?: string; value?: number; color?: string }>;
  label?: string;
}) {
  if (!active || !payload?.length) {
    return null;
  }

  return (
    <div className="rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-(--shadow-overlay)">
      {label ? <p className="mb-1 font-medium">{label}</p> : null}
      {payload.map((item) => (
        <p key={`${item.name}-${item.value}`} className="flex items-center gap-2 tabular-nums">
          <span className="size-2 rounded-full" style={{ background: item.color ?? "var(--chart-1)" }} />
          <span>{item.name}</span>
          <span className="font-medium">{item.value}</span>
        </p>
      ))}
    </div>
  );
}

const axis = { fill: "var(--muted-foreground)", fontSize: 12 };

export function VolumeChart({ data }: { data: AnalyticsReport["volume"] }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <AreaChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid stroke="var(--border)" vertical={false} />
        <XAxis dataKey="date" tick={axis} tickLine={false} axisLine={false} minTickGap={24} />
        <YAxis allowDecimals={false} tick={axis} tickLine={false} axisLine={false} width={32} />
        <Tooltip content={<Tip />} />
        <Area
          type="monotone"
          dataKey="count"
          name="Started"
          stroke="var(--chart-1)"
          fill="var(--chart-1)"
          fillOpacity={0.16}
          strokeWidth={2}
          dot={false}
          isAnimationActive={false}
        />
      </AreaChart>
    </ResponsiveContainer>
  );
}

export function StatusChart({ data }: { data: AnalyticsReport["status_distribution"] }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <PieChart>
        <Pie data={data} dataKey="count" nameKey="status" innerRadius="58%" outerRadius="82%" paddingAngle={2} isAnimationActive={false}>
          {data.map((item) => (
            <Cell key={item.status} fill={statusColor(item.status)} name={instanceStatusLabel(item.status)} />
          ))}
        </Pie>
        <Tooltip content={<Tip />} />
      </PieChart>
    </ResponsiveContainer>
  );
}

export function DecisionChart({ approved, rejected }: { approved: number; rejected: number }) {
  const data = [
    { name: "Approved", value: approved, fill: "var(--chart-3)" },
    { name: "Rejected", value: rejected, fill: "var(--chart-5)" },
  ];

  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={data} layout="vertical" margin={{ top: 8, right: 16, left: 8, bottom: 0 }}>
        <CartesianGrid stroke="var(--border)" horizontal={false} />
        <XAxis type="number" allowDecimals={false} tick={axis} tickLine={false} axisLine={false} />
        <YAxis type="category" dataKey="name" tick={axis} tickLine={false} axisLine={false} width={72} />
        <Tooltip content={<Tip />} />
        <Bar dataKey="value" name="Decisions" radius={6} isAnimationActive={false}>
          {data.map((item) => (
            <Cell key={item.name} fill={item.fill} />
          ))}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}
